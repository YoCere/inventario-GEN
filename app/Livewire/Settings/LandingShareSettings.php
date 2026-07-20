<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Shop\Landing\LandingImages;
use App\Shop\Landing\LandingUrl;
use App\Shop\Seo\ShareMeta;
use App\Shop\Seo\ShareMetaBuilder;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ajustes de cómo se ve la tienda al compartir su enlace. Guardado inmediato
 * (como el panel del logo): no hay paso de "publicar", así que borrar la imagen
 * anterior en el momento es correcto acá — a diferencia de LandingSectionForm,
 * donde el borrado se difiere hasta guardar porque el usuario puede abandonar la
 * edición sin confirmar. No unificar los dos criterios sin leer el spec.
 */
class LandingShareSettings extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $description = '';

    public $imageUpload;

    private function authorizeShare(): void
    {
        abort_unless(auth()->user()?->can('shop.landing.manage'), 403);
    }

    public function mount(): void
    {
        $this->authorizeShare();

        $this->title = (string) Setting::get('shop_share_title', '');
        $this->description = (string) Setting::get('shop_share_description', '');
    }

    /**
     * Lo que realmente se va a compartir, con las cadenas de respaldo ya aplicadas.
     * Null si la tienda pública todavía no está habilitada: ShareMetaBuilder::forLanding()
     * llama a route('shop.index'), que solo se registra con shop_enabled='1'
     * (ShopServiceProvider::boot() carga routes/shop.php condicionalmente). Este panel
     * vive en /settings, accesible aun con la tienda apagada, así que hay que tolerar
     * la ruta ausente en vez de dejar que RouteNotFoundException tumbe toda la página.
     */
    #[Computed]
    public function preview(): ?ShareMeta
    {
        if (! Route::has('shop.index')) {
            return null;
        }

        return app(ShareMetaBuilder::class)->forLanding();
    }

    public function imagePath(): ?string
    {
        return Setting::get('shop_share_image_path') ?: null;
    }

    public function save(): void
    {
        $this->authorizeShare();

        $this->validate([
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        Setting::set('shop_share_title', $this->title);
        Setting::set('shop_share_description', $this->description);

        unset($this->preview);
        $this->dispatch('share-settings-saved');
    }

    public function updatedImageUpload(): void
    {
        $this->authorizeShare();

        if (! $this->imageUpload) {
            return;
        }

        $this->validate([
            'imageUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
        ]);

        app(LandingImages::class)->delete($this->imagePath());

        $path = LandingUrl::safeStoragePath(app(LandingImages::class)->store($this->imageUpload));
        Setting::set('shop_share_image_path', (string) $path);

        $this->imageUpload = null;
        unset($this->preview);
    }

    public function removeImage(): void
    {
        $this->authorizeShare();

        app(LandingImages::class)->delete($this->imagePath());
        Setting::set('shop_share_image_path', '');

        unset($this->preview);
    }

    public function render()
    {
        return view('livewire.settings.landing-share-settings');
    }
}
