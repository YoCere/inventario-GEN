<?php

namespace App\Shop\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
 * Regla de oro de esta clase: **nunca** dejar que un problema con una imagen
 * se convierta en un error 500. Todo lo que puede fallar (formato que GD no
 * entiende, foto enorme que no entra en memoria, servidor sin WebP) se detecta
 * antes y sale como `RuntimeException` con un mensaje que la dueña entienda.
 * Un fatal por falta de memoria no se puede atrapar, así que se estima el
 * consumo antes de abrir la imagen.
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

    /** Tope duro de resolución: arriba de esto ninguna cámara de celular normal llega. */
    private const MAX_MEGAPIXELES = 40;

    /**
     * Bytes por píxel que hay que reservar para abrir + clonar la imagen.
     * GD guarda 4 bytes por píxel y durante el encode conviven el original y
     * una copia escalada, así que se reserva con holgura.
     */
    private const BYTES_POR_PIXEL = 10;

    /**
     * Procesa un upload Livewire/HTTP y persiste las 3 variantes en el disk public.
     *
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     *
     * @throws \RuntimeException con mensaje friendly si el archivo no se puede procesar
     */
    public function processForProduct(UploadedFile $file, int $productId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $ruta = (string) $file->getRealPath();

        $contexto = [
            'product_id' => $productId,
            'file' => $file->getClientOriginalName(),
            'size_mb' => $file->getSize() ? round($file->getSize() / 1024 / 1024, 2) : null,
            'extension' => $extension,
            'memoria_usada_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_limit' => ini_get('memory_limit'),
        ];

        $source = $this->abrirImagen($ruta, $extension, $contexto);

        return $this->encodeVariants($source, $productId);
    }

    /**
     * Re-procesa una imagen YA almacenada (no upload) para generar variantes faltantes.
     * Usado por el comando shop:regenerate-images para backfill de imágenes legacy
     * y por el alta de productos desde el bot de Telegram.
     *
     * @return array{path:string,path_thumb:string,path_card:string,path_full:string}
     */
    public function processExisting(string $existingPath, int $productId): array
    {
        if (! Storage::disk('public')->exists($existingPath)) {
            throw new \RuntimeException("Imagen no encontrada en disk: {$existingPath}");
        }

        $absolutePath = Storage::disk('public')->path($existingPath);
        $extension = strtolower((string) pathinfo($existingPath, PATHINFO_EXTENSION));

        $source = $this->abrirImagen($absolutePath, $extension, [
            'product_id' => $productId,
            'file' => $existingPath,
        ]);

        return $this->encodeVariants($source, $productId);
    }

    /**
     * Abre la imagen dejando fuera, con mensaje claro, todo lo que haría caer
     * el request: archivo que no es imagen, resolución absurda, o foto que no
     * entra en la memoria disponible.
     *
     * @param  array<string, mixed>  $contexto  datos para el log si algo falla
     * @return \Intervention\Image\Interfaces\ImageInterface
     */
    private function abrirImagen(string $ruta, string $extension, array $contexto)
    {
        $this->asegurarSoporteWebp();

        $info = $ruta !== '' ? @getimagesize($ruta) : false;

        if ($info === false) {
            Log::warning('Imagen de producto ilegible para GD', $contexto);

            throw new \RuntimeException($this->mensajeDeFormato($extension));
        }

        [$ancho, $alto] = $info;
        $megapixeles = round(($ancho * $alto) / 1000000, 1);

        Log::info('Procesando imagen de producto', $contexto + [
            'ancho' => $ancho,
            'alto' => $alto,
            'megapixeles' => $megapixeles,
        ]);

        if ($megapixeles > self::MAX_MEGAPIXELES) {
            throw new \RuntimeException(
                "La foto tiene {$megapixeles} megapíxeles y es demasiado grande para procesarla. " .
                'Sacala de nuevo con menos resolución o reducila antes de subirla.'
            );
        }

        $this->asegurarMemoria($ancho, $alto, $megapixeles, $contexto);

        try {
            return Image::decode($ruta);
        } catch (\Throwable $e) {
            Log::error('No se pudo abrir la imagen del producto', $contexto + [
                'error' => $e->getMessage(),
                'excepcion' => $e::class,
            ]);

            throw new \RuntimeException($this->mensajeDeFormato($extension), 0, $e);
        }
    }

    /**
     * Un decode de 4000x3000 pide ~120 MB. Si no hay tanto libre, PHP muere con
     * un fatal que ningún try/catch atrapa y el usuario ve un 500. Mejor avisarle.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function asegurarMemoria(int $ancho, int $alto, float $megapixeles, array $contexto): void
    {
        $limite = $this->limiteDeMemoriaEnBytes();

        if ($limite === null) {
            return; // Sin tope configurado: no hay nada que estimar.
        }

        $necesario = $ancho * $alto * self::BYTES_POR_PIXEL;
        $libre = $limite - memory_get_usage(true);

        if ($necesario <= $libre) {
            return;
        }

        Log::warning('Imagen de producto rechazada por memoria insuficiente', $contexto + [
            'megapixeles' => $megapixeles,
            'necesario_mb' => round($necesario / 1024 / 1024),
            'libre_mb' => round($libre / 1024 / 1024),
        ]);

        throw new \RuntimeException(
            "La foto de {$megapixeles} megapíxeles es demasiado pesada para el servidor. " .
            'Subila con menos resolución, o mandala por el bot de Telegram.'
        );
    }

    /** Null cuando no hay tope (memory_limit = -1). */
    private function limiteDeMemoriaEnBytes(): ?int
    {
        $valor = trim((string) ini_get('memory_limit'));

        if ($valor === '' || $valor === '-1') {
            return null;
        }

        $unidad = strtolower(substr($valor, -1));
        $numero = (int) $valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * Si el PHP desplegado quedó sin WebP, TODA foto falla siempre. Conviene
     * decirlo con esas palabras en vez de dejar suelto un error de librería.
     */
    private function asegurarSoporteWebp(): void
    {
        $gd = function_exists('gd_info') ? gd_info() : [];

        if (! empty($gd['WebP Support']) || class_exists(\Imagick::class)) {
            return;
        }

        Log::error('El servidor no puede generar WebP: falta soporte en GD e Imagick');

        throw new \RuntimeException(
            'El servidor no puede convertir las fotos al formato del catálogo. ' .
            'Avisale a soporte: falta el soporte WebP en el servidor.'
        );
    }

    private function mensajeDeFormato(string $extension): string
    {
        if (in_array($extension, ['heic', 'heif'], true)) {
            return 'Esa foto está en formato HEIC (el de iPhone) y el servidor no lo puede abrir. ' .
                'Mandala desde el bot de Telegram, o en el iPhone poné Ajustes → Cámara → Formatos → Más compatible.';
        }

        return 'No pudimos abrir ese archivo como imagen. Probá con una foto en JPG o PNG.';
    }

    /**
     * Genera las 3 variantes WebP a partir de un Image::decode() ya hecho.
     * Reutilizado por processForProduct y processExisting.
     *
     * @param  \Intervention\Image\Interfaces\ImageInterface  $source
     */
    private function encodeVariants($source, int $productId): array
    {
        $basePath = "products/{$productId}";
        $filename = (string) Str::uuid();
        $paths = [];

        // La más grande primero: las chicas se derivan de ella y no del original,
        // así se escala sobre menos píxeles (menos memoria, más rápido).
        $fullImg = clone $source;
        $fullImg->scaleDown(width: self::VARIANTS['full']);
        $paths['path_full'] = $this->guardarWebp($fullImg, "{$basePath}/{$filename}_full.webp");

        $cardImg = clone $fullImg;
        $cardImg->scaleDown(width: self::VARIANTS['card']);
        $paths['path_card'] = $this->guardarWebp($cardImg, "{$basePath}/{$filename}_card.webp");

        $thumbImg = clone $cardImg;
        $thumbImg->scaleDown(width: self::VARIANTS['thumb']);
        $paths['path_thumb'] = $this->guardarWebp($thumbImg, "{$basePath}/{$filename}_thumb.webp");

        unset($thumbImg, $cardImg, $fullImg);

        return [
            'path' => $paths['path_full'],
            ...$paths,
        ];
    }

    /** @param \Intervention\Image\Interfaces\ImageInterface $imagen */
    private function guardarWebp($imagen, string $relativePath): string
    {
        $encoded = $imagen->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
        Storage::disk('public')->put($relativePath, (string) $encoded);
        unset($encoded); // Liberar antes de la variante siguiente.

        return $relativePath;
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
