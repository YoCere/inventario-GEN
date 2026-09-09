<?php

namespace App\Notifications\Channels;

use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de notificacion de Laravel para las alertas de spatie/laravel-backup.
 * Referenciado por FQCN en config/backup.php notifications (Laravel acepta un
 * class-string como canal en via(); no requiere Notification::extend).
 *
 * Usa dispatchSync a proposito: NO hay queue:work en prod (docker/supervisord.conf),
 * asi que un job encolado nunca se entregaria — la alerta debe salir sincrona.
 */
class BackupTelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMail')) {
            return;
        }

        if (Setting::get('telegram_enabled') !== '1') {
            return;
        }

        $chatId = Setting::get('telegram_admin_chat_id');
        if (! $chatId) {
            Log::warning('Alerta de backup sin telegram_admin_chat_id configurado');
            return;
        }

        $mail = $notification->toMail();
        $lines = array_merge($mail->introLines ?? [], $mail->outroLines ?? []);
        $text = '🛑 <b>' . e($mail->subject ?? 'Backup') . "</b>\n\n" . e(implode("\n", $lines));

        // Telegram corta a 4096; dejamos margen (el trace de la excepcion puede ser largo).
        if (mb_strlen($text) > 3500) {
            $text = mb_substr($text, 0, 3500);
            // No cortar una entidad HTML a la mitad (&gt; -> &g): Telegram rechaza el
            // mensaje entero con 400 y se pierde la alerta. Quitar cualquier entidad
            // incompleta que haya quedado al final del corte.
            $text = preg_replace('/&[#a-zA-Z0-9]*$/', '', $text) . '…';
        }

        SendTelegramMessage::dispatchSync($chatId, $text);
    }
}
