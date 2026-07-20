<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingSectionForm;
use App\Models\User;
use App\Shop\Models\LandingSection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandingSectionFormTest extends TestCase
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

    private function section(string $type, array $data = []): LandingSection
    {
        return LandingSection::create([
            'type' => $type, 'sort_order' => 0, 'is_enabled' => true, 'data' => $data,
        ]);
    }

    public function test_loads_the_selected_section_merged_with_defaults(): void
    {
        $s = $this->section('hero', ['heading' => 'Hola']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->assertSet('type', 'hero')
            ->assertSet('form.heading', 'Hola')
            ->assertSet('form.cta_target', 'catalog');
    }

    public function test_save_persists_the_data(): void
    {
        $s = $this->section('hero', ['heading' => 'Viejo']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.heading', 'Nuevo')
            ->call('save')
            ->assertDispatched('landing-sections-changed');

        $this->assertSame('Nuevo', $s->fresh()->data['heading']);
    }

    public function test_save_sanitizes_rich_html(): void
    {
        $s = $this->section('about', ['heading' => 'H']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.body_html', '<p>Ok</p><script>alert(1)</script>')
            ->call('save');

        $this->assertStringNotContainsString('<script', $s->fresh()->data['body_html']);
        $this->assertStringContainsString('Ok', $s->fresh()->data['body_html']);
    }

    public function test_save_rejects_javascript_url_in_target(): void
    {
        $s = $this->section('cta', ['button_text' => 'Ir']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.target', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors('form.target');

        $this->assertNotSame('javascript:alert(1)', $s->fresh()->data['target'] ?? null);
    }

    public function test_save_accepts_catalog_whatsapp_and_valid_urls(): void
    {
        $s = $this->section('cta', ['button_text' => 'Ir']);

        foreach (['catalog', 'whatsapp', 'https://example.com', '/pagina'] as $value) {
            Livewire::test(LandingSectionForm::class)
                ->call('load', $s->id)
                ->set('form.target', $value)
                ->call('save')
                ->assertHasNoErrors();
        }
    }

    public function test_save_requires_hero_heading(): void
    {
        $s = $this->section('hero', ['heading' => 'X']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.heading', '')
            ->call('save')
            ->assertHasErrors('form.heading');
    }

    public function test_uploading_an_image_stores_it_and_sets_the_path(): void
    {
        $s = $this->section('about', []);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('imageUpload.image_path', UploadedFile::fake()->image('foto.jpg'));

        $path = $component->get('form.image_path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_removing_an_image_deletes_the_file(): void
    {
        $s = $this->section('about', []);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('imageUpload.image_path', UploadedFile::fake()->image('foto.jpg'));

        $path = $component->get('form.image_path');
        $component->call('removeImage', 'image_path');

        Storage::disk('public')->assertMissing($path);
        $this->assertEmpty($component->get('form.image_path'));
    }

    public function test_gallery_upload_appends_to_images(): void
    {
        $s = $this->section('gallery', ['images' => []]);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('galleryUpload', UploadedFile::fake()->image('a.jpg'))
            ->set('galleryUpload', UploadedFile::fake()->image('b.jpg'));

        $this->assertCount(2, $component->get('form.images'));
    }

    public function test_add_and_remove_hours_rows(): void
    {
        $s = $this->section('hours', ['rows' => []]);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->call('addHoursRow')
            ->assertCount('form.rows', 1)
            ->call('removeRow', 'rows', 0)
            ->assertCount('form.rows', 0);
    }

    public function test_manual_category_link_rejects_javascript(): void
    {
        $s = $this->section('categories', ['source' => 'manual', 'items' => [['label' => 'X', 'link' => 'javascript:alert(1)']]]);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->call('save')
            ->assertHasErrors('form.items.0.link');
    }
}
