<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Settings\SettingsCatalog;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class SettingsPage extends Component
{
    use WithFileUploads;

    #[Locked, Url(as: 'seccion', history: true)]
    public string $section = 'negocio';

    /** Valores editables de la sección abierta, indexados por clave de setting. */
    public array $values = [];

    /** Contraseña para campos marcados con 'confirm'. */
    public string $password = '';

    public $companyLogoUpload = null;
    public $shopLogoUpload = null;

    public function mount(): void
    {
        abort_if($this->sections() === [], 403);

        if (! array_key_exists($this->section, $this->sections())) {
            $this->section = array_key_first($this->sections());
        }

        $this->loadValues();
    }

    public function goTo(string $section): void
    {
        if (! array_key_exists($section, $this->sections())) {
            return;
        }

        $this->section = $section;
        $this->password = '';
        $this->resetValidation();
        $this->loadValues();
    }

    public function applyPalette(string $palette): void
    {
        $colors = SettingsCatalog::SHOP_PALETTES[$palette] ?? null;
        if ($this->section !== 'tienda' || ! $colors) {
            return;
        }

        $this->values['shop_primary_color'] = $colors['primary'];
        $this->values['shop_secondary_color'] = $colors['secondary'];
        $this->values['shop_accent_color'] = $colors['accent'];
        $this->values['shop_text_on_primary'] = $colors['text'];
    }

    public function save(): void
    {
        $fields = $this->currentFields();
        $stored = [];
        $rules = [];

        foreach ($fields as $field) {
            $stored[$field['key']] = $this->storedValue($field);
            $rules['values.' . $field['key']] = SettingsCatalog::rules($field, $stored[$field['key']]);
        }

        $this->validate($rules, [], $this->attributeNames($fields));

        if ($this->section === 'moneda'
            && ($this->values['currency_thousand_separator'] ?? null) === ($this->values['currency_decimal_separator'] ?? null)) {
            $this->addError('values.currency_decimal_separator', 'Debe ser distinto del separador de miles.');
            return;
        }

        $changed = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $new = $this->normalize($field, $this->values[$key] ?? null);

            // Secreto vacío = conservar el actual (nunca se envía al navegador).
            if ($field['type'] === 'secret' && $new === '') {
                continue;
            }

            if ($new !== (string) ($stored[$key] ?? '')) {
                $changed[$key] = ['field' => $field, 'value' => $new];
            }
        }

        $needsPassword = collect($changed)->contains(fn ($c) => ! empty($c['field']['confirm']));
        if ($needsPassword && ! Hash::check($this->password, (string) auth()->user()->password)) {
            $this->addError('password', 'Contraseña incorrecta.');
            return;
        }

        foreach ($changed as $key => $change) {
            Setting::set($key, $change['value']);
        }

        if (array_key_exists('shop_enabled', $changed)) {
            app(ShopFeatureFlag::class)->invalidate();
        }

        $this->password = '';
        $this->loadValues();
        $this->dispatch('settings-saved');
        $this->dispatch('toast', message: $changed === [] ? 'No había cambios que guardar.' : 'Cambios guardados.', type: 'success');
    }

    // -------------------------------------------------------------------------
    // Logos (se guardan al subirlos, como cualquier archivo)
    // -------------------------------------------------------------------------

    public function updatedCompanyLogoUpload(): void
    {
        $this->storeLogo('companyLogoUpload', 'store_logo_path', 'company', 'negocio');
    }

    public function updatedShopLogoUpload(): void
    {
        $this->storeLogo('shopLogoUpload', 'shop_logo_path', 'shop', 'tienda');
    }

    public function removeCompanyLogo(): void
    {
        $this->deleteLogo('store_logo_path', 'negocio');
    }

    public function removeShopLogo(): void
    {
        $this->deleteLogo('shop_logo_path', 'tienda');
    }

    private function storeLogo(string $property, string $settingKey, string $directory, string $section): void
    {
        $this->authorizeSection($section);
        $this->validate([$property => ['image', 'max:2048', 'mimes:png,jpg,jpeg,svg,webp']], [], [$property => 'logo']);

        $old = Setting::get($settingKey);
        if ($old && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        Setting::set($settingKey, $this->{$property}->store($directory, 'public'));
        $this->{$property} = null;
        $this->dispatch('toast', message: 'Logo actualizado.', type: 'success');
    }

    private function deleteLogo(string $settingKey, string $section): void
    {
        $this->authorizeSection($section);

        $path = Setting::get($settingKey);
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        Setting::set($settingKey, '');
    }

    // -------------------------------------------------------------------------
    // Datos para la vista
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function sections(): array
    {
        return SettingsCatalog::visibleFor(auth()->user());
    }

    #[Computed]
    public function companyLogoUrl(): ?string
    {
        $path = Setting::get('store_logo_path');

        return $path ? Storage::url($path) : null;
    }

    #[Computed]
    public function shopLogoUrl(): ?string
    {
        $path = Setting::get('shop_logo_path');

        return $path ? Storage::url($path) : null;
    }

    #[Computed]
    public function shopPublicUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/tienda';
    }

    #[Computed]
    public function shopQrSvg(): string
    {
        return (string) QrCode::size(160)->margin(1)->errorCorrection('H')->generate($this->shopPublicUrl());
    }

    /** Pista "••••1234" para secretos ya configurados, sin exponer el valor. */
    public function secretHint(string $key): ?string
    {
        $value = (string) Setting::get($key);

        return $value === '' ? null : '••••••••' . substr($value, -4);
    }

    /** Vista previa del formato de moneda con los valores del formulario. */
    #[Computed]
    public function currencyPreview(): string
    {
        $v = $this->values;
        $decimals = (int) ($v['currency_fraction_digits'] ?? 2);
        $number = number_format(1234.5, $decimals, (string) ($v['currency_decimal_separator'] ?? ','), (string) ($v['currency_thousand_separator'] ?? '.'));
        $symbol = (string) ($v['currency_symbol'] ?? 'Bs');

        return ($v['currency_position'] ?? 'left') === 'right' ? "{$number} {$symbol}" : "{$symbol} {$number}";
    }

    public function render()
    {
        $section = $this->sections()[$this->section];
        $fields = collect($section['fields']);

        return view('livewire.settings.settings-page', [
            'current' => $section,
            'basicGroups' => $fields->where('advanced', '!=', true)->groupBy(fn ($f) => $f['group'] ?? ''),
            'advancedFields' => $fields->where('advanced', true)->values(),
            'hasConfirmFields' => $fields->contains(fn ($f) => ! empty($f['confirm'])),
        ]);
    }

    // -------------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentFields(): array
    {
        return $this->sections()[$this->section]['fields'] ?? [];
    }

    private function loadValues(): void
    {
        $this->values = [];
        foreach ($this->currentFields() as $field) {
            // Los secretos nunca viajan al navegador: el input arranca vacío.
            $this->values[$field['key']] = $field['type'] === 'secret' ? '' : $this->storedValue($field);
        }
    }

    private function storedValue(array $field): string
    {
        // get() sin default: evita cachear nuestro default por encima del de otros consumidores.
        return (string) (Setting::get($field['key']) ?? $field['default']);
    }

    private function normalize(array $field, mixed $value): string
    {
        $value = is_string($value) ? $value : (string) ($value ?? '');

        return match ($field['type']) {
            'toggle' => $value === '1' ? '1' : '0',
            'color' => strtoupper($value),
            // Sin trim: el separador "espacio" es un valor válido.
            'select' => $value,
            default => trim($value),
        };
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            $names['values.' . $field['key']] = mb_strtolower($field['label']);
        }

        return $names;
    }

    private function authorizeSection(string $section): void
    {
        abort_unless(array_key_exists($section, $this->sections()), 403);
    }
}
