<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingShareSettings;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Models\LandingSection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandingShareSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->admin()->create());

        // La migración sembradora de SP1 llena la tabla en cada build; estos tests
        // necesitan controlar el contenido exacto.
        LandingSection::query()->delete();
    }

    public function test_save_persists_title_and_description(): void
    {
        Livewire::test(LandingShareSettings::class)
            ->set('title', 'Mi Tienda')
            ->set('description', 'La mejor tienda del barrio')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Mi Tienda', Setting::get('shop_share_title'));
        $this->assertSame('La mejor tienda del barrio', Setting::get('shop_share_description'));
    }

    public function test_uploading_a_second_image_deletes_the_first_and_stores_the_new_one(): void
    {
        $component = Livewire::test(LandingShareSettings::class)
            ->set('imageUpload', UploadedFile::fake()->image('primero.jpg'));

        $firstPath = Setting::get('shop_share_image_path');
        $this->assertNotEmpty($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $component->set('imageUpload', UploadedFile::fake()->image('segundo.jpg'));

        $secondPath = Setting::get('shop_share_image_path');
        $this->assertNotEmpty($secondPath);
        $this->assertNotSame($firstPath, $secondPath);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_remove_image_deletes_the_file_and_clears_the_setting(): void
    {
        $component = Livewire::test(LandingShareSettings::class)
            ->set('imageUpload', UploadedFile::fake()->image('foto.jpg'));

        $path = Setting::get('shop_share_image_path');
        $this->assertNotEmpty($path);

        $component->call('removeImage');

        Storage::disk('public')->assertMissing($path);
        $this->assertEmpty(Setting::get('shop_share_image_path'));
    }

    public function test_title_longer_than_70_chars_fails_validation(): void
    {
        Livewire::test(LandingShareSettings::class)
            ->set('title', str_repeat('a', 71))
            ->call('save')
            ->assertHasErrors('title');
    }

    public function test_user_without_permission_cannot_save(): void
    {
        // El componente monta con el admin autenticado en setUp() — igual que en
        // producción, mount() no vuelve a correr en llamadas posteriores. Simulamos
        // que el permiso se revoca mientras la página sigue abierta cambiando el
        // usuario autenticado ANTES de la acción, no antes del mount.
        $test = Livewire::test(LandingShareSettings::class);

        $this->actingAs(User::factory()->staff()->create());

        $test->set('title', 'Intento sin permiso')
            ->call('save')
            ->assertForbidden();

        $this->assertNotSame('Intento sin permiso', Setting::get('shop_share_title'));
    }

    /**
     * ShareMetaBuilder::forLanding() llama a route('shop.index'), que solo se
     * registra cuando shop_enabled='1' (routes/shop.php). En este panel de admin
     * la tienda puede estar apagada, así que la vista previa tiene que tolerar
     * la ruta ausente en vez de tirar RouteNotFoundException y romper la página
     * entera de ajustes.
     */
    public function test_preview_does_not_blow_up_when_the_shop_is_disabled(): void
    {
        $this->assertNotSame('1', Setting::get('shop_enabled'));

        $html = Livewire::test(LandingShareSettings::class)->html();

        $this->assertStringContainsString('Activá la tienda', $html);
    }
}
