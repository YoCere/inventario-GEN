<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que el respaldo se NEGO a correr (ver BackupSafeRunCommand).
 *
 * Expone toMail() a proposito: es lo unico que BackupTelegramChannel necesita
 * para armar el mensaje, y asi este aviso viaja por el mismo canal que las
 * alertas de spatie/laravel-backup en vez de inventar un segundo camino.
 */
class BackupBlockedNotification extends Notification
{
    public function __construct(private readonly string $motivo)
    {
    }

    public function toMail(mixed $notifiable = null): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Respaldo CANCELADO: no saldria cifrado')
            ->line($this->motivo)
            ->line('No se genero ni se subio ningun archivo. Cargá la variable y volvé a correr "php artisan backup:safe-run".');
    }
}
