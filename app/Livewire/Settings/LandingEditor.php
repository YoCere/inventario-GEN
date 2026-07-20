<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Shop\Landing\LandingImages;
use App\Shop\Landing\SectionTypes;
use App\Shop\Models\LandingSection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Estructura de la landing: qué secciones hay, en qué orden, cuáles están activas
 * y si la landing se publica. NO edita el contenido — de eso se ocupa LandingSectionForm.
 */
class LandingEditor extends Component
{
    public ?int $selectedId = null;

    public bool $landingEnabled = true;

    public function mount(): void
    {
        $this->authorizeEditor();

        $this->landingEnabled = Setting::get('shop_landing_enabled', '1') === '1';
    }

    /**
     * La ruta gatea solo el GET inicial con middleware `can:`. Las acciones de
     * Livewire llegan por POST /livewire/update contra un snapshot hidratado y
     * `mount()` no vuelve a correr, así que cada acción mutante re-chequea el
     * permiso por su cuenta (mismo patrón que CategoryTable/AccountingPeriodSettings).
     */
    private function authorizeEditor(): void
    {
        abort_unless(auth()->user()?->can('shop.landing.manage'), 403);
    }

    #[Computed]
    public function sections()
    {
        return LandingSection::ordered()->get();
    }

    /** @return array<string,string> tipo => label, para el menú "agregar sección" */
    #[Computed]
    public function availableTypes(): array
    {
        return collect(SectionTypes::keys())
            ->mapWithKeys(fn ($type) => [$type => SectionTypes::label($type)])
            ->all();
    }

    public function updatedLandingEnabled(): void
    {
        $this->authorizeEditor();

        Setting::set('shop_landing_enabled', $this->landingEnabled ? '1' : '0');
    }

    public function select(int $id): void
    {
        $this->authorizeEditor();

        $this->selectedId = $id;
        $this->dispatch('landing-section-selected', id: $id);
    }

    public function addSection(string $type): void
    {
        $this->authorizeEditor();

        if (! SectionTypes::exists($type)) {
            return;
        }

        $section = LandingSection::create([
            'type' => $type,
            'sort_order' => (int) (LandingSection::max('sort_order') ?? -1) + 1,
            'is_enabled' => true,
            'data' => SectionTypes::defaultData($type),
        ]);

        unset($this->sections);
        $this->select($section->id);
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeEditor();

        $ordered = LandingSection::ordered()->get();
        $index = $ordered->search(fn ($s) => $s->id === $id);

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        // Reasignar 0..n-1 sobre el orden ya intercambiado: robusto aunque los
        // sort_order vengan duplicados o con huecos.
        $reordered = $ordered->values();
        $moved = $reordered->pull($index);
        $reordered = $reordered->values();
        $reordered->splice($target, 0, [$moved]);

        foreach ($reordered->values() as $position => $section) {
            $section->update(['sort_order' => $position]);
        }

        unset($this->sections);
    }

    public function toggleEnabled(int $id): void
    {
        $this->authorizeEditor();

        $section = LandingSection::find($id);
        $section?->update(['is_enabled' => ! $section->is_enabled]);

        unset($this->sections);
    }

    public function deleteSection(int $id): void
    {
        $this->authorizeEditor();

        $section = LandingSection::find($id);
        if (! $section) {
            return;
        }

        // Borra la fila ANTES de tocar el disco: si el delete de DB falla, el peor
        // caso es un archivo huérfano (desperdicia espacio) en vez de una fila que
        // sobrevive apuntando a imágenes ya borradas (rotas en la landing pública).
        // El modelo sigue teniendo `data` en memoria tras delete(), así que
        // deleteForSection() puede llamarse después sin problema.
        $section->delete();
        app(LandingImages::class)->deleteForSection($section);

        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->dispatch('landing-section-cleared');
        }

        unset($this->sections);
    }

    #[On('landing-sections-changed')]
    public function refreshSections(): void
    {
        unset($this->sections);
    }

    public function render()
    {
        return view('livewire.settings.landing-editor');
    }
}
