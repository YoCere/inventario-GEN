<?php

namespace Tests\Feature\Backup;

use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use App\Notifications\Channels\BackupTelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BackupTelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    private function fakeNotification(): Notification
    {
        return new class extends Notification {
            public function toMail(): MailMessage
            {
                return (new MailMessage)->error()->subject('Backup failed')->line('boom');
            }
        };
    }

    public function test_dispatches_sync_telegram_when_enabled(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '12345');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertDispatchedSync(
            SendTelegramMessage::class,
            fn (SendTelegramMessage $j) => $j->chatId === '12345' && str_contains($j->message, 'Backup failed')
        );
    }

    public function test_does_nothing_when_telegram_disabled(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '0');
        Setting::set('telegram_admin_chat_id', '12345');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertNotDispatched(SendTelegramMessage::class);
    }

    public function test_does_nothing_without_chat_id(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertNotDispatched(SendTelegramMessage::class);
    }
}
