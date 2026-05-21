<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shop module: cancela reservas web abandonadas cada hora. Libera stock que
// quedó apartado por clientes que nunca confirmaron en WhatsApp.
// Default 24h de antigüedad — ajustable por flag --hours= en el comando.
Schedule::command('shop:cancel-expired-reservations')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
