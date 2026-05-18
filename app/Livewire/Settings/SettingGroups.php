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
        'mensajeria' => [
            'telegram_enabled',
            'telegram_bot_paused',
            'telegram_bot_token',
            'telegram_admin_chat_id',
            'telegram_notify_low_stock',
            'telegram_notify_daily',
            'telegram_webhook_secret',
            'ai_search_enabled',
            'anthropic_api_key',
        ],
        'ia' => [
            'ai_chatbot_enabled',
            'ai_provider',
            'ai_api_base_url',
            'ai_model',
            'ai_max_tokens_response',
            'ai_max_cost_usd_per_day',
            'ai_max_msgs_per_minute',
            'ai_system_prompt',
            'ai_voice_enabled',
            'ai_voice_reply',
            'whisper_provider',
            'whisper_language',
            'whisper_max_seconds',
            'tts_provider',
            'tts_voice',
            'tts_binary_path',
            'tts_model_path',
            'openai_api_key',
        ],
        'nomina' => [
            'payroll_antiquity_base_amount',
            'payroll_border_bonus_rate',
            'payroll_labor_contribution_rate',
            'payroll_rc_iva_rate',
            'payroll_rc_iva_minimum',
            'payroll_rc_iva_compensable',
            'payroll_solidarity_1_rate',
            'payroll_solidarity_1_threshold',
            'payroll_solidarity_2_rate',
            'payroll_solidarity_2_threshold',
            'payroll_employer_contribution_rate',
            'payroll_aguinaldo_provision_rate',
            'payroll_indemnization_provision_rate',
            'payroll_account_mod',
            'payroll_account_moi',
            'payroll_account_sales',
            'payroll_account_admin',
            'payroll_account_net_payable',
            'payroll_account_employer_contribution',
            'payroll_account_labor_contribution',
            'payroll_account_aguinaldo_provision',
            'payroll_account_indemnization_provision',
            'payroll_account_rc_iva',
            'payroll_account_solidarity',
            'payroll_account_other_discounts',
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
        'mensajeria' => 'Telegram y notificaciones',
        'ia' => 'Agente IA y voz',
        'nomina' => 'Nomina y sueldos',
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
        'telegram_enabled' => '0',
        'telegram_bot_paused' => '0',
        'telegram_bot_token' => '',
        'telegram_admin_chat_id' => '',
        'telegram_notify_low_stock' => '1',
        'telegram_notify_daily' => '1',
        'telegram_webhook_secret' => '',
        'ai_search_enabled' => '0',
        'anthropic_api_key' => '',
        'ai_provider' => 'anthropic',
        'ai_api_base_url' => '',
        'payroll_antiquity_base_amount' => '7500',
        'payroll_border_bonus_rate' => '20',
        'payroll_labor_contribution_rate' => '12.71',
        'payroll_rc_iva_rate' => '13',
        'payroll_rc_iva_minimum' => '5000',
        'payroll_rc_iva_compensable' => '5000',
        'payroll_solidarity_1_rate' => '1',
        'payroll_solidarity_1_threshold' => '13000',
        'payroll_solidarity_2_rate' => '5',
        'payroll_solidarity_2_threshold' => '25000',
        'payroll_employer_contribution_rate' => '16.71',
        'payroll_aguinaldo_provision_rate' => '8.33',
        'payroll_indemnization_provision_rate' => '8.33',
        'payroll_account_mod' => '5.2',
        'payroll_account_moi' => '5.3',
        'payroll_account_sales' => '6.2',
        'payroll_account_admin' => '6.1',
        'payroll_account_net_payable' => '2.1.03',
        'payroll_account_employer_contribution' => '2.1.04',
        'payroll_account_labor_contribution' => '2.1.05',
        'payroll_account_aguinaldo_provision' => '2.1.06',
        'payroll_account_indemnization_provision' => '2.1.07',
        'payroll_account_rc_iva' => '2.1.08',
        'payroll_account_solidarity' => '2.1.09',
        'payroll_account_other_discounts' => '2.1.10',
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
            'telegram_enabled' => 'Habilitar Telegram',
            'telegram_bot_paused' => 'Bot en pausa',
            'telegram_bot_token' => 'Token del bot',
            'telegram_admin_chat_id' => 'Chat ID del admin',
            'telegram_notify_low_stock' => 'Notificar stock bajo',
            'telegram_notify_daily' => 'Resumen diario',
            'telegram_webhook_secret' => 'Webhook secret',
            'ai_search_enabled' => 'Búsqueda con IA',
            'anthropic_api_key' => 'Anthropic API key',
            'ai_chatbot_enabled' => 'Agente IA conversacional',
            'ai_provider' => 'Proveedor IA',
            'ai_api_base_url' => 'URL base API (OpenAI-compatible)',
            'ai_model' => 'Modelo',
            'ai_max_tokens_response' => 'Max tokens por respuesta',
            'ai_max_cost_usd_per_day' => 'Límite costo USD/día',
            'ai_max_msgs_per_minute' => 'Max mensajes/minuto por usuario',
            'ai_system_prompt' => 'Prompt del sistema',
            'ai_voice_enabled' => 'Procesar mensajes de voz',
            'ai_voice_reply' => 'Responder con voz si lo recibe',
            'whisper_provider' => 'Proveedor STT (transcripción)',
            'whisper_language' => 'Idioma transcripción (ISO-639)',
            'whisper_max_seconds' => 'Duración máxima audio (s)',
            'tts_provider' => 'Proveedor TTS (síntesis voz)',
            'tts_voice' => 'Voz TTS',
            'tts_binary_path' => 'Ruta binario Piper',
            'tts_model_path' => 'Ruta modelo Piper .onnx',
            'openai_api_key' => 'OpenAI API key',
            'payroll_antiquity_base_amount' => 'Base bono antiguedad',
            'payroll_border_bonus_rate' => 'Bono frontera (%)',
            'payroll_labor_contribution_rate' => 'Aporte laboral (%)',
            'payroll_rc_iva_rate' => 'RC-IVA (%)',
            'payroll_rc_iva_minimum' => 'RC-IVA minimo imponible',
            'payroll_rc_iva_compensable' => 'RC-IVA base compensable',
            'payroll_solidarity_1_rate' => 'Aporte solidario 1 (%)',
            'payroll_solidarity_1_threshold' => 'Umbral aporte solidario 1',
            'payroll_solidarity_2_rate' => 'Aporte solidario 2 (%)',
            'payroll_solidarity_2_threshold' => 'Umbral aporte solidario 2',
            'payroll_employer_contribution_rate' => 'Aporte patronal (%)',
            'payroll_aguinaldo_provision_rate' => 'Provision aguinaldo (%)',
            'payroll_indemnization_provision_rate' => 'Provision indemnizacion (%)',
            'payroll_account_mod' => 'Cuenta MOD',
            'payroll_account_moi' => 'Cuenta MOI',
            'payroll_account_sales' => 'Cuenta sueldos ventas',
            'payroll_account_admin' => 'Cuenta sueldos administracion',
            'payroll_account_net_payable' => 'Cuenta sueldos por pagar',
            'payroll_account_employer_contribution' => 'Cuenta aporte patronal por pagar',
            'payroll_account_labor_contribution' => 'Cuenta aporte laboral por pagar',
            'payroll_account_aguinaldo_provision' => 'Cuenta provision aguinaldo',
            'payroll_account_indemnization_provision' => 'Cuenta provision indemnizacion',
            'payroll_account_rc_iva' => 'Cuenta RC-IVA por pagar',
            'payroll_account_solidarity' => 'Cuenta aporte solidario por pagar',
            'payroll_account_other_discounts' => 'Cuenta otras retenciones',
            default => Str::title(str_replace('_', ' ', $key)),
        };
    }

    protected function formatValue(string $key, string $value): string
    {
        return match ($key) {
            'ai_provider' => match ($value) {
                'openai_compatible' => 'OpenAI-compatible (DeepSeek, Groq…)',
                default => 'Anthropic (Claude)',
            },
            'currency_position' => $value === 'right' ? 'Derecha' : 'Izquierda',
            'tax_include_iva', 'tax_include_it', 'telegram_enabled', 'telegram_notify_low_stock', 'telegram_notify_daily', 'ai_search_enabled',
            'ai_chatbot_enabled', 'ai_voice_enabled', 'ai_voice_reply' => $value === '1' ? 'Activo' : 'Inactivo',
            'telegram_bot_paused' => $value === '1' ? '🔴 Pausado' : '✅ Activo',
            'dashboard_display_mode' => $value === 'amount' ? 'Montos' : 'Porcentajes',
            'tax_iva_rate', 'tax_it_rate', 'discount_rate_annual',
            'payroll_border_bonus_rate', 'payroll_labor_contribution_rate', 'payroll_rc_iva_rate',
            'payroll_solidarity_1_rate', 'payroll_solidarity_2_rate', 'payroll_employer_contribution_rate',
            'payroll_aguinaldo_provision_rate', 'payroll_indemnization_provision_rate' => $value . '%',
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
