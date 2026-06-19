<?php

namespace Tests\Feature\Reminders;

use App\Models\Reminder;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\BotAuthHandler;
use App\Services\Telegram\ReminderHandler;
use App\Services\Messaging\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ReminderHandlerTest extends TestCase
{
    use RefreshDatabase;

    private ReminderHandler $handler;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-19 10:00:00');

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturn([]);

        $this->user = User::factory()->create();
        TelegramUser::create(['chat_id' => '555', 'user_id' => $this->user->id, 'identifier' => 'alice']);

        $auth = Mockery::mock(BotAuthHandler::class);
        $auth->shouldReceive('getAuthenticatedUser')->with('555')->andReturn($this->user);

        $this->handler = new ReminderHandler($telegram, $auth);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_one_time_reminder_through_guided_flow(): void
    {
        $this->handler->start('555');
        $this->handler->handle('555', ['text' => 'Comprar cajas']);
        $this->handler->handle('555', ['text' => '20/06/2026 15:00']);
        $this->handler->handle('555', ['text' => '1']);
        $this->handler->handle('555', ['text' => '1']);

        $reminder = Reminder::forUser($this->user->id)->first();
        $this->assertNotNull($reminder, 'Expected a Reminder to be created');
        $this->assertSame('Comprar cajas', $reminder->title);
        $this->assertSame('none', $reminder->recurrence);
        $this->assertSame('20/06/2026 15:00', $reminder->remind_at->format('d/m/Y H:i'));

        $this->assertDatabaseMissing('telegram_conversations', ['chat_id' => '555']);
    }

    public function test_rejects_a_past_date(): void
    {
        $this->handler->start('555');
        $this->handler->handle('555', ['text' => 'Algo']);
        $this->handler->handle('555', ['text' => '01/01/2020 10:00']);

        $this->assertSame(0, Reminder::count());

        $conv = TelegramConversation::where('chat_id', '555')->first();
        $this->assertNotNull($conv);
        $this->assertSame('recordar:fecha', $conv->step);
    }

    public function test_cancels_only_the_own_reminder_by_number(): void
    {
        // Foreign user's reminder (should NOT be cancelled)
        $foreignUser = User::factory()->create();
        $foreignReminder = Reminder::factory()->create([
            'user_id' => $foreignUser->id,
            'status'   => 'pending',
            'remind_at' => now()->addDay(),
            'recurrence' => 'none',
        ]);

        // Own user's reminder (should be cancelled)
        $ownReminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'status'   => 'pending',
            'remind_at' => now()->addDay(),
            'recurrence' => 'none',
        ]);

        $this->handler->listAndManage('555');
        $this->handler->handle('555', ['text' => '1']);

        $this->assertSame('cancelled', $ownReminder->fresh()->status);
        $this->assertSame('pending', $foreignReminder->fresh()->status);
    }

    public function test_creates_weekly_recurring_reminder(): void
    {
        $this->handler->start('555');
        $this->handler->handle('555', ['text' => 'Pagar alquiler']);
        $this->handler->handle('555', ['text' => '22/06/2026 09:00']); // 22/06/2026 is a Monday
        $this->handler->handle('555', ['text' => '3']); // cada semana
        $this->handler->handle('555', ['text' => '1']); // guardar

        $r = \App\Models\Reminder::forUser($this->user->id)->first();
        $this->assertNotNull($r);
        $this->assertSame('weekly', $r->recurrence);
        $this->assertSame(['days' => [1]], $r->recurrence_rule); // Monday = isoWeekday 1
    }

    public function test_invalid_recurrence_input_stays_on_step(): void
    {
        $this->handler->start('555');
        $this->handler->handle('555', ['text' => 'Algo']);
        $this->handler->handle('555', ['text' => '21/06/2026 12:00']);
        $this->handler->handle('555', ['text' => '9']); // inválido

        $this->assertSame(0, \App\Models\Reminder::count());
        $this->assertSame(
            'recordar:recurrencia',
            \App\Models\TelegramConversation::where('chat_id', '555')->first()->step
        );
    }

    public function test_rejects_overflow_date(): void
    {
        $this->handler->start('555');
        $this->handler->handle('555', ['text' => 'Algo']);
        $this->handler->handle('555', ['text' => '32/13/2026 25:99']); // overflow inválido

        $this->assertSame(0, \App\Models\Reminder::count());
        $this->assertSame(
            'recordar:fecha',
            \App\Models\TelegramConversation::where('chat_id', '555')->first()->step
        );
    }
}
