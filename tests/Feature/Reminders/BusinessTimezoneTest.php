<?php

namespace Tests\Feature\Reminders;

use App\Models\Reminder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CreateReminderTool;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_timezone_falls_back_to_app_default_when_unset(): void
    {
        $this->assertSame(config('app.timezone'), BusinessTime::timezone());
    }

    public function test_timezone_reads_configured_setting(): void
    {
        Setting::set('business_timezone', 'America/La_Paz');
        $this->assertSame('America/La_Paz', BusinessTime::timezone());
    }

    public function test_timezone_falls_back_when_value_is_invalid(): void
    {
        Setting::set('business_timezone', 'Zona/Inexistente');
        $this->assertSame(config('app.timezone'), BusinessTime::timezone());
    }

    public function test_prompt_context_contains_current_local_date(): void
    {
        Setting::set('business_timezone', 'America/La_Paz');
        // 17:42 UTC = 13:42 en La_Paz (UTC-4).
        Carbon::setTestNow(Carbon::parse('2026-06-19 17:42:00', 'UTC'));

        $context = BusinessTime::promptContext();

        $this->assertStringContainsString('2026', $context);
        $this->assertStringContainsString('13:42', $context);
        $this->assertStringContainsString('America/La_Paz', $context);
    }

    public function test_create_reminder_tool_stores_business_local_time_as_utc(): void
    {
        Setting::set('business_timezone', 'America/La_Paz');
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00', 'UTC'));

        $user = User::factory()->create();
        $context = new AgentContext($user, '555', 'telegram');

        // 16:00 hora local La_Paz para mañana.
        $result = (new CreateReminderTool())->execute([
            'title' => 'Reunión',
            'remind_at' => '2026-06-20T16:00:00',
            'recurrence' => 'none',
        ], $context);

        $this->assertArrayNotHasKey('error', $result);

        $reminder = Reminder::first();
        $this->assertSame('America/La_Paz', $reminder->timezone);
        // 16:00 La_Paz (UTC-4) = 20:00 UTC almacenado.
        $this->assertSame('2026-06-20 20:00:00', $reminder->remind_at->toDateTimeString());
    }
}
