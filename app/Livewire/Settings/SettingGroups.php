<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class SettingGroups extends Component
{
    use WithFileUploads;

    /**
     * @var array<string, array<int, string>>
     */
    private const GROUP_KEYS = [
        'empresa' => [
            'store_logo_path',
            'store_name',
            'store_nit',
            'store_address',
            'store_phone',
            'business_timezone',
        ],
        'moneda' => [
            'currency_symbol',
            'currency_position',
            'currency_fraction_digits',
            'currency_thousand_separator',
            'currency_decimal_separator',
        ],
        'finanzas' => [
            'discount_rate_annual',
            'dashboard_display_mode',
        ],
        'periodo_contable' => [
            'default_accounting_period_type',
            'auto_create_next_period',
            'opening_balance_date',
            'opening_balance_amount',
        ],
        'impuestos' => [
            'tax_iva_rate',
            'tax_it_rate',
            'tax_include_iva',
            'tax_include_it',
            'accounting_iva_receivable_code',
            'accounting_iva_payable_code',
            'accounting_it_payable_code',
        ],
        'mensajeria' => [
            'telegram_enabled',
            'telegram_bot_paused',
            'telegram_bot_token',
            'telegram_admin_chat_id',
            'telegram_notify_low_stock',
            'telegram_notify_daily',
            'telegram_webhook_secret',
        ],
        'ia' => [
            // Habilitación
            'ai_chatbot_enabled',
            'ai_search_enabled',
            // Proveedor y API keys
            'ai_provider',
            'anthropic_api_key',
            'openai_api_key',
            'ai_api_base_url',
            // Modelo y límites
            'ai_model',
            'ai_max_tokens_response',
            'ai_max_cost_usd_per_day',
            'ai_max_msgs_per_minute',
            'ai_system_prompt',
            // Voz entrada (STT)
            'ai_voice_enabled',
            'whisper_provider',
            'whisper_model',
            'whisper_language',
            'whisper_max_seconds',
            // Voz salida (TTS)
            'ai_voice_reply',
            'tts_provider',
            'tts_voice',
            'tts_binary_path',
            'tts_model_path',
            // Visión (búsqueda por imagen en bot Telegram)
            'ai_vision_enabled',
            'ai_vision_model',
        ],
        'tienda' => [
            'shop_enabled',
            'shop_whatsapp_number',
            'shop_business_name',
            'shop_currency_symbol',
            'shop_welcome_message',
            'shop_show_out_of_stock',
            // Personalización visual (se editan inline en el panel, no via modal).
            'shop_logo_path',
            'shop_primary_color',
            'shop_secondary_color',
            'shop_accent_color',
            'shop_text_on_primary',
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
        'backups' => [
            'backup_schedule_enabled',
            'backup_retention_days',
            'backup_notify_email',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'empresa' => 'Datos de la empresa',
        'moneda' => 'Moneda y formato',
        'finanzas' => 'Ajustes financieros',
        'periodo_contable' => 'Periodo Contable',
        'impuestos' => 'Impuestos Bolivia',
        'mensajeria' => 'Telegram y notificaciones',
        'ia' => 'Agente IA y voz',
        'tienda' => 'Tienda en línea',
        'nomina' => 'Nomina y sueldos',
        'backups' => 'Backups y respaldos',
        'otros' => 'Otros ajustes',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_VALUES = [
        'store_logo_path' => '',
        'store_name' => 'Mi empresa',
        'store_nit' => '',
        'store_address' => '',
        'store_phone' => '',
        'business_timezone' => 'America/La_Paz',
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
        'accounting_iva_receivable_code' => '1.1.05',
        'accounting_iva_payable_code' => '2.1.11',
        'accounting_it_payable_code' => '2.1.12',
        'default_accounting_period_type' => 'monthly',
        'auto_create_next_period'        => '1',
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
        'whisper_model' => '',
        'ai_vision_enabled' => '0',
        'ai_vision_model' => 'gpt-4o-mini',
        'shop_enabled' => '0',
        'shop_whatsapp_number' => '',
        'shop_business_name' => '',
        'shop_currency_symbol' => 'Bs.',
        'shop_welcome_message' => '',
        'shop_show_out_of_stock' => '0',
        'shop_logo_path' => '',
        'shop_primary_color' => '#2563EB',
        'shop_secondary_color' => '#64748B',
        'shop_accent_color' => '#F59E0B',
        'shop_text_on_primary' => '#FFFFFF',
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
        'backup_schedule_enabled' => '1',
        'backup_retention_days'   => '30',
        'backup_notify_email'     => '',
    ];

    public bool $shopEnabled = false;

    /** Tema visual de la tienda — editable inline desde la sección Tienda. */
    public string $shopPrimaryColor = '#2563EB';
    public string $shopSecondaryColor = '#64748B';
    public string $shopAccentColor = '#F59E0B';
    public string $shopTextOnPrimary = '#FFFFFF';

    /** Upload temporal del logo de la tienda online (Livewire Temporary Upload). */
    public $logoUpload = null;

    /** Upload temporal del logo de la empresa (nav principal). */
    public $companyLogoUpload = null;

    /**
     * Paletas preset family-user. Click aplica los 4 colores de una vez.
     *
     * @var array<string, array<string, string>>
     */
    public const COLOR_PALETTES = [
        'azul' => [
            'name' => 'Profesional Azul',
            'primary' => '#2563EB',
            'secondary' => '#64748B',
            'accent' => '#F59E0B',
            'text' => '#FFFFFF',
        ],
        'verde' => [
            'name' => 'Natural Verde',
            'primary' => '#16A34A',
            'secondary' => '#78716C',
            'accent' => '#EAB308',
            'text' => '#FFFFFF',
        ],
        'rojo' => [
            'name' => 'Energía Roja',
            'primary' => '#DC2626',
            'secondary' => '#52525B',
            'accent' => '#F97316',
            'text' => '#FFFFFF',
        ],
        'morado' => [
            'name' => 'Elegante Morado',
            'primary' => '#7C3AED',
            'secondary' => '#6B7280',
            'accent' => '#EC4899',
            'text' => '#FFFFFF',
        ],
        'oscuro' => [
            'name' => 'Modo Oscuro',
            'primary' => '#0F172A',
            'secondary' => '#475569',
            'accent' => '#22D3EE',
            'text' => '#F8FAFC',
        ],
        'calido' => [
            'name' => 'Café Cálido',
            'primary' => '#92400E',
            'secondary' => '#78716C',
            'accent' => '#D97706',
            'text' => '#FFFBEB',
        ],
    ];

    /**
     * Grupos sensibles: contienen credenciales API / tokens / config técnica
     * que un toque equivocado rompe integraciones. Solo el rol Developer los ve.
     */
    private const DEVELOPER_ONLY_GROUPS = ['mensajeria', 'ia', 'backups'];

    public function mount(): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);

        $this->shopEnabled = Setting::get('shop_enabled') === '1';
        $this->shopPrimaryColor = Setting::get('shop_primary_color', '#2563EB');
        $this->shopSecondaryColor = Setting::get('shop_secondary_color', '#64748B');
        $this->shopAccentColor = Setting::get('shop_accent_color', '#F59E0B');
        $this->shopTextOnPrimary = Setting::get('shop_text_on_primary', '#FFFFFF');
    }

    /**
     * True si el usuario actual puede ver/editar grupos técnicos. Usado por
     * buildGroups() para esconder Telegram + IA al admin estándar.
     */
    private function canSeeDeveloperGroups(): bool
    {
        return auth()->user()?->isDeveloper() ?? false;
    }

    #[On('settings-updated')]
    public function refreshList(): void
    {
        // Trigger re-render after settings update.
        $this->shopEnabled = Setting::get('shop_enabled') === '1';
    }

    /**
     * Toggle del módulo Tienda — wire:model.live en el switch dispara updatedShopEnabled.
     * Persiste el flag, invalida el cache del feature flag para que ShopServiceProvider
     * lo refleje en el próximo request.
     *
     * GATE: solo Developer puede tocar este flag. Aunque la UI del admin renderice
     * el switch deshabilitado, un admin con dev-tools podría forzar el wire:model.
     * Este guard server-side rechaza el cambio y revierte el estado visible.
     */
    public function updatedShopEnabled(bool $value): void
    {
        if (! auth()->user()?->isDeveloper()) {
            // Revertir el estado al valor real persistido, sin escribir.
            $this->shopEnabled = Setting::get('shop_enabled') === '1';
            $this->dispatch('toast', message: 'Solo el desarrollador puede activar/desactivar la tienda.', type: 'error');
            return;
        }

        Setting::set('shop_enabled', $value ? '1' : '0');
        app(ShopFeatureFlag::class)->invalidate();
        $this->dispatch('settings-updated');
    }

    /**
     * Persist color setting cuando wire:model.live se dispara. Valida formato
     * hex #RRGGBB antes de guardar — protege contra CSS injection si el HTML
     * del catálogo interpola el valor directamente.
     */
    public function updatedShopPrimaryColor(string $value): void
    {
        $this->persistColor('shop_primary_color', $value, 'shopPrimaryColor');
    }

    public function updatedShopSecondaryColor(string $value): void
    {
        $this->persistColor('shop_secondary_color', $value, 'shopSecondaryColor');
    }

    public function updatedShopAccentColor(string $value): void
    {
        $this->persistColor('shop_accent_color', $value, 'shopAccentColor');
    }

    public function updatedShopTextOnPrimary(string $value): void
    {
        $this->persistColor('shop_text_on_primary', $value, 'shopTextOnPrimary');
    }

    private function persistColor(string $settingKey, string $value, string $property): void
    {
        $value = strtoupper($value);
        if (! preg_match('/^#[0-9A-F]{6}$/', $value)) {
            // Valor inválido — revertir a lo que está en BD.
            $this->{$property} = Setting::get($settingKey, '#000000');
            return;
        }

        Setting::set($settingKey, $value);
        $this->{$property} = $value;
    }

    /**
     * Aplicar paleta preset completa. Más simple para usuario no técnico:
     * elegir un nombre vs. ajustar 4 colores a mano.
     */
    public function applyPalette(string $paletteKey): void
    {
        if (! isset(self::COLOR_PALETTES[$paletteKey])) {
            return;
        }

        $palette = self::COLOR_PALETTES[$paletteKey];

        Setting::set('shop_primary_color', $palette['primary']);
        Setting::set('shop_secondary_color', $palette['secondary']);
        Setting::set('shop_accent_color', $palette['accent']);
        Setting::set('shop_text_on_primary', $palette['text']);

        $this->shopPrimaryColor = $palette['primary'];
        $this->shopSecondaryColor = $palette['secondary'];
        $this->shopAccentColor = $palette['accent'];
        $this->shopTextOnPrimary = $palette['text'];

        $this->dispatch('settings-updated');
    }

    /**
     * Upload logo: validar imagen, generar storage path, persistir en setting.
     * Borra el logo anterior si existía para no acumular orphan files.
     */
    public function updatedLogoUpload(): void
    {
        $this->validate([
            'logoUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,svg,webp'],
        ]);

        $oldPath = Setting::get('shop_logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $this->logoUpload->store('shop', 'public');
        Setting::set('shop_logo_path', $path);

        $this->logoUpload = null;
        $this->dispatch('settings-updated');
    }

    public function removeLogo(): void
    {
        $path = Setting::get('shop_logo_path');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        Setting::set('shop_logo_path', '');
        $this->dispatch('settings-updated');
    }

    // -------------------------------------------------------------------------
    // Logo de la empresa (nav principal)
    // -------------------------------------------------------------------------

    public function updatedCompanyLogoUpload(): void
    {
        $this->validate([
            'companyLogoUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,svg,webp'],
        ]);

        $old = Setting::get('store_logo_path');
        if ($old && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        $path = $this->companyLogoUpload->store('company', 'public');
        Setting::set('store_logo_path', $path);

        $this->companyLogoUpload = null;
        $this->dispatch('settings-updated');
    }

    public function removeCompanyLogo(): void
    {
        $path = Setting::get('store_logo_path');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        Setting::set('store_logo_path', '');
        $this->dispatch('settings-updated');
    }

    #[Computed]
    public function companyLogoUrl(): ?string
    {
        $path = Setting::get('store_logo_path');
        return $path ? Storage::url($path) : null;
    }

    // -------------------------------------------------------------------------
    // Logo de la tienda online
    // -------------------------------------------------------------------------

    #[Computed]
    public function shopLogoUrl(): ?string
    {
        $path = Setting::get('shop_logo_path');
        return $path ? Storage::url($path) : null;
    }

    #[Computed]
    public function shopPublicUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/tienda';
    }

    /**
     * Render del QR como SVG inline. simple-qrcode usa el backend bacon-qr-code por
     * defecto y produce SVG sin necesidad de extensión imagick.
     */
    #[Computed]
    public function shopQrSvg(): string
    {
        return (string) QrCode::size(200)
            ->margin(1)
            ->errorCorrection('H')
            ->generate($this->shopPublicUrl());
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
        $canSeeDevGroups = $this->canSeeDeveloperGroups();

        $groups = [];
        foreach (self::GROUP_KEYS as $groupKey => $keys) {
            // Filtrar grupos técnicos sensibles para no-developers. Mantiene la
            // visión limpia para el admin y evita que toque tokens/keys por error.
            if (in_array($groupKey, self::DEVELOPER_ONLY_GROUPS, true) && ! $canSeeDevGroups) {
                continue;
            }

            $groups[] = [
                'key' => $groupKey,
                'title' => self::GROUP_LABELS[$groupKey],
                'items' => $this->makeItems($keys, $settings),
            ];
        }

        // El grupo 'otros' (settings ad-hoc sin grupo asignado) también es técnico
        // por naturaleza — esconderlo a non-devs.
        if ($canSeeDevGroups) {
            $otherKeys = $settings->keys()->filter(fn (string $key) => ! in_array($key, $usedKeys, true))->values()->all();
            if (! empty($otherKeys)) {
                $groups[] = [
                    'key' => 'otros',
                    'title' => self::GROUP_LABELS['otros'],
                    'items' => $this->makeItems($otherKeys, $settings),
                ];
            }
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
            'store_logo_path' => 'Logo de la empresa',
            'store_name' => 'Nombre de la empresa',
            'store_nit' => 'NIT (Número de Identificación Tributaria)',
            'store_address' => 'Dirección',
            'store_phone' => 'Teléfono',
            'business_timezone' => 'Zona horaria del negocio (ej: America/La_Paz)',
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
            'accounting_iva_receivable_code' => 'Cuenta Crédito Fiscal IVA (compras)',
            'accounting_iva_payable_code' => 'Cuenta Débito Fiscal IVA (ventas)',
            'accounting_it_payable_code' => 'Cuenta IT por Pagar',
            'default_accounting_period_type' => 'Tipo de periodo contable por defecto',
            'auto_create_next_period'        => 'Auto-crear siguiente periodo al cerrar',
            'dashboard_display_mode' => 'Modo del dashboard',
            'telegram_enabled' => 'Habilitar Telegram',
            'telegram_bot_paused' => 'Bot en pausa',
            'telegram_bot_token' => 'Token del bot',
            'telegram_admin_chat_id' => 'Chat ID del admin',
            'telegram_notify_low_stock' => 'Notificar stock bajo',
            'telegram_notify_daily' => 'Resumen diario',
            'telegram_webhook_secret' => 'Webhook secret',
            'ai_chatbot_enabled' => 'Agente IA conversacional',
            'ai_search_enabled' => 'Búsqueda con IA (Telegram)',
            'ai_provider' => 'Proveedor IA',
            'anthropic_api_key' => 'API Key — Anthropic (Claude)',
            'openai_api_key' => 'API Key — OpenAI / Groq / DeepSeek',
            'ai_api_base_url' => 'Endpoint URL (ej: https://api.groq.com/openai/v1)',
            'ai_model' => 'Modelo (ej: claude-haiku-4-5-20251001, deepseek-chat, llama-3.3-70b)',
            'ai_max_tokens_response' => 'Max tokens por respuesta',
            'ai_max_cost_usd_per_day' => 'Límite costo USD/día',
            'ai_max_msgs_per_minute' => 'Max mensajes/minuto por usuario',
            'ai_system_prompt' => 'Prompt del sistema',
            'ai_voice_enabled' => 'Procesar mensajes de voz (STT)',
            'whisper_provider' => 'Proveedor STT',
            'whisper_language' => 'Idioma STT (ISO-639)',
            'whisper_max_seconds' => 'Duración máxima audio (s)',
            'whisper_model' => 'Modelo Whisper (ej: whisper-large-v3-turbo, whisper-1)',
            'ai_voice_reply' => 'Responder con voz (TTS)',
            'tts_provider' => 'Proveedor TTS',
            'tts_voice' => 'Voz TTS',
            'tts_binary_path' => 'Ruta binario Piper',
            'tts_model_path' => 'Ruta modelo Piper .onnx',
            'ai_vision_enabled' => 'Búsqueda por imagen (Vision)',
            'ai_vision_model' => 'Modelo Vision (ej: gpt-4o-mini, gpt-4o)',
            'shop_enabled' => 'Tienda en línea activa',
            'shop_whatsapp_number' => 'Número WhatsApp (formato internacional sin +)',
            'shop_business_name' => 'Nombre del negocio',
            'shop_currency_symbol' => 'Símbolo de moneda (tienda)',
            'shop_welcome_message' => 'Mensaje de bienvenida',
            'shop_show_out_of_stock' => 'Mostrar productos sin stock',
            'shop_logo_path' => 'Logo del negocio',
            'shop_primary_color' => 'Color principal',
            'shop_secondary_color' => 'Color secundario',
            'shop_accent_color' => 'Color de acento',
            'shop_text_on_primary' => 'Texto sobre color principal',
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
            'backup_schedule_enabled' => 'Backup automático diario (02:00 AM)',
            'backup_retention_days'   => 'Días de retención',
            'backup_notify_email'     => 'Email para notificaciones de backup',
            default => Str::title(str_replace('_', ' ', $key)),
        };
    }

    protected function formatValue(string $key, string $value): string
    {
        return match ($key) {
            // Rutas de archivo: no exponer path interno.
            'store_logo_path', 'shop_logo_path' => $value !== '' ? '📷 Logo configurado' : '— Sin logo',
            // Campos sensibles: mostrar sólo últimos 4 caracteres enmascarados.
            'telegram_bot_token', 'telegram_webhook_secret',
            'anthropic_api_key', 'openai_api_key' => $value !== ''
                ? '••••••••' . substr($value, -4)
                : '-',
            'ai_provider' => match ($value) {
                'openai_compatible' => 'OpenAI-compatible (DeepSeek, Groq…)',
                default => 'Anthropic (Claude)',
            },
            'currency_position' => $value === 'right' ? 'Derecha' : 'Izquierda',
            'tax_include_iva', 'tax_include_it', 'telegram_enabled', 'telegram_notify_low_stock', 'telegram_notify_daily', 'ai_search_enabled',
            'ai_chatbot_enabled', 'ai_voice_enabled', 'ai_voice_reply', 'ai_vision_enabled',
            'shop_enabled', 'shop_show_out_of_stock' => $value === '1' ? 'Activo' : 'Inactivo',
            'telegram_bot_paused' => $value === '1' ? '🔴 Pausado' : '✅ Activo',
            'dashboard_display_mode' => $value === 'amount' ? 'Montos' : 'Porcentajes',
            'tax_iva_rate', 'tax_it_rate', 'discount_rate_annual',
            'payroll_border_bonus_rate', 'payroll_labor_contribution_rate', 'payroll_rc_iva_rate',
            'payroll_solidarity_1_rate', 'payroll_solidarity_2_rate', 'payroll_employer_contribution_rate',
            'payroll_aguinaldo_provision_rate', 'payroll_indemnization_provision_rate' => $value . '%',
            'default_accounting_period_type' => match ($value) {
                'monthly'   => 'Mensual',
                'quarterly' => 'Trimestral',
                'biannual'  => 'Semestral',
                'annual'    => 'Anual',
                'custom'    => 'Personalizado',
                default     => $value,
            },
            'auto_create_next_period' => $value === '1' ? 'Activo' : 'Inactivo',
            'backup_schedule_enabled' => $value === '1' ? 'Activo' : 'Inactivo',
            'backup_retention_days'   => $value . ' días',
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
