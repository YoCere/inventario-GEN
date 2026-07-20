<?php

namespace App\Shop\Landing;

/**
 * Fuente única de los tipos de sección de la landing: label, partial de render
 * y data por defecto. El editor (SP2) leerá este registry para ofrecer tipos y
 * armar formularios. El render (shop.landing) lo usa para validar y ubicar partials.
 */
class SectionTypes
{
    /** type => [label, partial, default] */
    private static function map(): array
    {
        return [
            'hero' => [
                'label' => 'Héroe',
                'partial' => 'shop.landing.sections.hero',
                'default' => [
                    'heading' => 'Bienvenido a nuestra tienda',
                    'subheading' => 'Descubre nuestros productos',
                    'cta_text' => 'Entrar a la tienda',
                    'cta_target' => 'catalog',
                ],
            ],
            'about' => [
                'label' => 'Acerca / Historia',
                'partial' => 'shop.landing.sections.about',
                'default' => [
                    'heading' => 'Quiénes somos',
                    'body_html' => '<p>Cuéntale a tus clientes tu historia.</p>',
                ],
            ],
            'hours' => [
                'label' => 'Horarios',
                'partial' => 'shop.landing.sections.hours',
                'default' => [
                    'heading' => 'Horarios de atención',
                    'rows' => [
                        ['label' => 'Lunes a Viernes', 'value' => '9:00 – 18:00'],
                        ['label' => 'Sábados', 'value' => '9:00 – 13:00'],
                    ],
                ],
            ],
            'categories' => [
                'label' => 'Qué vendemos',
                'partial' => 'shop.landing.sections.categories',
                'default' => [
                    'heading' => 'Qué vendemos',
                    'source' => 'auto',
                    'items' => [],
                ],
            ],
            'gallery' => [
                'label' => 'Galería',
                'partial' => 'shop.landing.sections.gallery',
                'default' => [
                    'heading' => 'Galería',
                    'images' => [],
                ],
            ],
            'contact' => [
                'label' => 'Contacto',
                'partial' => 'shop.landing.sections.contact',
                'default' => [
                    'heading' => 'Contacto',
                    'whatsapp' => '',
                    'address' => '',
                    'email' => '',
                ],
            ],
            'cta' => [
                'label' => 'Botón a la tienda',
                'partial' => 'shop.landing.sections.cta',
                'default' => [
                    'heading' => '¿Listo para comprar?',
                    'text' => 'Explora todo nuestro catálogo.',
                    'button_text' => 'Entrar a la tienda',
                    'target' => 'catalog',
                ],
            ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::map());
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::map());
    }

    public static function label(string $type): string
    {
        return self::map()[$type]['label'] ?? $type;
    }

    public static function partial(string $type): ?string
    {
        return self::map()[$type]['partial'] ?? null;
    }

    /** @return array<string,mixed> */
    public static function defaultData(string $type): array
    {
        return self::map()[$type]['default'] ?? [];
    }
}
