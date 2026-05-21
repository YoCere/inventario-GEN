<?php

namespace App\Shop\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Procesador de imágenes del producto. Genera 3 variantes WebP por upload:
 *   - thumb (200px) — para listados densos / carrito
 *   - card  (600px) — para grids del catálogo
 *   - full  (1200px) — para detalle producto / lightbox
 *
 * Toda la entrega es WebP @ 82 quality (ratio compresión / calidad bueno
 * para fotos producto). Si el upload es <= al tamaño objetivo, NO upscalea
 * (scaleDown solo reduce).
 *
 * Output: array con paths relativos al disk public:
 *   [
 *     'path' => 'products/{id}/uuid_full.webp',  // canonical
 *     'path_thumb' => 'products/{id}/uuid_thumb.webp',
 *     'path_card'  => 'products/{id}/uuid_card.webp',
 *     'path_full'  => 'products/{id}/uuid_full.webp',
 *   ]
 */
class ImageProcessor
{
    private const VARIANTS = [
        'thumb' => 200,
        'card'  => 600,
        'full'  => 1200,
    ];

    private const WEBP_QUALITY = 82;

    /**
     * Procesa un upload Livewire/HTTP y persiste las 3 variantes en el disk public.
     *
     * @param UploadedFile $file
     * @param int $productId
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     */
    public function processForProduct(UploadedFile $file, int $productId): array
    {
        $basePath = "products/{$productId}";
        $filename = (string) Str::uuid();
        $paths = [];

        foreach (self::VARIANTS as $variant => $width) {
            $img = Image::read($file->getRealPath())
                ->scaleDown(width: $width)
                ->toWebp(quality: self::WEBP_QUALITY);

            $relativePath = "{$basePath}/{$filename}_{$variant}.webp";
            Storage::disk('public')->put($relativePath, (string) $img);
            $paths["path_{$variant}"] = $relativePath;
        }

        return [
            'path' => $paths['path_full'], // canonical
            ...$paths,
        ];
    }

    /**
     * Re-procesa una imagen YA almacenada (no upload) para generar variantes faltantes.
     * Usado por el comando shop:regenerate-images para backfill de imágenes legacy.
     *
     * @param string $existingPath path relativo al disk public (ej. 'products/12/foo.jpg')
     * @param int $productId
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     *
     * @throws \RuntimeException si el archivo no existe en el disk
     */
    public function processExisting(string $existingPath, int $productId): array
    {
        if (! Storage::disk('public')->exists($existingPath)) {
            throw new \RuntimeException("Imagen no encontrada en disk: {$existingPath}");
        }

        $absolutePath = Storage::disk('public')->path($existingPath);
        $basePath = "products/{$productId}";
        $filename = (string) Str::uuid();
        $paths = [];

        foreach (self::VARIANTS as $variant => $width) {
            $img = Image::read($absolutePath)
                ->scaleDown(width: $width)
                ->toWebp(quality: self::WEBP_QUALITY);

            $relativePath = "{$basePath}/{$filename}_{$variant}.webp";
            Storage::disk('public')->put($relativePath, (string) $img);
            $paths["path_{$variant}"] = $relativePath;
        }

        return [
            'path' => $paths['path_full'],
            ...$paths,
        ];
    }

    /**
     * Borra las 3 variantes de un ProductImage del disk. No toca la BD —
     * el caller debe eliminar el row aparte (o lo hace cascadeOnDelete del FK).
     */
    public function deleteVariants(array $paths): void
    {
        $disk = Storage::disk('public');
        foreach (['path', 'path_thumb', 'path_card', 'path_full'] as $key) {
            if (! empty($paths[$key]) && $disk->exists($paths[$key])) {
                $disk->delete($paths[$key]);
            }
        }
    }
}
