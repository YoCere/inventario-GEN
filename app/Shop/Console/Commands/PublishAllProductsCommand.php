<?php

namespace App\Shop\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Marca productos como visibles en la tienda pública (`is_public=true`).
 *
 * Por default actúa sobre todos los `is_active=true`. Útil para arranque
 * inicial del Shop module — el admin después desmarca los que no quiere
 * mostrar individualmente desde el form.
 *
 * Uso:
 *   php artisan shop:publish-all                      # todos los activos
 *   php artisan shop:publish-all --including-inactive # también los is_active=false
 *   php artisan shop:publish-all --dry-run            # reportar sin escribir
 *   php artisan shop:unpublish-all                    # comando inverso (otro archivo)
 */
class PublishAllProductsCommand extends Command
{
    protected $signature = 'shop:publish-all
        {--including-inactive : Marcar también productos con is_active=false}
        {--dry-run : Solo contar lo que se publicaría sin escribir}';

    protected $description = 'Marca todos los productos como visibles en /tienda (is_public=true)';

    public function handle(): int
    {
        $query = Product::query();

        if (! $this->option('including-inactive')) {
            $query->where('is_active', true);
        }

        $candidates = (clone $query)->where('is_public', false)->count();
        $alreadyPublic = (clone $query)->where('is_public', true)->count();

        if ($candidates === 0) {
            $this->info("Todos los productos ya están publicados ({$alreadyPublic} en total).");
            return self::SUCCESS;
        }

        $this->line("Productos por publicar: {$candidates}");
        $this->line("Ya publicados: {$alreadyPublic}");

        if ($this->option('dry-run')) {
            $this->warn('Modo dry-run: no se escribirán cambios.');
            return self::SUCCESS;
        }

        $updated = $query->where('is_public', false)->update(['is_public' => true]);

        // Invalidar caches del Shop module así el catálogo refleja al instante.
        Cache::forget('shop.categories_with_public_products');
        Cache::forget('shop.price_range');

        $this->info("✓ {$updated} producto(s) marcados como is_public=true.");
        return self::SUCCESS;
    }
}
