<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\WebConversation;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Assistant\AssistantWebHandler;
use App\Services\Assistant\WebAssistantPrompt;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AssistantWebHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_handle_returns_text_and_persists_history(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $fakeAgent = Mockery::mock(AgentService::class);
        $fakeAgent->shouldReceive('run')->once()->andReturn([
            'text' => 'Las ventas de hoy son 100 Bs.',
            'messages' => [
                ['role' => 'user', 'content' => 'ventas hoy?'],
                ['role' => 'assistant', 'content' => 'Las ventas de hoy son 100 Bs.'],
            ],
            'usage' => [],
        ]);
        $this->app->bind(AgentService::class, fn () => $fakeAgent);

        $handler = $this->app->make(AssistantWebHandler::class);
        $reply = $handler->handle($user, 'ventas hoy?', 'dashboard');

        $this->assertSame('Las ventas de hoy son 100 Bs.', $reply);
        $this->assertCount(2, WebConversation::getOrCreate($user)->history());
    }

    public function test_builds_web_context_with_route_and_prompt(): void
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        $captured = null;
        $fakeAgent = Mockery::mock(AgentService::class);
        $fakeAgent->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($msg, $history, AgentContext $ctx) use (&$captured) {
                $captured = $ctx;
                return ['text' => 'ok', 'messages' => [], 'usage' => []];
            });
        $this->app->bind(AgentService::class, fn () => $fakeAgent);

        $handler = $this->app->make(AssistantWebHandler::class);
        $handler->handle($user, 'hola', 'finance.index');

        $this->assertSame('web', $captured->channel);
        $this->assertNotNull($captured->systemPrompt);
        $this->assertSame('finance.index', $captured->route);
    }

    public function test_prompt_mentions_role_appropriate_modules(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $prompt = WebAssistantPrompt::build($staff, 'dashboard');

        $this->assertStringContainsString('Ventas', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('Plan de cuentas', $prompt);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
