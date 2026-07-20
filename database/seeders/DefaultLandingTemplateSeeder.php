<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Database\Seeder;

class DefaultLandingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Activa la landing por defecto (solo si no está seteada).
        if (Setting::get('shop_landing_enabled') === null) {
            Setting::set('shop_landing_enabled', '1');
        }

        // Idempotente: si ya hay secciones, no siembra.
        if (LandingSection::count() > 0) {
            return;
        }

        $sections = [
            ['type' => 'hero', 'data' => [
                'heading' => 'Bienvenido a nuestra tienda',
                'subheading' => 'Descubre nuestros productos y compra fácil.',
                'cta_text' => 'Entrar a la tienda',
                'cta_target' => 'catalog',
            ]],
            ['type' => 'about', 'data' => [
                'heading' => 'Quiénes somos',
                'body_html' => '<p>Somos un negocio comprometido con ofrecerte los mejores productos y atención. Edita este texto desde Ajustes.</p>',
            ]],
            ['type' => 'hours', 'data' => [
                'heading' => 'Horarios de atención',
                'rows' => [
                    ['label' => 'Lunes a Viernes', 'value' => '9:00 – 18:00'],
                    ['label' => 'Sábados', 'value' => '9:00 – 13:00'],
                ],
            ]],
            ['type' => 'categories', 'data' => [
                'heading' => 'Qué vendemos',
                'source' => 'auto',
                'items' => [],
            ]],
            ['type' => 'cta', 'data' => [
                'heading' => '¿Listo para comprar?',
                'text' => 'Explora todo nuestro catálogo.',
                'button_text' => 'Entrar a la tienda',
                'target' => 'catalog',
            ]],
        ];

        foreach ($sections as $i => $s) {
            LandingSection::create([
                'type' => $s['type'],
                'sort_order' => $i,
                'is_enabled' => true,
                'data' => $s['data'],
            ]);
        }
    }
}
