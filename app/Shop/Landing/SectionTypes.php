<?php

namespace App\Shop\Landing;

/**
 * Fuente única de los tipos de sección de la landing: label, partial de render
 * y data por defecto. El editor (SP2) leerá este registry para ofrecer tipos y
 * armar formularios. El render (shop.landing) lo usa para validar y ubicar partials.
 */
class SectionTypes
{
    /** type => [label, partial, default, form, rules] */
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
                'form' => 'settings.landing.forms.hero',
                'rules' => [
                    'heading' => ['required', 'string', 'max:120'],
                    'subheading' => ['nullable', 'string', 'max:200'],
                    'cta_text' => ['nullable', 'string', 'max:40'],
                    'cta_target' => ['nullable', 'string', 'max:255'],
                    'background_image_path' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'about' => [
                'label' => 'Acerca / Historia',
                'partial' => 'shop.landing.sections.about',
                'default' => [
                    'heading' => 'Quiénes somos',
                    'body_html' => '<p>Cuéntale a tus clientes tu historia.</p>',
                ],
                'form' => 'settings.landing.forms.about',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'body_html' => ['nullable', 'string', 'max:20000'],
                    'image_path' => ['nullable', 'string', 'max:255'],
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
                'form' => 'settings.landing.forms.hours',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'rows' => ['array', 'max:20'],
                    'rows.*.label' => ['required', 'string', 'max:60'],
                    'rows.*.value' => ['required', 'string', 'max:60'],
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
                'form' => 'settings.landing.forms.categories',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'source' => ['required', 'in:auto,manual'],
                    'items' => ['array', 'max:20'],
                    'items.*.label' => ['required', 'string', 'max:60'],
                    'items.*.link' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'gallery' => [
                'label' => 'Galería',
                'partial' => 'shop.landing.sections.gallery',
                'default' => [
                    'heading' => 'Galería',
                    'images' => [],
                ],
                'form' => 'settings.landing.forms.gallery',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'images' => ['array', 'max:24'],
                    'images.*' => ['string', 'max:255'],
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
                'form' => 'settings.landing.forms.contact',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'whatsapp' => ['nullable', 'string', 'max:30'],
                    'address' => ['nullable', 'string', 'max:200'],
                    'email' => ['nullable', 'email', 'max:120'],
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
                'form' => 'settings.landing.forms.cta',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'text' => ['nullable', 'string', 'max:200'],
                    'button_text' => ['required', 'string', 'max:40'],
                    'target' => ['nullable', 'string', 'max:255'],
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

    public static function form(string $type): ?string
    {
        return self::map()[$type]['form'] ?? null;
    }

    /** @return array<string, array<int,string>> reglas keyed por campo del `data` */
    public static function rules(string $type): array
    {
        return self::map()[$type]['rules'] ?? [];
    }
}
