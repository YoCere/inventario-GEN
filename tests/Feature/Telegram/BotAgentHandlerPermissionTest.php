<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotAgentHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotAgentHandlerPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_passed_to_agent_is_filtered_by_user_permissions(): void
    {
        // El bot manda mensajes salientes; TelegramService::sendMessage lanza si no hay token
        // (antes de tocar la red), así que lo mockeamos como no-op para aislar el test.
        $this->mock(TelegramService::class, function ($mock) {
            $mock->shouldReceive('sendChatAction')->andReturn([]);
            $mock->shouldReceive('sendMessage')->andReturn([]);
            $mock->shouldReceive('sendVoice')->andReturn([]);
        });

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.create');

        $chatId = '999000';
        TelegramUser::create([
            'chat_id' => $chatId,
            'user_id' => $user->id,
            'identifier' => $user->email,
        ]);

        $capturedTools = null;
        $this->app->bind(AgentService::class, function ($app, $params) use (&$capturedTools) {
            $capturedTools = $params['tools'];
            return new class($params['tools']) extends AgentService {
                public function __construct(public ToolRegistry $reg) {}
                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    return ['text' => 'ok', 'messages' => []];
                }
            };
        });

        app(BotAgentHandler::class)->handle($chatId, 'hola');

        $this->assertNotNull($capturedTools, 'BotAgentHandler debe construir un AgentService con un registry.');
        $keys = $capturedTools->all();
        $this->assertArrayNotHasKey('get_balance_sheet', $keys); // sin finance.view
        $this->assertArrayHasKey('start_sale', $keys);           // con sales.create
    }

    public function test_unlinked_chat_gets_empty_registry_fail_closed(): void
    {
        // Chat SIN TelegramUser vinculado → $user es null → registry vacío (fail-closed):
        // el LLM no recibe ninguna tool.
        $this->mock(TelegramService::class, function ($mock) {
            $mock->shouldReceive('sendChatAction')->andReturn([]);
            $mock->shouldReceive('sendMessage')->andReturn([]);
            $mock->shouldReceive('sendVoice')->andReturn([]);
        });

        $capturedTools = null;
        $this->app->bind(AgentService::class, function ($app, $params) use (&$capturedTools) {
            $capturedTools = $params['tools'];
            return new class($params['tools']) extends AgentService {
                public function __construct(public ToolRegistry $reg) {}
                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    return ['text' => 'ok', 'messages' => []];
                }
            };
        });

        // '555111' no tiene fila en telegram_users.
        app(BotAgentHandler::class)->handle('555111', 'hola');

        $this->assertNotNull($capturedTools, 'BotAgentHandler debe construir un AgentService aun sin usuario.');
        $this->assertSame([], $capturedTools->all(), 'Sin usuario vinculado el registry debe estar vacío.');
    }
}
