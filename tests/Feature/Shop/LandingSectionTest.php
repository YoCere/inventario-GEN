<?php

namespace Tests\Feature\Shop;

use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La migración sembradora (2026_07_08_150100) inserta la plantilla por defecto
        // en cada build de RefreshDatabase. Estos tests de scope necesitan tabla limpia.
        LandingSection::query()->delete();
    }

    public function test_data_is_cast_to_array(): void
    {
        $s = LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'Bienvenido'],
        ]);

        $this->assertIsArray($s->fresh()->data);
        $this->assertSame('Bienvenido', $s->fresh()->data['heading']);
    }

    public function test_ordered_scope_sorts_by_sort_order_then_id(): void
    {
        LandingSection::create(['type' => 'cta', 'sort_order' => 2, 'is_enabled' => true, 'data' => []]);
        LandingSection::create(['type' => 'hero', 'sort_order' => 1, 'is_enabled' => true, 'data' => []]);

        $types = LandingSection::ordered()->pluck('type')->all();
        $this->assertSame(['hero', 'cta'], $types);
    }

    public function test_enabled_scope_excludes_disabled(): void
    {
        LandingSection::create(['type' => 'hero', 'sort_order' => 0, 'is_enabled' => true, 'data' => []]);
        LandingSection::create(['type' => 'about', 'sort_order' => 1, 'is_enabled' => false, 'data' => []]);

        $this->assertSame(1, LandingSection::enabled()->count());
    }
}
