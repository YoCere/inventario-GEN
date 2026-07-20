<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingEditor;
use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandingEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La migración sembradora de SP1 llena la tabla en cada build; estos tests
        // necesitan controlar el contenido exacto.
        LandingSection::query()->delete();
    }

    private function makeSection(string $type, int $order): LandingSection
    {
        return LandingSection::create([
            'type' => $type,
            'sort_order' => $order,
            'is_enabled' => true,
            'data' => ['heading' => strtoupper($type)],
        ]);
    }

    public function test_add_section_creates_row_with_default_data(): void
    {
        Livewire::test(LandingEditor::class)->call('addSection', 'hours');

        $section = LandingSection::where('type', 'hours')->firstOrFail();
        $this->assertTrue($section->is_enabled);
        $this->assertArrayHasKey('rows', $section->data);
    }

    public function test_add_section_rejects_unknown_type(): void
    {
        Livewire::test(LandingEditor::class)->call('addSection', 'inexistente');

        $this->assertSame(0, LandingSection::count());
    }

    public function test_move_down_swaps_with_next(): void
    {
        $a = $this->makeSection('hero', 0);
        $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $a->id, 'down');

        $this->assertSame(['about', 'hero'], LandingSection::ordered()->pluck('type')->all());
    }

    public function test_move_up_swaps_with_previous(): void
    {
        $this->makeSection('hero', 0);
        $b = $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $b->id, 'up');

        $this->assertSame(['about', 'hero'], LandingSection::ordered()->pluck('type')->all());
    }

    public function test_move_at_the_edge_does_nothing(): void
    {
        $a = $this->makeSection('hero', 0);
        $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $a->id, 'up');

        $this->assertSame(['hero', 'about'], LandingSection::ordered()->pluck('type')->all());
    }

    public function test_toggle_enabled_flips_the_flag(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)->call('toggleEnabled', $a->id);

        $this->assertFalse($a->fresh()->is_enabled);
    }

    public function test_delete_section_removes_the_row(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)->call('deleteSection', $a->id);

        $this->assertNull(LandingSection::find($a->id));
    }

    public function test_publish_switch_writes_the_setting(): void
    {
        Livewire::test(LandingEditor::class)->set('landingEnabled', false);

        $this->assertSame('0', Setting::get('shop_landing_enabled'));
    }

    public function test_selecting_a_section_dispatches_the_event(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)
            ->call('select', $a->id)
            ->assertDispatched('landing-section-selected');
    }
}
