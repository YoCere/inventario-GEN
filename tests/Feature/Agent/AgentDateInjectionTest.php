<?php

namespace Tests\Feature\Agent;

use App\Models\Setting;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El agente debe recibir la fecha/hora actual para resolver relativos ("mañana").
 * Sin esto, el LLM adivina la fecha con su cutoff de entrenamiento.
 */
class AgentDateInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('business_timezone', 'America/La_Paz');
        Carbon::setTestNow(Carbon::parse('2026-06-19 17:42:00', 'UTC')); // 13:42 La_Paz
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_anthropic_request_includes_current_datetime_in_system(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');
        Setting::set('ai_system_prompt', 'Eres un asistente.');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $service = app(AgentService::class);
        $service->run('hola', [], new AgentContext(null, '555', 'telegram'));

        Http::assertSent(function ($request) {
            $system = $request['system'] ?? [];
            $joined = collect($system)->pluck('text')->implode(' ');
            return str_contains($joined, '2026')
                && str_contains($joined, '13:42')
                && str_contains($joined, 'America/La_Paz');
        });
    }

    public function test_openai_request_includes_current_datetime_in_system(): void
    {
        Setting::set('ai_provider', 'openai_compatible');
        Setting::set('openai_api_key', 'sk-test');
        Setting::set('ai_api_base_url', 'https://api.openai.com/v1');
        Setting::set('ai_system_prompt', 'Eres un asistente.');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $service = app(AgentService::class);
        $service->run('hola', [], new AgentContext(null, '555', 'telegram'));

        Http::assertSent(function ($request) {
            $systemMsgs = collect($request['messages'] ?? [])
                ->where('role', 'system')
                ->pluck('content')
                ->implode(' ');
            return str_contains($systemMsgs, '2026')
                && str_contains($systemMsgs, '13:42')
                && str_contains($systemMsgs, 'America/La_Paz');
        });
    }
}
