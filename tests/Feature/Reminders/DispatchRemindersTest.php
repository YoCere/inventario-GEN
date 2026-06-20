<?php

namespace Tests\Feature\Reminders;

use App\Models\Reminder;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> chatIds a los que se envió un mensaje */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-19 15:00:00');

        // El despacho ahora envía síncrono (dispatchSync) → el job corre inline y
        // llama a TelegramService. Lo mockeamos para no pegar a la API real y para
        // capturar a qué chat se envió.
        $this->sent = [];
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function ($chatId) {
            $this->sent[] = $chatId;
            return [];
        });
        $this->app->instance(TelegramService::class, $telegram);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_sends_due_one_time_reminder_and_marks_sent(): void
    {
        $user = User::factory()->create();

        $reminder = Reminder::factory()->create([
            'user_id'    => $user->id,
            'chat_id'    => '555',
            'remind_at'  => now()->subMinute(),
            'recurrence' => 'none',
            'status'     => 'pending',
        ]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        $this->assertContains('555', $this->sent);

        $reminder->refresh();
        $this->assertSame('sent', $reminder->status);
        $this->assertSame(1, $reminder->sent_count);
    }

    public function test_reschedules_recurring_reminder_instead_of_marking_sent(): void
    {
        $user = User::factory()->create();

        $reminder = Reminder::factory()->daily()->create([
            'user_id'   => $user->id,
            'chat_id'   => '555',
            'remind_at' => Carbon::parse('2026-06-19 14:00:00'),
            'status'    => 'pending',
        ]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        $this->assertContains('555', $this->sent);

        $reminder->refresh();
        $this->assertSame('pending', $reminder->status);
        $this->assertSame('2026-06-20 14:00:00', $reminder->remind_at->toDateTimeString());
        $this->assertSame(1, $reminder->sent_count);
    }

    public function test_does_not_send_not_yet_due_reminder(): void
    {
        $user = User::factory()->create();

        Reminder::factory()->create([
            'user_id'   => $user->id,
            'chat_id'   => '555',
            'remind_at' => now()->addHour(),
            'status'    => 'pending',
        ]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        $this->assertEmpty($this->sent);
    }

    public function test_falls_back_to_telegram_user_chat_id_when_reminder_chat_id_is_null(): void
    {
        $user = User::factory()->create();

        TelegramUser::create([
            'chat_id'    => '999',
            'user_id'    => $user->id,
            'identifier' => 'alice',
        ]);

        Reminder::factory()->create([
            'user_id'   => $user->id,
            'chat_id'   => null,
            'remind_at' => now()->subMinute(),
            'status'    => 'pending',
        ]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        $this->assertContains('999', $this->sent);
    }

    public function test_one_corrupt_reminder_does_not_block_others(): void
    {
        $user = User::factory()->create();

        // Recordatorio corrupto: regla semanal con weekday inválido (9) → next() lanza LogicException.
        Reminder::factory()->create([
            'user_id'          => $user->id,
            'chat_id'          => '111',
            'remind_at'        => now()->subMinutes(2),
            'recurrence'       => 'weekly',
            'recurrence_rule'  => ['days' => [9]],
            'status'           => 'pending',
        ]);

        // Recordatorio sano que debe enviarse igual.
        Reminder::factory()->create([
            'user_id'    => $user->id,
            'chat_id'    => '222',
            'remind_at'  => now()->subMinute(),
            'recurrence' => 'none',
            'status'     => 'pending',
        ]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        // El sano se envió pese al fallo del corrupto.
        $this->assertContains('222', $this->sent);
    }
}
