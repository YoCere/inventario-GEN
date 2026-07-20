<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopLandingRoutingTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableShop();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_landing_enabled_renders_landing_sections(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'MARCA_LANDING_HERO', 'subheading' => 'sub', 'cta_text' => 'Entrar', 'cta_target' => 'catalog'],
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('MARCA_LANDING_HERO');
    }

    public function test_landing_disabled_falls_back_to_catalog(): void
    {
        Setting::set('shop_landing_enabled', '0');

        $this->get('/tienda')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false);
    }

    public function test_catalog_route_renders_catalog(): void
    {
        $this->get('/tienda/catalogo')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false);
    }

    public function test_about_body_html_is_sanitized_on_render(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'about',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'Historia', 'body_html' => '<p>Ok</p><script>alert(1)</script>'],
        ]);

        $res = $this->get('/tienda')->assertOk();
        $res->assertSee('Historia');
        $res->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_cta_section_links_to_catalog(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'cta',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['button_text' => 'Entrar a la tienda', 'target' => 'catalog'],
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false)
            ->assertSee('Entrar a la tienda');
    }

    public function test_disabled_section_is_not_rendered(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => false,
            'data' => ['heading' => 'OCULTO_HERO'],
        ]);

        $this->get('/tienda')->assertOk()->assertDontSee('OCULTO_HERO');
    }

    public function test_default_template_seeded_and_idempotent(): void
    {
        // La migración ya corrió (RefreshDatabase). Debe haber secciones por defecto.
        $count = \App\Shop\Models\LandingSection::count();
        $this->assertGreaterThan(0, $count);

        // Re-ejecutar la lógica de siembra no debe duplicar.
        (new \Database\Seeders\DefaultLandingTemplateSeeder())->run();
        $this->assertSame($count, \App\Shop\Models\LandingSection::count());

        // Flag activado por defecto.
        $this->assertSame('1', \App\Models\Setting::get('shop_landing_enabled'));
    }

    public function test_hero_background_path_resolves_to_single_storage_url(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'H', 'background_image_path' => 'landing/hero.jpg'],
        ]);

        $res = $this->get('/tienda')->assertOk();
        $res->assertSee('url(/storage/landing/hero.jpg)', false);
        $res->assertDontSee('/storage//storage', false);
    }

    public function test_content_saved_by_the_editor_shows_on_the_public_landing(): void
    {
        \App\Models\Setting::set('shop_landing_enabled', '1');

        $section = LandingSection::create([
            'type' => 'about',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'MARCA_IDA_VUELTA', 'body_html' => '<p>Texto guardado</p>'],
        ]);

        $this->actingAs(\App\Models\User::factory()->admin()->create());

        \Livewire\Livewire::test(\App\Livewire\Settings\LandingSectionForm::class)
            ->call('load', $section->id)
            ->set('form.heading', 'MARCA_EDITADA')
            ->call('save');

        $this->get('/tienda')->assertOk()->assertSee('MARCA_EDITADA');
    }

    public function test_default_template_uses_registry_defaults(): void
    {
        \App\Shop\Models\LandingSection::query()->delete();
        (new \Database\Seeders\DefaultLandingTemplateSeeder())->run();

        $hero = \App\Shop\Models\LandingSection::where('type', 'hero')->firstOrFail();

        $this->assertSame(
            \App\Shop\Landing\SectionTypes::defaultData('hero')['subheading'],
            $hero->data['subheading']
        );
    }
}
