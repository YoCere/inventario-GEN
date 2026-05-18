<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps LLM API with tool use loop.
 * Supports Anthropic (with prompt caching) and OpenAI-compatible APIs
 * (DeepSeek, Groq, Together AI, OpenAI, etc.).
 */
class AgentService
{
    private const MAX_TOOL_ITERATIONS = 6;

    // Anthropic-specific
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_API_VERSION = '2023-06-01';

    public function __construct(
        private ToolRegistry $tools,
        private CostTracker $costTracker,
    ) {}

    /**
     * Run the agent for a single user turn.
     *
     * @param array<int, array{role:string, content:mixed}> $history Past turns
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

        $model = Setting::get('ai_model', 'claude-haiku-4-5-20251001');
        $maxTokens = (int) Setting::get('ai_max_tokens_response', '1024');
        $systemPrompt = Setting::get('ai_system_prompt', '');

        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $toolSchemas = $this->tools->anthropicSchemas();

        $systemBlocks = [
            ['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']],
        ];

        $finalText = '';
        $totalUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ];

        for ($iter = 0; $iter < self::MAX_TOOL_ITERATIONS; $iter++) {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_API_VERSION,
                'content-type' => 'application/json',
            ])->timeout(60)->post(self::ANTHROPIC_API_URL, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemBlocks,
                'tools' => $toolSchemas,
                'messages' => $messages,
            ]);

            if ($response->failed()) {
                Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Error en API IA: ' . $response->status());
            }

            $data = $response->json();
            $usage = $data['usage'] ?? [];
            $totalUsage['input_tokens'] += $usage['input_tokens'] ?? 0;
            $totalUsage['output_tokens'] += $usage['output_tokens'] ?? 0;
            $totalUsage['cache_creation_input_tokens'] += $usage['cache_creation_input_tokens'] ?? 0;
            $totalUsage['cache_read_input_tokens'] += $usage['cache_read_input_tokens'] ?? 0;

            $content = $data['content'] ?? [];
            $stopReason = $data['stop_reason'] ?? null;

            $this->costTracker->record(
                userId: $context->user?->id,
                chatId: $context->chatId,
                model: $model,
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
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolUseId,
                        'content' => json_encode(['error' => "Tool '{$block['name']}' no encontrada."]),
                        'is_error' => true,
                    ];
                    continue;
                }

                try {
                    $result = $tool->execute($block['input'] ?? [], $context);
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolUseId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Tool execution failed', ['tool' => $block['name'], 'error' => $e->getMessage()]);
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolUseId,
                        'content' => json_encode(['error' => $e->getMessage()]),
                        'is_error' => true,
                    ];
                }
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return [
            'text' => $finalText,
            'messages' => $messages,
            'usage' => $totalUsage,
        ];
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

        $model = Setting::get('ai_model', 'deepseek-chat');
        $maxTokens = (int) Setting::get('ai_max_tokens_response', '1024');
        $systemPrompt = Setting::get('ai_system_prompt', '');
        $baseUrl = rtrim(Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');

        // Build messages: system + converted history + current user message
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($history as $msg) {
            $converted = $this->convertHistoryToOpenAi($msg);
            if ($converted !== null) {
                $messages[] = $converted;
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $toolSchemas = $this->tools->openaiSchemas();

        $finalText = '';
        $totalUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ];

        for ($iter = 0; $iter < self::MAX_TOOL_ITERATIONS; $iter++) {
            $payload = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ];

            if (!empty($toolSchemas)) {
                $payload['tools'] = $toolSchemas;
                $payload['tool_choice'] = 'auto';
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($baseUrl . '/chat/completions', $payload);

            if ($response->failed()) {
                Log::error('OpenAI-compatible API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Error en API IA: ' . $response->status());
            }

            $data = $response->json();
            $usage = $data['usage'] ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;

            $totalUsage['input_tokens'] += $promptTokens;
            $totalUsage['output_tokens'] += $completionTokens;

            $this->costTracker->record(
                userId: $context->user?->id,
                chatId: $context->chatId,
                model: $model,
                action: 'agent.text',
                tokensIn: $promptTokens,
                tokensOut: $completionTokens,
                summary: 'iter ' . $iter,
            );

            $choice = $data['choices'][0] ?? null;
            if (!$choice) {
                break;
            }

            $finishReason = $choice['finish_reason'] ?? null;
            $assistantMessage = $choice['message'] ?? [];

            if ($finishReason !== 'tool_calls' || empty($assistantMessage['tool_calls'])) {
                $finalText = $assistantMessage['content'] ?? '';
                break;
            }

            // Append assistant message with tool_calls
            $messages[] = $assistantMessage;

            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';
                $toolInput = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
                $toolCallId = $toolCall['id'] ?? '';

                $tool = $this->tools->get($toolName);

                if (!$tool) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode(['error' => "Tool '{$toolName}' no encontrada."]),
                    ];
                    continue;
                }

                try {
                    $result = $tool->execute($toolInput, $context);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Tool execution failed', ['tool' => $toolName, 'error' => $e->getMessage()]);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode(['error' => $e->getMessage()]),
                    ];
                }
            }
        }

        // Save only text user/assistant turns as history (provider-agnostic)
        $simpleHistory = array_values(array_filter($messages, function (array $m): bool {
            return in_array($m['role'] ?? '', ['user', 'assistant'], true)
                && is_string($m['content'] ?? null)
                && ($m['role'] !== 'system');
        }));

        return [
            'text' => (string) $finalText,
            'messages' => $simpleHistory,
            'usage' => $totalUsage,
        ];
    }

    /**
     * Convert one Anthropic-format history message to OpenAI format.
     * Returns null for messages that should be skipped (tool results, etc.).
     */
    private function convertHistoryToOpenAi(array $msg): ?array
    {
        $role = $msg['role'] ?? '';
        $content = $msg['content'] ?? '';

        if (is_string($content) && $content !== '' && in_array($role, ['user', 'assistant'], true)) {
            return ['role' => $role, 'content' => $content];
        }

        if (is_array($content)) {
            // user message that is a tool_result block → skip
            if ($role === 'user' && ($content[0]['type'] ?? '') === 'tool_result') {
                return null;
            }

            // assistant message with content blocks → extract text
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
