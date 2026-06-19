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

// Backup automático diario a las 2am — configurable via backup_schedule_enabled setting
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->when(fn () => \App\Models\Setting::get('backup_schedule_enabled', '1') === '1')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->when(fn () => \App\Models\Setting::get('backup_schedule_enabled', '1') === '1')
    ->withoutOverlapping()
    ->onOneServer();

// Activos fijos: postea la depreciación del mes recién cerrado el día 1 a las 02:00.
Schedule::command('depreciation:run', ['--month' => now()->subMonthNoOverflow()->format('Y-m')])
    ->monthlyOn(1, '02:00')
    ->description('Depreciación mensual de activos fijos');

// Recordatorios personales: revisa cada minuto los vencidos y los envía por Telegram.
Schedule::command('reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
