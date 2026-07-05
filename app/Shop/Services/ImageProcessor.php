<?php

namespace App\Shop\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Procesador de imágenes del producto. Genera 3 variantes WebP por upload:
 *   - thumb (200px) — para listados densos / carrito
 *   - card  (600px) — para grids del catálogo
 *   - full  (1200px) — para detalle producto / lightbox
 *
 * API Intervention/Image v4.1: decode(source) + encode(WebpEncoder).
 * scaleDown solo reduce (no upscalea si la imagen original ya es chica).
 *
 * Output: array con paths relativos al disk public.
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
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     *
     * @throws \RuntimeException con mensaje friendly si formato no soportado
     */
    public function processForProduct(UploadedFile $file, int $productId): array
{
    $extension = strtolower($file->getClientOriginalExtension());
    $fileSize = round($file->getSize() / 1024 / 1024, 2);
    $fileName = $file->getClientOriginalName();

    // --- VALIDACIÓN PREVENTIVA DE MEGAPÍXELES ---
    $imageInfo = @getimagesize($file->getRealPath());
    if ($imageInfo) {
        $megapixels = ($imageInfo[0] * $imageInfo[1]) / 1000000;
        if ($megapixels > 40) {
            throw new \RuntimeException(
                "La imagen tiene {$megapixels}MP. Por favor, reduce la resolución " .
                "a menos de 40MP (ej. 6000x4000) o usa una herramienta de compresión."
            );
        }
        \Log::info('Image dimensions', [
            'product_id' => $productId,
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'megapixels' => round($megapixels, 1),
        ]);
    }
    // --- FIN VALIDACIÓN ---

    \Log::info('Processing product image', [
        'product_id' => $productId,
        'file' => $fileName,
        'size_mb' => $fileSize,
        'extension' => $extension,
        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
    ]);

    try {
        $source = Image::decode($file->getRealPath());
    } catch (\Throwable $e) {
        // ... manejador existente
    }

    return $this->encodeVariants($source, $productId);
}
    /**
     * Re-procesa una imagen YA almacenada (no upload) para generar variantes faltantes.
     * Usado por el comando shop:regenerate-images para backfill de imágenes legacy.
     *
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     */
    public function processExisting(string $existingPath, int $productId): array
    {
        if (! Storage::disk('public')->exists($existingPath)) {
            throw new \RuntimeException("Imagen no encontrada en disk: {$existingPath}");
        }

        $absolutePath = Storage::disk('public')->path($existingPath);
        $source = Image::decode($absolutePath);

        return $this->encodeVariants($source, $productId);
    }

    /**
     * Genera las 3 variantes WebP a partir de un Image::decode() ya hecho.
     * Reutilizado por processForProduct y processExisting.
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $source
     */
    private function encodeVariants($source, int $productId): array
{
    $basePath = "products/{$productId}";
    $filename = (string) Str::uuid();
    $paths = [];

    // --- FULL (1200px) ---
    $fullImg = clone $source;
    $fullImg->scaleDown(width: self::VARIANTS['full']);
    $encoded = $fullImg->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
    $relativePath = "{$basePath}/{$filename}_full.webp";
    Storage::disk('public')->put($relativePath, (string) $encoded);
    $paths['path_full'] = $relativePath;
    unset($encoded); // Liberar early

    // --- CARD (600px) ---
    $cardImg = clone $fullImg;
    $cardImg->scaleDown(width: self::VARIANTS['card']);
    $encoded = $cardImg->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
    $relativePath = "{$basePath}/{$filename}_card.webp";
    Storage::disk('public')->put($relativePath, (string) $encoded);
    $paths['path_card'] = $relativePath;
    unset($cardImg, $encoded); // Liberar early

    // --- THUMB (200px) - usar cardImg (más pequeña) en lugar de fullImg ---
    // Pero cardImg ya fue liberada, así que clonamos fullImg de nuevo
    // OPCIÓN A: Usar fullImg (como tienes ahora) - consume más memoria pero es más simple
    // OPCIÓN B: Reutilizar cardImg antes de liberarla
    $thumbImg = clone $fullImg; // Mantener como está, es correcto
    $thumbImg->scaleDown(width: self::VARIANTS['thumb']);
    $encoded = $thumbImg->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
    $relativePath = "{$basePath}/{$filename}_thumb.webp";
    Storage::disk('public')->put($relativePath, (string) $encoded);
    $paths['path_thumb'] = $relativePath;
    unset($thumbImg, $encoded);

    // Liberar fullImg al final
    unset($fullImg);

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
