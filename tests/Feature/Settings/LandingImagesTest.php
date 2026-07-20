<?php

namespace Tests\Feature\Settings;

use App\Shop\Landing\LandingImages;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingImagesTest extends TestCase
{
    use RefreshDatabase;

    private LandingImages $images;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->images = new LandingImages();
    }

    public function test_store_puts_file_under_shop_landing(): void
    {
        $path = $this->images->store(UploadedFile::fake()->image('foto.jpg'));

        $this->assertStringStartsWith('shop/landing/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_delete_removes_the_file(): void
    {
        $path = $this->images->store(UploadedFile::fake()->image('foto.jpg'));

        $this->images->delete($path);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_delete_ignores_null_and_missing(): void
    {
        $this->images->delete(null);
        $this->images->delete('shop/landing/no-existe.jpg');

        $this->assertTrue(true); // no lanza
    }

    public function test_delete_for_section_removes_every_image_it_references(): void
    {
        $bg = $this->images->store(UploadedFile::fake()->image('bg.jpg'));
        $one = $this->images->store(UploadedFile::fake()->image('uno.jpg'));
        $two = $this->images->store(UploadedFile::fake()->image('dos.jpg'));

        $section = LandingSection::create([
            'type' => 'gallery',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['background_image_path' => $bg, 'images' => [$one, $two]],
        ]);

        $this->images->deleteForSection($section);

        Storage::disk('public')->assertMissing($bg);
        Storage::disk('public')->assertMissing($one);
        Storage::disk('public')->assertMissing($two);
    }
}
