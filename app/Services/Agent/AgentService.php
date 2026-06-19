<?php

namespace App\Services\Agent;

use App\Models\Setting;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps LLM API with tool use loop.
 * Supports Anthropic (with prompt caching) and OpenAI-compatible APIs
 * (DeepSeek, Groq, Together AI, OpenAI, etc.).
 */
class AgentService
{
    // Anthropic supports deeper chains reliably; openai_compatible capped at 3
    private const MAX_TOOL_ITERATIONS_ANTHROPIC = 6;
    private const MAX_TOOL_ITERATIONS_OPENAI = 3;

    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_API_VERSION = '2023-06-01';

    public function __construct(
        private ToolRegistry $tools,
        private CostTracker $costTracker,
    ) {}

    /**
     * @param array<int, array{role:string, content:mixed}> $history
     * @return array{text:string, messages:array, usage:array}
     */
    public function run(string $userMessage, array $history, AgentContext $context): array
    {
        $provider = Setting::get('ai_provider', 'anthropic');

        return match ($provider) {
            'openai_compatible' => $this->runOpenAiCompatible($userMessage, $history, $context),
            default => $this->runAnthropic($userMessage, $history, $context),
        };
    }

    // ── Anthropic ────────────────────────────────────────────────────────────

    private function runAnthropic(string $userMessage, array $history, AgentContext $context): array
    {
        $apiKey = Setting::get('anthropic_api_key', '');
        if (!$apiKey) {
            throw new \RuntimeException('Anthropic API key no configurada.');
        }

        if (!$this->costTracker->withinDailyLimit()) {
            throw new \RuntimeException('Límite diario de costo IA alcanzado.');
        }

        $model     = Setting::get('ai_model', 'claude-haiku-4-5-20251001');
        $maxTokens = (int) Setting::get('ai_max_tokens_response', '1024');
        $sysPrompt = $context->systemPrompt ?? Setting::get('ai_system_prompt', '');

        $messages    = $history;
        $messages[]  = ['role' => 'user', 'content' => $userMessage];
        $toolSchemas = $this->tools->anthropicSchemas();
        $systemBlocks = [
            // Bloque estático cacheado (prompt grande). El breakpoint de cache va aquí.
            ['type' => 'text', 'text' => $sysPrompt, 'cache_control' => ['type' => 'ephemeral']],
            // Fecha/hora actual: dinámica (cambia cada minuto), va DESPUÉS del breakpoint
            // para no invalidar la cache del bloque estático.
            ['type' => 'text', 'text' => BusinessTime::promptContext()],
        ];

        $finalText  = '';
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];

