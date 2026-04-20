<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class SettingGroups extends Component
{
    /**
     * @var array<string, array<int, string>>
     */
    private const GROUP_KEYS = [
        'empresa' => [
            'store_name',
            'store_address',
            'store_phone',
        ],
        'moneda' => [
            'currency_symbol',
            'currency_position',
            'currency_fraction_digits',
            'currency_thousand_separator',
            'currency_decimal_separator',
        ],
        'finanzas' => [
            'opening_balance_date',
            'opening_balance_amount',
            'discount_rate_annual',
            'dashboard_display_mode',
        ],
        'impuestos' => [
            'tax_iva_rate',
            'tax_it_rate',
            'tax_include_iva',
            'tax_include_it',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'empresa' => 'Datos de la empresa',
        'moneda' => 'Moneda y formato',
        'finanzas' => 'Ajustes financieros',
        'impuestos' => 'Impuestos Bolivia',
        'otros' => 'Otros ajustes',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_VALUES = [
        'store_name' => 'Mi empresa',
        'store_address' => '',
        'store_phone' => '',
        'currency_symbol' => 'Bs',
        'currency_position' => 'left',
        'currency_fraction_digits' => '2',
        'currency_thousand_separator' => '.',
        'currency_decimal_separator' => ',',
        'opening_balance_date' => '',
        'opening_balance_amount' => '0',
        'discount_rate_annual' => '12',
        'tax_iva_rate' => '13',
        'tax_it_rate' => '3',
        'tax_include_iva' => '1',
        'tax_include_it' => '1',
        'dashboard_display_mode' => 'percent',
    ];

    public function mount(): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
    }

    #[On('settings-updated')]
    public function refreshList(): void
    {
        // Trigger re-render after settings update.
    }

    public function editSetting(string $key): void
    {
        $this->dispatch('edit-setting', key: $key);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildGroups(): array
    {
        $settings = Setting::query()->orderBy('key')->get()->keyBy('key');
        $usedKeys = collect(self::GROUP_KEYS)->flatten()->values()->all();

        $groups = [];
        foreach (self::GROUP_KEYS as $groupKey => $keys) {
            $groups[] = [
                'key' => $groupKey,
                'title' => self::GROUP_LABELS[$groupKey],
                'items' => $this->makeItems($keys, $settings),
            ];
        }

        $otherKeys = $settings->keys()->filter(fn (string $key) => ! in_array($key, $usedKeys, true))->values()->all();
        if (! empty($otherKeys)) {
            $groups[] = [
                'key' => 'otros',
                'title' => self::GROUP_LABELS['otros'],
                'items' => $this->makeItems($otherKeys, $settings),
            ];
        }

        return $groups;
    }

    /**
     * @param array<int, string> $keys
     * @param Collection<string, Setting> $settings
     * @return array<int, array<string, string>>
     */
    protected function makeItems(array $keys, Collection $settings): array
    {
        $items = [];

        foreach ($keys as $key) {
            $default = self::DEFAULT_VALUES[$key] ?? ($settings->get($key)?->value);
            $value = Setting::get($key, $default);

            $items[] = [
                'key' => $key,
                'label' => $this->labelFor($key),
                'value' => $this->formatValue($key, (string) ($value ?? '')),
            ];
        }

        return $items;
    }

    protected function labelFor(string $key): string
    {
        return match ($key) {
            'store_name' => 'Nombre de la empresa',
            'store_address' => 'Dirección',
            'store_phone' => 'Teléfono',
            'currency_symbol' => 'Símbolo de moneda',
            'currency_position' => 'Posición del símbolo',
            'currency_fraction_digits' => 'Decimales',
            'currency_thousand_separator' => 'Separador de miles',
            'currency_decimal_separator' => 'Separador decimal',
            'opening_balance_date' => 'Fecha de balance inicial',
            'opening_balance_amount' => 'Monto de balance inicial',
            'discount_rate_annual' => 'Tasa anual para VAN (%)',
            'tax_iva_rate' => 'IVA (%)',
            'tax_it_rate' => 'IT (%)',
            'tax_include_iva' => 'Aplicar IVA',
            'tax_include_it' => 'Aplicar IT',
            'dashboard_display_mode' => 'Modo del dashboard',
            default => Str::title(str_replace('_', ' ', $key)),
        };
    }

    protected function formatValue(string $key, string $value): string
    {
        return match ($key) {
            'currency_position' => $value === 'right' ? 'Derecha' : 'Izquierda',
            'tax_include_iva', 'tax_include_it' => $value === '1' ? 'Activo' : 'Inactivo',
            'dashboard_display_mode' => $value === 'amount' ? 'Montos' : 'Porcentajes',
            'tax_iva_rate', 'tax_it_rate', 'discount_rate_annual' => $value . '%',
            default => $value !== '' ? $value : '-',
        };
    }

    public function render()
    {
        return view('livewire.settings.setting-groups', [
            'groups' => $this->buildGroups(),
        ]);
    }
}
