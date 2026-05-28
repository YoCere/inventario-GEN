<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StockRepairCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:repair {--dry-run : Mostrar cambios sin aplicarlos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detecta y corrige desincronizaciones entre products.quantity y product_stocks';

    public function __construct(
        protected StockService $stockService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('--- MODO DRY-RUN: no se aplicarán cambios ---');
        }

        $this->info('Analizando desincronizaciones de stock...');

        // Find all products where products.quantity != sum(product_stocks.quantity)
        $desynced = DB::table('products')
            ->leftJoin('product_stocks', 'products.id', '=', 'product_stocks.product_id')
            ->select(
                'products.id',
                'products.name',
                'products.quantity as products_quantity',
                DB::raw('COALESCE(SUM(product_stocks.quantity), 0) as stocks_sum'),
                DB::raw('COUNT(product_stocks.id) as stocks_count')
            )
            ->whereNull('products.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.quantity')
            ->havingRaw('products.quantity != COALESCE(SUM(product_stocks.quantity), 0)')
            ->get();

        if ($desynced->isEmpty()) {
            $this->info('No se encontraron desincronizaciones. Todo el stock está consistente.');
            return Command::SUCCESS;
        }

        $this->info("Se encontraron {$desynced->count()} producto(s) con desincronización:");

        $tableRows = [];
        $totalFixed = 0;

        foreach ($desynced as $row) {
            $stocksCount = (int) $row->stocks_count;
            $productsQty = (int) $row->products_quantity;
            $stocksSum   = (int) $row->stocks_sum;

            if ($stocksCount === 0 && $productsQty > 0) {
                // Case 2: No product_stocks rows — create one at default location
                $action = "CREAR fila product_stocks (qty={$productsQty}) en ubicación por defecto";

                $tableRows[] = [
                    $row->id,
                    mb_strimwidth($row->name, 0, 40, '…'),
                    $productsQty,
                    $stocksSum,
                    $action,
                ];

                if (!$isDryRun) {
                    DB::transaction(function () use ($row, $productsQty) {
                        $defaultLocationId = $this->stockService->defaultLocationId();
                        ProductStock::create([
                            'product_id'  => $row->id,
                            'location_id' => $defaultLocationId,
                            'quantity'    => $productsQty,
                        ]);
                        $this->line("  Producto {$row->id} ({$row->name}): creando fila product_stocks con cantidad {$productsQty} en ubicación {$defaultLocationId}");
                    });
                }
            } else {
                // Case 3: Rows exist but sum doesn't match — trust product_stocks as source of truth
                $action = "CORREGIR products.quantity de {$productsQty} → {$stocksSum} (suma de ubicaciones)";

                $tableRows[] = [
                    $row->id,
                    mb_strimwidth($row->name, 0, 40, '…'),
                    $productsQty,
                    $stocksSum,
                    $action,
                ];

                if (!$isDryRun) {
                    DB::transaction(function () use ($row, $productsQty, $stocksSum) {
                        $this->stockService->syncProductQuantity($row->id);
                        $this->line("  Producto {$row->id} ({$row->name}): corrigiendo products.quantity de {$productsQty} a {$stocksSum} (suma de ubicaciones)");
                    });
                }
            }

            $totalFixed++;
        }

        $this->table(
            ['ID', 'Nombre', 'products.qty', 'stocks sum', 'Acción'],
            $tableRows
        );

        if ($isDryRun) {
            $this->warn("Total: {$totalFixed} corrección(es) pendiente(s). Ejecuta sin --dry-run para aplicar.");
        } else {
            $this->info("Total: {$totalFixed} corrección(es) aplicada(s) exitosamente.");
        }

        return Command::SUCCESS;
    }
}
