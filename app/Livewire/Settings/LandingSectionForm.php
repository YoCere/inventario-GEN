<?php

namespace App\Livewire\Settings;

use App\Shop\Landing\LandingImages;
use App\Shop\Landing\LandingUrl;
use App\Shop\Landing\SectionTypes;
use App\Shop\Models\LandingSection;
use App\Shop\Services\LandingHtmlSanitizer;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Contenido de UNA sección: campos, validación, saneo e imágenes.
 * No sabe nada del orden ni del resto de la lista — de eso se ocupa LandingEditor.
 */
class LandingSectionForm extends Component
{
    use WithFileUploads;

    public ?int $sectionId = null;

    public string $type = '';

    /** @var array<string,mixed> copia editable del `data` de la sección */
    public array $form = [];

    /** @var array<string,mixed> subidas de imagen única, keyed por campo del form */
    public array $imageUpload = [];

    public $galleryUpload;

    /** Campos cuyo valor es un destino de enlace ('catalog' | 'whatsapp' | URL). */
    private const TARGET_FIELDS = ['cta_target', 'target'];

    private function authorizeEditor(): void
    {
        abort_unless(auth()->user()?->can('shop.landing.manage'), 403);
    }

    #[On('landing-section-selected')]
    public function load(int $id): void
    {
        $this->authorizeEditor();

        $section = LandingSection::find($id);
        if (! $section) {
            return;
        }

        $this->resetValidation();
        $this->sectionId = $section->id;
        $this->type = $section->type;
        $this->form = array_merge(SectionTypes::defaultData($section->type), $section->data ?? []);
        $this->imageUpload = [];
        $this->galleryUpload = null;
    }

    #[On('landing-section-cleared')]
    public function clear(): void
    {
        $this->authorizeEditor();

        $this->reset(['sectionId', 'type', 'form', 'imageUpload', 'galleryUpload']);
        $this->resetValidation();
    }

    public function addHoursRow(): void
    {
        $this->authorizeEditor();
        $this->form['rows'][] = ['label' => '', 'value' => ''];
    }

    public function addCategoryItem(): void
    {
        $this->authorizeEditor();
        $this->form['items'][] = ['label' => '', 'link' => ''];
    }

    public function removeRow(string $key, int $index): void
    {
        $this->authorizeEditor();
        unset($this->form[$key][$index]);
        $this->form[$key] = array_values($this->form[$key] ?? []);
    }

    public function updatedImageUpload(): void
    {
        $this->authorizeEditor();

        foreach ($this->imageUpload as $field => $file) {
            if (! $file) {
                continue;
            }

            $this->validate(["imageUpload.{$field}" => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp']]);

            app(LandingImages::class)->delete($this->form[$field] ?? null);
            $this->form[$field] = app(LandingImages::class)->store($file);
            $this->imageUpload[$field] = null;
        }
    }

    public function updatedGalleryUpload(): void
    {
        $this->authorizeEditor();

        if (! $this->galleryUpload) {
            return;
        }

        $this->validate(['galleryUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp']]);

        $this->form['images'][] = app(LandingImages::class)->store($this->galleryUpload);
        $this->galleryUpload = null;
    }

    public function removeImage(string $field): void
    {
        $this->authorizeEditor();
        app(LandingImages::class)->delete($this->form[$field] ?? null);
        $this->form[$field] = null;
    }

    public function removeGalleryImage(int $index): void
    {
        $this->authorizeEditor();
        app(LandingImages::class)->delete($this->form['images'][$index] ?? null);
        unset($this->form['images'][$index]);
        $this->form['images'] = array_values($this->form['images'] ?? []);
    }

    public function save(): void
    {
        $this->authorizeEditor();

        if (! $this->sectionId) {
            return;
        }

        $rules = [];
        foreach (SectionTypes::rules($this->type) as $field => $rule) {
            $rules["form.{$field}"] = $rule;
        }

        // Los destinos de enlace: o una palabra clave, o una URL segura. Se VALIDA
        // (no se reescribe en silencio) para que el usuario vea qué está mal.
        // Se usa isSafeUrl (puro): safeUrl() resolvería route('shop.catalog'), que
        // no existe si la tienda está apagada.
        $linkRule = function (string $attribute, $value, $fail) {
            if ($value === null || $value === '' || in_array($value, ['catalog', 'whatsapp'], true)) {
                return;
            }
            if (! LandingUrl::isSafeUrl($value)) {
                $fail('El enlace debe empezar con http://, https:// o /');
            }
        };

        foreach (self::TARGET_FIELDS as $field) {
            if (! array_key_exists($field, $this->form)) {
                continue;
            }
            $rules["form.{$field}"] ??= ['nullable', 'string', 'max:255'];
            $rules["form.{$field}"][] = $linkRule;
        }

        // 'items.*.link' (categorías manuales): se adjunta el closure a la regla
        // comodín en vez de iterar por índice — si se agregan AMBAS,
        // 'form.items.*.link' y 'form.items.{i}.link', Laravel expande el comodín
        // y fusiona/duplica validadores por atributo concreto de forma confusa.
        // Adjuntar una sola vez al comodín deja que Laravel lo invoque por cada
        // 'items.N.link' expandido, con el $attribute y $value correctos.
        if (array_key_exists('form.items.*.link', $rules)) {
            $rules['form.items.*.link'][] = $linkRule;
        }

        $this->validate($rules);

        $data = $this->form;

        // Invariante de SP1: el HTML rico se guarda ya saneado (y el render lo sanea igual).
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = app(LandingHtmlSanitizer::class)->sanitize($data['body_html']);
        }

        // Las rutas de imagen se validan antes de persistir: el héroe las interpola en CSS.
        foreach (['background_image_path', 'image_path'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = LandingUrl::safeStoragePath($data[$field]);
            }
        }

        LandingSection::whereKey($this->sectionId)->update(['data' => $data]);

        $this->dispatch('landing-sections-changed');
        $this->dispatch('landing-saved');
    }

    public function render()
    {
        return view('livewire.settings.landing-section-form');
    }
}
