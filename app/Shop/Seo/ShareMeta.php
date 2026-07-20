<?php

namespace App\Shop\Seo;

/**
 * Metadatos de una página pública de la tienda para vistas previas al compartir
 * (Open Graph / Twitter Card) y para buscadores. Contenedor inmutable: toda la
 * lógica de respaldo vive en ShareMetaBuilder.
 */
class ShareMeta
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        /** URL ABSOLUTA de la imagen, o null si no hay ninguna. */
        public readonly ?string $imageUrl,
        /** URL ABSOLUTA canónica de la página. */
        public readonly string $url,
        public readonly string $type = 'website',
        public readonly bool $noindex = false,
    ) {}
}
