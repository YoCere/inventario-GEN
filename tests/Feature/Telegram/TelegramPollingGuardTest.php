<?php

namespace Tests\Feature\Telegram;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El bot lo levanta supervisor en cada despliegue, en un bucle que lo reintenta.
 *
 * Por eso el comando tiene que SALIR solo (sin error) cuando el bot está apagado
 * o sin token: si en cambio se quedara escuchando para siempre, llenaría los logs
 * de errores y, peor, nunca tomaría el token que se cargue después desde Ajustes,
 * porque el proceso ya está arriba con el valor viejo.
 */
class TelegramPollingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_token_sale_sin_error_en_vez_de_quedarse_escuchando(): void
    {
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_bot_token', '');

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('apagado o sin token')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(
            storage_path('framework/telegram-poll.pid'),
            'No debería haber dejado el archivo de proceso: nunca llegó a escuchar.'
        );
    }

    public function test_con_el_bot_apagado_sale_sin_error_aunque_haya_token(): void
    {
        Setting::set('telegram_enabled', '0');
        Setting::set('telegram_bot_token', '123456:token-de-prueba');

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('apagado o sin token')
            ->assertSuccessful();
    }
}
