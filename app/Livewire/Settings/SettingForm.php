<?php

namespace App\Livewire\Settings;

use Livewire\Component;
use App\Models\Setting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class SettingForm extends Component
{
    public ?string $key = null;
    public ?string $value = null;
    public string $label = '';
    public string $admin_password = '';

    public function rules()
    {
        $rules = [
            'value' => ['nullable', 'string'],
        ];

        if ($this->requiresSensitiveConfirmation()) {
            $rules['admin_password'] = ['required', 'string'];
        }

        return $rules;
    }

    #[On('edit-setting')]
    public function edit($key = null)
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $this->resetValidation();
        if (is_array($key)) {
            $key = $key['key'] ?? $key[0] ?? null;
        }

        if (! is_string($key) || $key === '') {
            return;
        }

        // Gate técnico: settings sensibles (Telegram, IA, voz, API keys)
        // requieren rol Developer. Protege contra requests Livewire forjados
        // por un admin tech-savvy aunque el grupo esté oculto en la UI.
        $this->guardDeveloperOnlyKey($key);

        $setting = Setting::query()->find($key) ?? new Setting([
            'key' => $key,
            'value' => '',
        ]);

        $this->key = $setting->key;
        $this->value = $setting->value;
        $this->admin_password = '';
        $this->label = Str::title(str_replace('_', ' ', $setting->key));

        $this->dispatch('open-modal', name: 'setting-form-modal');
    }

    public function save()
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        // Doble check: el key persistido pudo cambiar entre edit() y save() vía
        // wire:model. Validamos el key actual del componente.
        $this->guardDeveloperOnlyKey((string) $this->key);

        $this->validate();

        if ($this->requiresSensitiveConfirmation() && ! Hash::check($this->admin_password, auth()->user()->password)) {
            $this->addError('admin_password', 'Contrasena de administrador incorrecta.');
            return;
        }

        try {
            Setting::set($this->key, $this->value);

            $this->dispatch('close-modal', name: 'setting-form-modal');
            $this->dispatch('pg:eventRefresh-setting-table');
            $this->dispatch('settings-updated');
            $this->dispatch('toast', message: 'Ajuste actualizado correctamente.', type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'No se pudo actualizar el ajuste: ' . $e->getMessage(), type: 'error');
        }
    }

    protected function requiresSensitiveConfirmation(): bool
    {
        return in_array($this->key, [
            'opening_balance_date',
            'opening_balance_amount',
        ], true);
    }

    /**
     * Prefijos de settings que solo el rol Developer puede tocar. Cubre
     * credenciales API, tokens, modelos IA y configuración del bot — un
     * cambio incorrecto rompe integraciones difíciles de diagnosticar.
     */
    private const DEVELOPER_ONLY_PREFIXES = [
        'telegram_',
        'ai_',
        'whisper_',
        'tts_',
        'anthropic_',
        'openai_',
    ];

    private function isDeveloperOnlyKey(string $key): bool
    {
        foreach (self::DEVELOPER_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function guardDeveloperOnlyKey(string $key): void
    {
        if ($this->isDeveloperOnlyKey($key) && ! auth()->user()->isDeveloper()) {
            abort(403, 'Solo el desarrollador puede modificar esta configuración técnica.');
        }
    }

    public function render()
    {
        return view('livewire.settings.setting-form');
    }
}
