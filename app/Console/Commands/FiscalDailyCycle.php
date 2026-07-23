<?php

namespace App\Console\Commands;

use App\Fiscal\Siat\CatalogSync;
use App\Fiscal\Siat\FiscalAuthority;
use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FiscalDailyCycle extends Command
{
    protected $signature = 'fiscal:daily-cycle {--sucursal=0} {--pv=0}';
    protected $description = 'Ciclo diario de autorización del SIN (CUIS/CUFD/catálogos)';

    public function handle(FiscalAuthority $authority, CatalogSync $catalogs): int
    {
        $sucursal = (int) $this->option('sucursal');
        $pv = (int) $this->option('pv');

        // Guard de go-live: si el ambiente dice producción pero el proveedor sigue en
        // simulador, se estaría "facturando" contra datos falsos sin que nadie se entere.
        // El simulador nunca falla, así que sin esta alerta el error sería invisible.
        if (Setting::get('siat_environment') === 'produccion'
            && Setting::get('fiscal_provider', 'simulator') !== 'siat') {
            $this->notifyAdmin('⚠️ SIAT en PRODUCCIÓN pero el proveedor sigue en SIMULADOR: no se está facturando de verdad. Setear fiscal_provider=siat.');
        }

        try {
            $authority->ensureCuis($sucursal, $pv);
            $authority->currentCufd($sucursal, $pv);
            $catalogs->syncAll();

            if ($authority->cuisExpiringSoon($sucursal, $pv)) {
                $this->notifyAdmin('El CUIS del SIN está por vencer. Renovar pronto.');
            }

            $this->info('Ciclo diario del SIN completado.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Fallo el ciclo diario del SIN', ['error' => $e->getMessage()]);
            $this->notifyAdmin('Falló el ciclo diario del SIN: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Alerta al admin por Telegram. Si no hay chat configurado (o falla el envío),
     * degrada a un log de advertencia — nunca debe interrumpir el ciclo.
     */
    private function notifyAdmin(string $message): void
    {
        try {
            $chatId = Setting::get('telegram_admin_chat_id');
            if (!$chatId) {
                Log::warning('Alerta del ciclo diario del SIN sin chat de Telegram configurado', [
                    'message' => $message,
                ]);
                return;
            }

            SendTelegramMessage::dispatch($chatId, $message);
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la alerta del ciclo diario del SIN por Telegram', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
