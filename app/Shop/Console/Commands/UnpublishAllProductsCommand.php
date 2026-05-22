<?php

namespace App\Shop\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Inverso de shop:publish-all. Saca todos los productos del catálogo público
 * en una sola operación. Útil para volver al modo curado-manual o para
 * preparar un re-lanzamiento.
 *
 * Uso:
 *   php artisan shop:unpublish-all          # todos
 *   php artisan shop:unpublish-all --dry-run
 */
class UnpublishAllProductsCommand extends Command
{
    protected $signature = 'shop:unpublish-all
        {--dry-run : Solo contar lo que se ocultaría sin escribir}';

    protected $description = 'Quita TODOS los productos del catálogo público (is_public=false)';

    public function handle(): int
    {
        $candidates = Product::query()->where('is_public', true)->count();

        if ($candidates === 0) {
            $this->info('No hay productos públicos para ocultar.');
            return self::SUCCESS;
        }

        $this->warn("Productos por ocultar: {$candidates}");

        if ($this->option('dry-run')) {
            $this->warn('Modo dry-run: no se escribirán cambios.');
            return self::SUCCESS;
        }

        if (! $this->confirm("¿Confirmas ocultar {$candidates} producto(s) del catálogo público?", false)) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        $updated = Product::query()->where('is_public', true)->update(['is_public' => false]);

        Cache::forget('shop.categories_with_public_products');
        Cache::forget('shop.price_range');

        $this->info("✓ {$updated} producto(s) ocultados del catálogo público.");
        return self::SUCCESS;
    }
}
