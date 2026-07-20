<?php

namespace App\Shop\Seo;

use App\Models\Product;
use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Arma los metadatos de cada página pública aplicando cadenas de respaldo, de modo
 * que la tienda siempre se comparta con algo razonable sin obligar a configurar nada.
 *
 * Las URLs de imagen SIEMPRE salen absolutas: Storage::url() puede devolver una ruta
 * relativa según el disco configurado, y WhatsApp/Facebook descartan las imágenes
 * relativas sin avisar. url() detecta cuando la URL ya es absoluta y la deja tal cual,
 * así que envolverla con url() es seguro sin importar cómo esté configurado el disco.
 */
class ShareMetaBuilder
{
    private const DESCRIPTION_LIMIT = 200;

    public function forLanding(): ShareMeta
    {
        return new ShareMeta(
            title: $this->landingTitle(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.index'),
        );
    }

    public function forCatalog(): ShareMeta
    {
        return new ShareMeta(
            title: 'Catálogo · ' . $this->businessName(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.catalog'),
        );
    }

    public function forProduct(Product $product): ShareMeta
    {
        $description = $this->clean($product->description ?? '');

        return new ShareMeta(
            title: $product->name,
            description: $description !== '' ? $description : $this->landingDescription(),
            imageUrl: $this->productImageUrl($product) ?? $this->landingImageUrl(),
            url: route('shop.product', $product->slug),
            type: 'product',
        );
    }

    public function forCheckout(): ShareMeta
    {
        return new ShareMeta(
            title: 'Reservar · ' . $this->businessName(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.checkout'),
            noindex: true, // un carrito no tiene por qué estar en Google
        );
    }

    public function businessName(): string
    {
        return Setting::get('shop_business_name') ?: (string) config('app.name');
    }

    private function landingTitle(): string
    {
        return $this->nonEmpty(Setting::get('shop_share_title')) ?? $this->businessName();
    }

    private function landingDescription(): string
    {
        return $this->clean(
            $this->nonEmpty(Setting::get('shop_share_description'))
            ?? $this->nonEmpty($this->heroData()['subheading'] ?? null)
            ?? $this->nonEmpty(Setting::get('shop_welcome_message'))
            ?? ''
        );
    }

    private function landingImageUrl(): ?string
    {
        $path = $this->nonEmpty(Setting::get('shop_share_image_path'))
            ?? $this->nonEmpty($this->heroData()['background_image_path'] ?? null)
            ?? $this->nonEmpty(Setting::get('shop_logo_path'));

        return $this->absoluteFromPath($path);
    }

    private function productImageUrl(Product $product): ?string
    {
        $image = $product->primaryImage;

        $path = $image?->path_full
            ?: $image?->path_card
            ?: $image?->path
            ?: $product->image_path;

        return $this->absoluteFromPath($this->nonEmpty($path));
    }

    /** `data` de la primera sección hero habilitada, o [] si no hay. */
    private function heroData(): array
    {
        $hero = LandingSection::query()
            ->enabled()
            ->ordered()
            ->where('type', 'hero')
            ->first();

        return $hero?->data ?? [];
    }

    /** Ruta de disco → URL absoluta. Storage::url() puede devolver una ruta relativa. */
    private function absoluteFromPath(?string $path): ?string
    {
        return $path ? url(Storage::url($path)) : null;
    }

    private function clean(?string $text): string
    {
        return Str::limit(trim(strip_tags((string) $text)), self::DESCRIPTION_LIMIT, '');
    }

    private function nonEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
