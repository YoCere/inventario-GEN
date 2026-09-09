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

    public function test_long_message_truncated_without_splitting_html_entity(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '12345');

        // Cuerpo largo lleno de '>' → tras e() se vuelve '&gt;' denso; el corte NO debe
        // dejar una entidad partida (Telegram rechazaria el mensaje entero con 400).
        $notification = new class extends Notification {
            public function toMail(): MailMessage
            {
                return (new MailMessage)->error()->subject('Backup failed')
                    ->line(str_repeat('a -> b ', 2000)); // ~14k chars crudos, muchos '->'
            }
        };

        (new BackupTelegramChannel())->send(null, $notification);

        Bus::assertDispatchedSync(SendTelegramMessage::class, function (SendTelegramMessage $j) {
            // Bajo el limite de Telegram (4096) y sin entidad HTML incompleta al final.
            $this->assertLessThanOrEqual(4096, mb_strlen($j->message));
            $this->assertDoesNotMatchRegularExpression('/&[#a-zA-Z0-9]*$/', rtrim($j->message, '…'));
            return true;
        });
    }
}
