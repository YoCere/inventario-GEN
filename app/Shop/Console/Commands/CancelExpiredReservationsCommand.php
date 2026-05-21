<?php

namespace App\Shop\Console\Commands;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Auto-cancela reservas web (source='web') en estado PENDING con más de N
 * horas de antigüedad. Libera stock que quedó apartado por clientes que
 * nunca confirmaron por WhatsApp.
 *
 * Por defecto 24 horas. Configurable vía --hours= flag.
 *
 * Uso:
 *   php artisan shop:cancel-expired-reservations
 *   php artisan shop:cancel-expired-reservations --hours=48
 *   php artisan shop:cancel-expired-reservations --dry-run
 *
 * Programar en bootstrap/app.php o routes/console.php para correr cada hora.
 */
class CancelExpiredReservationsCommand extends Command
{
    protected $signature = 'shop:cancel-expired-reservations
        {--hours=24 : Antigüedad mínima en horas para considerar expirada}
        {--dry-run : Listar lo que se cancelaría sin tocar nada}';

    protected $description = 'Cancela reservas web PENDING antiguas y restaura stock apartado';

    public function handle(SaleService $saleService): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        if ($hours < 1) {
            $this->error('--hours debe ser >= 1');
            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        $candidates = Sale::query()
            ->where('source', 'web')
            ->where('status', SaleStatus::PENDING)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("Ninguna reserva web PENDING tiene más de {$hours}h. Nada que hacer.");
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d reserva(s) web PENDING > %dh:',
            $dryRun ? 'Encontradas (dry-run)' : 'Cancelando',
            $candidates->count(),
            $hours
        ));

        $cancelled = 0;
        $failed = 0;

        foreach ($candidates as $sale) {
            $age = $sale->created_at->diffForHumans(null, true);
            $this->line("  • {$sale->invoice_number} ({$sale->buyer_name} – {$age})");

            if ($dryRun) {
                continue;
            }

            try {
                $saleService->cancelSale(
                    $sale,
                    "Auto-cancelada por inactividad >{$hours}h (reserva web sin confirmación)."
                );
                $cancelled++;
            } catch (\Throwable $e) {
                $this->error("    ✗ Fallo cancelando #{$sale->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        if (! $dryRun) {
            Cache::forget('shop.pending_web_count');
            $this->newLine();
            $this->info("Canceladas: {$cancelled}" . ($failed ? ", fallidas: {$failed}" : ''));
        }

        return self::SUCCESS;
    }
}