        for ($iter = 0; $iter < self::MAX_TOOL_ITERATIONS_ANTHROPIC; $iter++) {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::ANTHROPIC_API_VERSION,
                'content-type'      => 'application/json',
            ])->timeout(60)->post(self::ANTHROPIC_API_URL, [
                'model'     => $model,
                'max_tokens'=> $maxTokens,
                'system'    => $systemBlocks,
                'tools'     => $toolSchemas,
                'messages'  => $messages,
            ]);

            if ($response->failed()) {
                Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Error en API IA: ' . $response->status());
            }

            $data    = $response->json();
            $usage   = $data['usage'] ?? [];
            $content = $data['content'] ?? [];
            $stopReason = $data['stop_reason'] ?? null;

            $totalUsage['input_tokens']               += $usage['input_tokens'] ?? 0;
            $totalUsage['output_tokens']              += $usage['output_tokens'] ?? 0;
            $totalUsage['cache_creation_input_tokens']+= $usage['cache_creation_input_tokens'] ?? 0;
            $totalUsage['cache_read_input_tokens']    += $usage['cache_read_input_tokens'] ?? 0;

            $this->costTracker->record(
                userId: $context->user?->id,
                chatId: $context->chatId,
                model: $model,
                channel: $context->channel,
                action: 'agent.text',
                tokensIn: $usage['input_tokens'] ?? 0,
                tokensOut: $usage['output_tokens'] ?? 0,
                cacheRead: $usage['cache_read_input_tokens'] ?? 0,
                cacheWrite: $usage['cache_creation_input_tokens'] ?? 0,
                summary: 'iter ' . $iter,
            );

            if ($stopReason !== 'tool_use') {
                $finalText = $this->extractAnthropicText($content);
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];

            $toolResults = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $tool = $this->tools->get($block['name']);
                $toolUseId = $block['id'];

                if (!$tool) {
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolUseId,
                        'content' => json_encode(['error' => "Tool '{$block['name']}' no encontrada."]), 'is_error' => true];
                    continue;
                }
                try {
                    $result = $tool->execute($block['input'] ?? [], $context);
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolUseId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                } catch (\Throwable $e) {
                    Log::warning('Tool execution failed', ['tool' => $block['name'], 'error' => $e->getMessage()]);
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolUseId,
                        'content' => json_encode(['error' => $e->getMessage()]), 'is_error' => true];
                }
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return ['text' => $finalText, 'messages' => $messages, 'usage' => $totalUsage];
    }

    private function extractAnthropicText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            }
        }
        return trim(implode("\n", $parts));
    }

    // ── OpenAI-compatible (DeepSeek, Groq, OpenAI, Together AI…) ────────────

    private function runOpenAiCompatible(string $userMessage, array $history, AgentContext $context): array
    {
        $apiKey = Setting::get('openai_api_key', '');
        if (!$apiKey) {
            throw new \RuntimeException('API key no configurada (openai_api_key en ajustes IA).');
        }

        if (!$this->costTracker->withinDailyLimit()) {
            throw new \RuntimeException('Límite diario de costo IA alcanzado.');
        }

        $model     = Setting::get('ai_model', 'deepseek-chat');
        $maxTokens = (int) Setting::get('ai_max_tokens_response', '1024');
        $sysPrompt = $context->systemPrompt ?? Setting::get('ai_system_prompt', '');
        $rawBase   = trim((string) Setting::get('ai_api_base_url', ''));
        $baseUrl   = rtrim($rawBase !== '' ? $rawBase : 'https://api.openai.com/v1', '/');
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new \RuntimeException("ai_api_base_url inválida: \"{$baseUrl}\". Debe empezar con http(s)://");
        }

        $messages = [];
        if ($sysPrompt) {
            $messages[] = ['role' => 'system', 'content' => $sysPrompt];
        }
        // Fecha/hora actual para resolver fechas relativas ("mañana", "hoy").
        $messages[] = ['role' => 'system', 'content' => BusinessTime::promptContext()];
        foreach ($history as $msg) {
            $converted = $this->convertHistoryToOpenAi($msg);
            if ($converted !== null) {
                $messages[] = $converted;
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $toolSchemas = $this->tools->openaiSchemas();

        $finalText  = '';
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];

        $headers = ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'];

        for ($iter = 0; $iter < self::MAX_TOOL_ITERATIONS_OPENAI; $iter++) {
            $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages];

            if (!empty($toolSchemas)) {
                $payload['tools']               = $toolSchemas;
                $payload['tool_choice']         = 'auto';
                $payload['parallel_tool_calls'] = false; // prevent multi-tool confusion
            }

            $response = Http::withHeaders($headers)->timeout(60)
                ->post($baseUrl . '/chat/completions', $payload);

            // Graceful fallback: if model fails to generate tool call JSON, retry text-only
            if ($response->failed() && $response->status() === 400) {
                $errMsg = $response->json('error.message') ?? '';
                if (str_contains($errMsg, 'failed_generation') && isset($payload['tools'])) {
                    Log::warning('Groq failed_generation, retrying without tools', [
                        'chat_id' => $context->chatId,
                        'iter'    => $iter,
                    ]);
                    unset($payload['tools'], $payload['tool_choice'], $payload['parallel_tool_calls']);
                    $response = Http::withHeaders($headers)->timeout(60)
                        ->post($baseUrl . '/chat/completions', $payload);
                }
            }

            if ($response->failed()) {
                $errMsg = $response->json('error.message') ?? $response->body();
                Log::error('OpenAI-compatible API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Error en API IA (' . $response->status() . '): ' . $errMsg);
            }

            $data             = $response->json();
            $usage            = $data['usage'] ?? [];
            $promptTokens     = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;

            $totalUsage['input_tokens']  += $promptTokens;
            $totalUsage['output_tokens'] += $completionTokens;

            $this->costTracker->record(
                userId: $context->user?->id,
                chatId: $context->chatId,
                model: $model,
                channel: $context->channel,
                action: 'agent.text',
                tokensIn: $promptTokens,
                tokensOut: $completionTokens,
                summary: 'iter ' . $iter,
            );

            $choice = $data['choices'][0] ?? null;
            if (!$choice) {
                break;
            }

            $finishReason     = $choice['finish_reason'] ?? null;
            $assistantMessage = $choice['message'] ?? [];

            if ($finishReason !== 'tool_calls' || empty($assistantMessage['tool_calls'])) {
                $finalText = $assistantMessage['content'] ?? '';
                break;
            }

            $messages[] = [
                'role'       => 'assistant',
                'content'    => $assistantMessage['content'] ?? null,
                'tool_calls' => $assistantMessage['tool_calls'],
            ];

            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $toolName   = $toolCall['function']['name'] ?? '';
                $rawArgs    = $toolCall['function']['arguments'] ?? '{}';
                $toolCallId = $toolCall['id'] ?? '';

                // Validar JSON estricto: si el modelo emite args inválidos, devolver
                // error al LLM en vez de ejecutar la tool con {} silenciosamente.
                $toolInput = json_decode($rawArgs, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($toolInput)) {
                    Log::warning('Invalid JSON tool args from LLM', [
                        'tool'  => $toolName,
                        'error' => json_last_error_msg(),
                        'raw'   => is_string($rawArgs) ? mb_substr($rawArgs, 0, 200) : null,
                    ]);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId,
                        'content' => json_encode([
                            'error' => "Invalid JSON arguments: " . json_last_error_msg(),
                        ])];
                    continue;
                }

                $tool = $this->tools->get($toolName);

                if (!$tool) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId,
                        'content' => json_encode(['error' => "Tool '{$toolName}' no encontrada."])];
                    continue;
                }
                try {
                    $result     = $tool->execute($toolInput, $context);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                } catch (\Throwable $e) {
                    Log::warning('Tool execution failed', ['tool' => $toolName, 'error' => $e->getMessage()]);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId,
                        'content' => json_encode(['error' => $e->getMessage()])];
                }
            }
        }

        // Persist full message chain (including tool_calls + tool responses) so the next turn
        // has memory of what was searched/found. Drop only the system message — that's re-injected.
        $persistedHistory = array_values(array_filter($messages, fn (array $m): bool =>
            ($m['role'] ?? '') !== 'system'
        ));

        return ['text' => (string) $finalText, 'messages' => $persistedHistory, 'usage' => $totalUsage];
    }

    private function convertHistoryToOpenAi(array $msg): ?array
    {
        $role = $msg['role'] ?? '';

        // Pass through native OpenAI-format messages saved from prior turns
        if ($role === 'tool' && isset($msg['tool_call_id'])) {
            return [
                'role'         => 'tool',
                'tool_call_id' => $msg['tool_call_id'],
                'content'      => $msg['content'] ?? '',
            ];
        }

        if ($role === 'assistant' && !empty($msg['tool_calls'])) {
            return [
                'role'       => 'assistant',
                'content'    => $msg['content'] ?? null,
                'tool_calls' => $msg['tool_calls'],
            ];
        }

        $content = $msg['content'] ?? '';

        if (is_string($content) && $content !== '' && in_array($role, ['user', 'assistant'], true)) {
            return ['role' => $role, 'content' => $content];
        }

        // Legacy Anthropic-format blocks (when switching providers mid-session)
        if (is_array($content)) {
            if ($role === 'user' && ($content[0]['type'] ?? '') === 'tool_result') {
                return null;
            }
            if ($role === 'assistant') {
                $text = '';
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= $block['text'] ?? '';
                    }
                }
                return $text !== '' ? ['role' => 'assistant', 'content' => trim($text)] : null;
            }
        }

        return null;
    }
}
