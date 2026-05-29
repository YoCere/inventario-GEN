<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\TtsService;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Facades\Log;

class BotAgentHandler
{
    private const MAX_HISTORY_TURNS_ANTHROPIC = 8;
    // Increased to 12: tool_calls + tool messages now persisted, so each "turn" expands to
    // ~3 messages (user, assistant+tool_call, tool result). 12 ≈ 4 conversational turns.
    private const MAX_HISTORY_TURNS_OPENAI    = 12;

    public function __construct(
        protected TelegramService $telegram,
        protected AgentService $agent,
        protected TtsService $tts,
    ) {}

    public function handle(string $chatId, string $userText, bool $withVoiceReply = false): void
    {
        try {
            $telegramUser = TelegramUser::find($chatId);
            $user = $telegramUser?->user;

            $context = new AgentContext(
                user: $user,
                chatId: $chatId,
                channel: 'telegram',
            );

            // Load conversation memory (last N turns)
            $conversation = TelegramConversation::getOrCreate($chatId);
            $history = $this->loadHistory($conversation);

            // Show "thinking" indicator (best effort)
            try {
                $this->telegram->sendChatAction($chatId, 'typing');
            } catch (\Throwable $e) {
                // non-critical
            }

            $result = $this->agent->run($userText, $history, $context);

            // Check if a tool set a bot flow state (e.g. start_sale → busqueda:resultado,
            // start_product_creation → nuevo:*). Tool already sent UI; skip agent text.
            $conversation->refresh();
            $step = $conversation->step;
            $botFlowOwned = in_array($step, ['busqueda:resultado', 'busqueda:multiple', 'venta_rapida:cantidad'], true)
                || str_starts_with($step, 'nuevo:')
                || str_starts_with($step, 'venta_rapida:')
                || str_starts_with($step, 'devolver:');
            if ($botFlowOwned) {
                return; // bot flow owns step now; saveHistory would overwrite it
            }

            $replyText = trim($result['text']);
            if (empty($replyText)) {
                $replyText = "(sin respuesta)";
            }

            $this->telegram->sendMessage($chatId, $replyText);

            if ($withVoiceReply) {
                $audio = $this->tts->synthesize($replyText);
                if ($audio) {
                    try {
                        $this->telegram->sendVoice($chatId, $audio['content'], $audio['filename']);
                    } catch (\Throwable $e) {
                        Log::warning('TTS voice send failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
                    }
                }
            }

            // Persist updated history (trim to last N)
            $this->saveHistory($conversation, $result['messages']);
        } catch (\Throwable $e) {
            Log::error('Agent handler error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ No pude procesar tu mensaje. Intenta de nuevo."
            );
        }
    }

    public function handleVoice(string $chatId, string $transcript): void
    {
        $withVoiceReply = \App\Models\Setting::get('ai_voice_reply', '0') === '1';
        $this->handle($chatId, $transcript, $withVoiceReply);
    }

    private function loadHistory(TelegramConversation $conversation): array
    {
        $data = $conversation->data ?? [];
        $history = $data['agent_history'] ?? [];

        // Defensive: ensure array of valid turns
        return \is_array($history) ? $history : [];
    }

    private function saveHistory(TelegramConversation $conversation, array $messages): void
    {
        $limit   = \App\Models\Setting::get('ai_provider', 'anthropic') === 'openai_compatible'
            ? self::MAX_HISTORY_TURNS_OPENAI
            : self::MAX_HISTORY_TURNS_ANTHROPIC;
        $trimmed = \array_slice($messages, -$limit);

        $data = $conversation->data ?? [];
        $data['agent_history'] = $trimmed;

        $conversation->update([
            'step' => 'agent:active',
            'data' => $data,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
