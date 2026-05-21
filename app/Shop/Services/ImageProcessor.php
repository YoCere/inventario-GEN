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

        try {
            $source = Image::decode($file->getRealPath());
        } catch (\Throwable $e) {
            if (\in_array($extension, ['heic', 'heif'], true) && ! extension_loaded('imagick')) {
                throw new \RuntimeException(
                    "Formato HEIC/HEIF no soportado en este servidor. " .
                    "Instala la extensión PHP Imagick o convierte la imagen a JPG/PNG antes de subir."
                );
            }
            throw new \RuntimeException("No se pudo leer la imagen ({$extension}): {$e->getMessage()}");
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

        foreach (self::VARIANTS as $variant => $width) {
            // clone evita mutar el source entre variantes (si scaleDown muta).
            $variantImg = (clone $source)->scaleDown(width: $width);
            $encoded = $variantImg->encode(new WebpEncoder(quality: self::WEBP_QUALITY));

            $relativePath = "{$basePath}/{$filename}_{$variant}.webp";
            Storage::disk('public')->put($relativePath, (string) $encoded);
            $paths["path_{$variant}"] = $relativePath;
        }

        return [
            'path' => $paths['path_full'], // canonical
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
