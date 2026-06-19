<?php

namespace Tests\Feature\Reminders;

use App\Models\Reminder;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CreateReminderTool;
use App\Services\Agent\Tools\ListRemindersTool;
use App\Services\Agent\Tools\CancelReminderTool;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AgentContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-19 10:00:00');
        $this->user    = User::factory()->create();
        $this->context = new AgentContext($this->user, '555', 'telegram');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_create_reminder_tool_creates_reminder(): void
    {
        $result = (new CreateReminderTool())->execute([
            'title'      => 'Pagar factura',
            'remind_at'  => '2026-06-20T15:00:00',
            'recurrence' => 'none',
        ], $this->context);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, Reminder::count());

        $reminder = Reminder::first();
        $this->assertSame('Pagar factura', $reminder->title);
        $this->assertSame('none', $reminder->recurrence);
        $this->assertSame('pending', $reminder->status);
        $this->assertSame('nl', $reminder->created_via);
    }

    public function test_create_reminder_tool_rejects_past_date(): void
    {
        $result = (new CreateReminderTool())->execute([
            'title'     => 'Algo pasado',
            'remind_at' => '2026-06-18T10:00:00',
        ], $this->context);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reminder::count());
    }

    public function test_create_reminder_tool_stores_weekly_recurrence_rule(): void
    {
        // 2026-06-22 is a Monday (isoWeekday = 1)
        $result = (new CreateReminderTool())->execute([
            'title'      => 'Reunión semanal',
            'remind_at'  => '2026-06-22T09:00:00',
            'recurrence' => 'weekly',
        ], $this->context);

        $this->assertArrayNotHasKey('error', $result);

        $reminder = Reminder::first();
        $this->assertSame('weekly', $reminder->recurrence);
        $this->assertSame(['days' => [1]], $reminder->recurrence_rule);
    }

    public function test_create_reminder_tool_rejects_unauthenticated_context(): void
    {
        $anonymousContext = new AgentContext(null, '555', 'telegram');

        $result = (new CreateReminderTool())->execute([
            'title'     => 'Sin usuario',
            'remind_at' => '2026-06-20T15:00:00',
        ], $anonymousContext);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reminder::count());
    }

    public function test_create_reminder_tool_stores_monthly_recurrence_rule(): void
    {
        $result = (new CreateReminderTool())->execute([
            'title'      => 'Pagar renta',
            'remind_at'  => '2026-07-01T08:00:00',
            'recurrence' => 'monthly',
        ], $this->context);

        $this->assertArrayNotHasKey('error', $result);

        $reminder = Reminder::first();
        $this->assertSame('monthly', $reminder->recurrence);
        $this->assertSame(['day' => 1], $reminder->recurrence_rule);
    }

    public function test_create_returns_friendly_error_on_unparseable_date(): void
    {
        $result = (new CreateReminderTool())->execute([
            'title'     => 'Algo',
            'remind_at' => 'mañana por la tarde',
        ], $this->context);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reminder::count());
    }

    public function test_create_rejects_invalid_recurrence(): void
    {
        $result = (new CreateReminderTool())->execute([
            'title'      => 'Algo',
            'remind_at'  => '2026-06-20T15:00:00',
            'recurrence' => 'hourly',
        ], $this->context);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reminder::count());
    }

    public function test_confirmation_summary_degrades_on_bad_date(): void
    {
        // No debe lanzar excepción ante fecha no parseable.
        $summary = (new CreateReminderTool())
            ->confirmationSummary(['title' => 'X', 'remind_at' => 'no-es-fecha']);
        $this->assertStringContainsString('X', $summary);
    }

    public function test_list_reminders_tool_returns_only_own(): void
    {
        $other = User::factory()->create();
        Reminder::factory()->create(['user_id' => $other->id, 'status' => 'pending']);
        Reminder::factory()->create([
            'user_id'   => $this->user->id,
            'status'    => 'pending',
            'remind_at' => now()->addDay(),
        ]);

        $result = (new ListRemindersTool())->execute([], $this->context);

        $this->assertSame(1, $result['count']);
    }

    public function test_cancel_reminder_tool_cannot_cancel_another_users_reminder(): void
    {
        $other   = User::factory()->create();
        $foreign = Reminder::factory()->create(['user_id' => $other->id, 'status' => 'pending']);

        $result = (new CancelReminderTool())->execute(['id' => $foreign->id], $this->context);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_cancel_reminder_tool_cancels_own_reminder(): void
    {
        $mine = Reminder::factory()->create([
            'user_id'   => $this->user->id,
            'status'    => 'pending',
            'remind_at' => now()->addDay(),
        ]);

        $result = (new CancelReminderTool())->execute(['id' => $mine->id], $this->context);

        $this->assertArrayHasKey('ok', $result);
        $this->assertSame('cancelled', $mine->fresh()->status);
    }
}
