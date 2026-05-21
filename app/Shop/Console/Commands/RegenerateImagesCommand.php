<?php

namespace App\Shop\Console\Commands;

use App\Models\Product;
use App\Shop\Models\ProductImage;
use App\Shop\Services\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Regenera variantes WebP (thumb/card/full) para imágenes legacy.
 *
 * Casos cubiertos:
 *   - Productos con products.image_path pero sin row en product_images.
 *   - Productos con rows en product_images sin paths de variantes (path_thumb,
 *     path_card, path_full vacíos). Originados por backfill de la migración.
 *   - Productos nuevos con galería ya procesada → se saltan (idempotente).
 *
 * Uso:
 *   php artisan shop:regenerate-images
 *   php artisan shop:regenerate-images --product=12     # solo uno
 *   php artisan shop:regenerate-images --dry-run        # solo reportar
 */
class RegenerateImagesCommand extends Command
{
    protected $signature = 'shop:regenerate-images
        {--product= : Solo procesar este product_id}
        {--dry-run : Listar lo que se procesaría sin escribir archivos}';

    protected $description = 'Genera variantes WebP (thumb/card/full) para imágenes de productos legacy';

    public function handle(ImageProcessor $processor): int
    {
        $productId = $this->option('product');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribirán archivos.');
        }

        $stats = ['processed' => 0, 'skipped' => 0, 'failed' => 0];

        $query = Product::query()->with('images');
        if ($productId) {
            $query->where('id', $productId);
        }

        $query->chunkById(50, function ($products) use ($processor, $dryRun, &$stats) {
            foreach ($products as $product) {
                $stats = $this->processProduct($product, $processor, $dryRun, $stats);
            }
        });

        $this->newLine();
        $this->info("Procesadas: {$stats['processed']}");
        $this->line("Saltadas (ya tenían variantes): {$stats['skipped']}");
        if ($stats['failed'] > 0) {
            $this->error("Fallidas: {$stats['failed']}");
        }

        return self::SUCCESS;
    }

    private function processProduct(Product $product, ImageProcessor $processor, bool $dryRun, array $stats): array
    {
        // Caso A: product_images existentes pero sin variantes.
        foreach ($product->images as $image) {
            if ($image->path_thumb && $image->path_card && $image->path_full) {
                $stats['skipped']++;
                continue;
            }

            if (! $image->path) {
                $this->warn("  ⚠️  ProductImage #{$image->id} sin path original, skip.");
                $stats['failed']++;
                continue;
            }

            if (! Storage::disk('public')->exists($image->path)) {
                $this->warn("  ⚠️  Archivo no encontrado: {$image->path} (ProductImage #{$image->id})");
                $stats['failed']++;
                continue;
            }

            $this->line("Regenerando ProductImage #{$image->id} (producto #{$product->id})...");

            if ($dryRun) {
                $stats['processed']++;
                continue;
            }

            try {
                $paths = $processor->processExisting($image->path, $product->id);
                $image->update([
                    'path_thumb' => $paths['path_thumb'],
                    'path_card' => $paths['path_card'],
                    'path_full' => $paths['path_full'],
                ]);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                $stats['failed']++;
            }
        }

        // Caso B: product.image_path legacy sin row en product_images (no debería pasar
        // si la migración corrió, pero defensivo por si fueron creados después).
        if ($product->image_path && $product->images->isEmpty()) {
            $this->line("Backfilling product #{$product->id} (legacy image_path → product_images)...");

            if ($dryRun) {
                $stats['processed']++;
                return $stats;
            }

            try {
                if (! Storage::disk('public')->exists($product->image_path)) {
                    $this->warn("  ⚠️  Archivo legacy no encontrado: {$product->image_path}");
                    $stats['failed']++;
                    return $stats;
                }

                $paths = $processor->processExisting($product->image_path, $product->id);
                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $paths['path'],
                    'path_thumb' => $paths['path_thumb'],
                    'path_card' => $paths['path_card'],
                    'path_full' => $paths['path_full'],
                    'sort_order' => 0,
                    'is_primary' => true,
                ]);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
