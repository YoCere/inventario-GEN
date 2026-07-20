<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Shop\Landing\SectionTypes;
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

        // El registry es la fuente de la copy por defecto (evita que editor y plantilla diverjan).
        $template = [
            'hero' => [],
            'about' => [
                'body_html' => '<p>Somos un negocio comprometido con ofrecerte los mejores productos y atención. Edita este texto desde Ajustes.</p>',
            ],
            'hours' => [],
            'categories' => [],
            'cta' => [],
        ];

        $order = 0;
        foreach ($template as $type => $overrides) {
            LandingSection::create([
                'type' => $type,
                'sort_order' => $order++,
                'is_enabled' => true,
                'data' => array_merge(SectionTypes::defaultData($type), $overrides),
            ]);
        }
    }
}
