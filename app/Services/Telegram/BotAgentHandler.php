<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Facades\Log;

class BotAgentHandler
{
    private const MAX_HISTORY_TURNS = 8; // last N messages kept in context

    public function __construct(
        protected TelegramService $telegram,
        protected AgentService $agent,
    ) {}

    public function handle(string $chatId, string $userText): void
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

            $replyText = trim($result['text']);
            if (empty($replyText)) {
                $replyText = "(sin respuesta)";
            }

            $this->telegram->sendMessage($chatId, $replyText);

            // Persist updated history (trim to last N)
            $this->saveHistory($conversation, $result['messages']);
        } catch (\Throwable $e) {
            Log::error('Agent handler error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Error: " . $e->getMessage()
            );
        }
    }

    private function loadHistory(TelegramConversation $conversation): array
    {
        $data = $conversation->data ?? [];
        $history = $data['agent_history'] ?? [];

        // Defensive: ensure array of valid turns
        return is_array($history) ? $history : [];
    }

    private function saveHistory(TelegramConversation $conversation, array $messages): void
    {
        // Keep last MAX_HISTORY_TURNS user/assistant pairs
        $trimmed = array_slice($messages, -self::MAX_HISTORY_TURNS);

        $data = $conversation->data ?? [];
        $data['agent_history'] = $trimmed;

        $conversation->update([
            'step' => 'agent:active',
            'data' => $data,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
