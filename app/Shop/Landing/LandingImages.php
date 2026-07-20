<?php

namespace App\Shop\Landing;

use App\Shop\Models\LandingSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Guarda y borra las imágenes de las secciones de la landing. Centralizado para
 * que borrar una sección no deje archivos huérfanos en el disco.
 */
class LandingImages
{
    /** Campos del `data` que contienen UNA ruta de imagen. */
    private const SINGLE_KEYS = ['background_image_path', 'image_path'];

    /** Campos del `data` que contienen una LISTA de rutas. */
    private const LIST_KEYS = ['images'];

    public function store(UploadedFile $file): string
    {
        return $file->store('shop/landing', 'public');
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Throwable $e) {
            // El registro en DB manda: un fallo de disco no aborta la operación.
            Log::warning('No se pudo borrar imagen de landing', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** Borra todas las imágenes referenciadas por el `data` de una sección. */
    public function deleteForSection(LandingSection $section): void
    {
        $data = $section->data ?? [];

        foreach (self::SINGLE_KEYS as $key) {
            $this->delete($data[$key] ?? null);
        }

        foreach (self::LIST_KEYS as $key) {
            foreach ((array) ($data[$key] ?? []) as $path) {
                $this->delete(is_string($path) ? $path : null);
            }
        }
    }
}
