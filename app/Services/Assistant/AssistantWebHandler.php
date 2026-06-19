<?php

namespace App\Services\Assistant;

use App\Models\Setting;
use App\Models\User;
use App\Models\WebConversation;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use Illuminate\Support\Facades\Log;

class AssistantWebHandler
{
    private const MAX_HISTORY_ANTHROPIC = 8;
    private const MAX_HISTORY_OPENAI    = 12;

    public function __construct(
        private ToolRegistry $tools,
    ) {}

    public function handle(User $user, string $userText, string $currentRoute): string
    {
        try {
            $conversation = WebConversation::getOrCreate($user);
            $history = $conversation->history();

            $context = new AgentContext(
                user: $user,
                chatId: 'web:' . $user->id,
                channel: 'web',
                route: $currentRoute,
                systemPrompt: WebAssistantPrompt::build($user, $currentRoute),
            );

            $registry = $this->tools->forWeb($user);
            $agent = app()->makeWith(AgentService::class, ['tools' => $registry]);

            $result = $agent->run($userText, $history, $context);

            $limit = Setting::get('ai_provider', 'anthropic') === 'openai_compatible'
                ? self::MAX_HISTORY_OPENAI
                : self::MAX_HISTORY_ANTHROPIC;
            $conversation->saveHistory($result['messages'], $limit);

            $text = trim($result['text']);
            return $text !== '' ? $text : '(sin respuesta)';
        } catch (\Throwable $e) {
            Log::error('AssistantWebHandler error', ['user' => $user->id, 'error' => $e->getMessage()]);
            return '⚠️ No pude procesar tu mensaje. Intenta de nuevo en un momento.';
        }
    }
}
