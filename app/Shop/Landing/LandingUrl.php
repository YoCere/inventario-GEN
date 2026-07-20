<?php

namespace App\Shop\Landing;

use App\Models\Setting;

/**
 * Resuelve/valida URLs de las secciones de la landing. La landing es pública y
 * (en SP2) editable por admins → validar esquema evita hrefs javascript: y rutas
 * de imagen que rompan el CSS inline. Defensa en profundidad, igual que el sanitizer.
 */
class LandingUrl
{
    /** Resuelve el target de un CTA: 'catalog' | 'whatsapp' | URL http(s)/relativa. */
    public static function target(?string $target): string
    {
        return match ($target) {
            'catalog', null, '' => route('shop.catalog'),
            'whatsapp' => 'https://wa.me/' . preg_replace('/\D/', '', (string) Setting::get('shop_whatsapp_number', '')),
            default => self::safeUrl($target),
        };
    }

    /** Solo permite http(s) absolutas o rutas relativas ('/...'); cualquier otra cosa → catálogo. */
    public static function safeUrl(?string $url): string
    {
        return self::isSafeUrl($url) ? trim((string) $url) : route('shop.catalog');
    }

    /**
     * Valida sin resolver rutas: true solo para http(s) absolutas o rutas relativas ('/...').
     * Puro a propósito — el editor corre en admin, donde route('shop.catalog') puede no existir
     * (las rutas de la tienda solo se registran con shop_enabled='1').
     */
    public static function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        return $url !== '' && (str_starts_with($url, '/') || (bool) preg_match('#^https?://#i', $url));
    }

    /**
     * Devuelve la ruta de almacenamiento solo si es segura para interpolar en un
     * url(...) de CSS inline (sin espacios, comillas, paréntesis, ; { } \). Si no, null.
     */
    public static function safeStoragePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('/[\s"\'()\\\\;{}]/', $path)) {
            return null;
        }
        return $path;
    }
}
