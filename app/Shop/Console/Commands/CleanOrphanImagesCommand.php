<?php

namespace App\Shop\Console\Commands;

use App\Models\Product;
use App\Shop\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Limpia paths de imágenes que apuntan a archivos inexistentes en disk.
 *
 * Caso típico: deploy fresco sin volume mount → la BD migra desde otro entorno
 * con paths como products/12/uuid_full.webp, pero esos archivos no existen
 * en storage/app/public del nuevo contenedor. El frontend muestra el alt-text
 * con icono roto hasta que limpiemos los paths.
 *
 * Este comando borra:
 *  - products.image_path cuyo archivo NO existe en storage/public.
 *  - Rows de product_images cuyo path principal NO existe en storage/public.
 *
 * Después del cleanup, los productos vuelven a renderizar el placeholder SVG
 * (via Product::getCardImageUrlAttribute fallback). El admin puede re-subir
 * imágenes desde el form.
 *
 * Uso:
 *   php artisan shop:clean-orphan-images          # ejecuta
 *   php artisan shop:clean-orphan-images --dry-run # solo reporta
 */
class CleanOrphanImagesCommand extends Command
{
    protected $signature = 'shop:clean-orphan-images
        {--dry-run : Listar lo que se limpiaría sin tocar BD}';

    protected $description = 'Limpia referencias a imágenes que ya no existen en el disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        $stats = [
            'products_legacy_cleared' => 0,
            'product_images_deleted'  => 0,
            'kept'                    => 0,
        ];

        // ── 1. products.image_path (campo legacy) ────────────────────────────
        $this->info('Revisando products.image_path…');
        Product::query()
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->chunkById(200, function ($products) use ($disk, $dryRun, &$stats) {
                foreach ($products as $product) {
                    if ($disk->exists($product->image_path)) {
                        $stats['kept']++;
                        continue;
                    }
                    $this->line("  ✗ orfan #{$product->id} ({$product->name}): {$product->image_path}");
                    if (! $dryRun) {
                        $product->update(['image_path' => null]);
                    }
                    $stats['products_legacy_cleared']++;
                }
            });

        // ── 2. product_images (galería Bloque C) ─────────────────────────────
        $this->newLine();
        $this->info('Revisando product_images…');
        ProductImage::query()
            ->chunkById(200, function ($images) use ($disk, $dryRun, &$stats) {
                foreach ($images as $img) {
                    // Considerar orfan si el path canonical no existe.
                    if ($disk->exists($img->path)) {
                        $stats['kept']++;
                        continue;
                    }
                    $this->line("  ✗ orfan ProductImage #{$img->id} (product #{$img->product_id}): {$img->path}");
                    if (! $dryRun) {
                        $img->delete();
                    }
                    $stats['product_images_deleted']++;
                }
            });

        $this->newLine();
        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribieron cambios.');
        }
        $this->info("products.image_path limpiados: {$stats['products_legacy_cleared']}");
        $this->info("product_images borrados: {$stats['product_images_deleted']}");
        $this->line("Conservados (archivo existe): {$stats['kept']}");

        return self::SUCCESS;
    }
}
