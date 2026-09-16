<?php

namespace App\Settings;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Catálogo único de la pantalla Ajustes: secciones, campos, quién los ve y cómo
 * se validan. La vista y el guardado leen de aquí; agregar un ajuste = agregar
 * una entrada, sin tocar Blade.
 *
 * Campo:
 *  - key        clave en la tabla settings
 *  - label      texto visible (lenguaje de negocio, no técnico)
 *  - type       text|textarea|select|toggle|percent|number|account|secret|date|url|phone|color
 *  - default    valor mostrado si la clave no existe en BD
 *  - help       ayuda corta bajo el campo (opcional)
 *  - options    [valor => etiqueta] para select
 *  - advanced   true = va plegado en el bloque avanzado de la sección
 *  - group      subtítulo dentro de la sección (opcional)
 *  - ability    permiso extra requerido solo para este campo (además del de la sección)
 *  - required   no puede quedar vacío
 *  - suffix     texto a la derecha del input (%, Bs, USD)
 *  - wide       ocupa las dos columnas
 *  - confirm    exige contraseña del usuario para cambiarlo
 */
final class SettingsCatalog
{
    public const TIMEZONES = [
        'America/La_Paz' => 'Bolivia (La Paz)',
        'America/Lima' => 'Perú (Lima)',
        'America/Bogota' => 'Colombia (Bogotá)',
        'America/Guayaquil' => 'Ecuador (Guayaquil)',
        'America/Santiago' => 'Chile (Santiago)',
        'America/Argentina/Buenos_Aires' => 'Argentina (Buenos Aires)',
        'America/Asuncion' => 'Paraguay (Asunción)',
        'America/Montevideo' => 'Uruguay (Montevideo)',
        'America/Caracas' => 'Venezuela (Caracas)',
        'America/Mexico_City' => 'México (Ciudad de México)',
        'Europe/Madrid' => 'España (Madrid)',
        'UTC' => 'UTC',
    ];

    public const SEPARATORS = [
        '.' => 'Punto (1.000)',
        ',' => 'Coma (1,000)',
        ' ' => 'Espacio (1 000)',
        '' => 'Ninguno (1000)',
    ];

    /**
     * Paletas de un clic para la tienda en línea.
     *
     * @var array<string, array<string, string>>
     */
    public const SHOP_PALETTES = [
        'azul' => ['name' => 'Azul', 'primary' => '#2563EB', 'secondary' => '#64748B', 'accent' => '#F59E0B', 'text' => '#FFFFFF'],
        'verde' => ['name' => 'Verde', 'primary' => '#16A34A', 'secondary' => '#78716C', 'accent' => '#EAB308', 'text' => '#FFFFFF'],
        'rojo' => ['name' => 'Rojo', 'primary' => '#DC2626', 'secondary' => '#52525B', 'accent' => '#F97316', 'text' => '#FFFFFF'],
        'morado' => ['name' => 'Morado', 'primary' => '#7C3AED', 'secondary' => '#6B7280', 'accent' => '#EC4899', 'text' => '#FFFFFF'],
        'oscuro' => ['name' => 'Oscuro', 'primary' => '#0F172A', 'secondary' => '#475569', 'accent' => '#22D3EE', 'text' => '#F8FAFC'],
        'cafe' => ['name' => 'Café', 'primary' => '#92400E', 'secondary' => '#78716C', 'accent' => '#D97706', 'text' => '#FFFBEB'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'negocio' => [
                'title' => 'Mi negocio',
                'description' => 'Aparece en tus comprobantes, reportes y en la barra superior.',
                'icon' => 'building-storefront',
                'ability' => 'settings.edit-business',
                'fields' => [
                    ['key' => 'store_name', 'label' => 'Nombre del negocio', 'type' => 'text', 'default' => 'Mi empresa', 'required' => true],
                    ['key' => 'store_nit', 'label' => 'NIT', 'type' => 'text', 'default' => '', 'help' => 'Opcional. Solo si emites facturas.'],
                    ['key' => 'store_phone', 'label' => 'Teléfono', 'type' => 'text', 'default' => ''],
                    ['key' => 'business_timezone', 'label' => 'Zona horaria', 'type' => 'select', 'default' => 'America/La_Paz', 'options' => self::TIMEZONES],
                    ['key' => 'store_address', 'label' => 'Dirección', 'type' => 'text', 'default' => '', 'wide' => true],
                ],
            ],

            'moneda' => [
                'title' => 'Moneda',
                'description' => 'Cómo se muestran los precios en todo el sistema.',
                'icon' => 'banknotes',
                'ability' => 'settings.edit-business',
                'fields' => [
                    ['key' => 'currency_symbol', 'label' => 'Símbolo', 'type' => 'text', 'default' => 'Bs', 'required' => true, 'max' => 5],
                    ['key' => 'currency_position', 'label' => 'Dónde va el símbolo', 'type' => 'select', 'default' => 'left', 'options' => ['left' => 'Antes del monto', 'right' => 'Después del monto']],
                    ['key' => 'currency_fraction_digits', 'label' => 'Decimales', 'type' => 'select', 'default' => '2', 'options' => ['0' => 'Sin decimales', '2' => '2 decimales']],
                    ['key' => 'currency_thousand_separator', 'label' => 'Separador de miles', 'type' => 'select', 'default' => '.', 'options' => self::SEPARATORS],
                    ['key' => 'currency_decimal_separator', 'label' => 'Separador de decimales', 'type' => 'select', 'default' => ',', 'options' => ['.' => 'Punto (0.50)', ',' => 'Coma (0,50)']],
                ],
            ],

            'facturacion' => [
                'title' => 'Facturación e impuestos',
                'description' => 'Si no emites facturas puedes dejar todo como está.',
                'icon' => 'receipt-percent',
                'ability' => 'settings.edit-business',
                'advanced_title' => 'Para tu contador',
                'advanced_help' => 'Cuentas contables donde se registran los impuestos. Cámbialas solo si tu contador lo indica.',
                'fields' => [
                    ['key' => 'facturacion_activada', 'label' => 'Emitir facturas', 'type' => 'toggle', 'default' => '0', 'wide' => true, 'help' => 'Muestra la opción "¿Con factura?" al vender y calcula IVA e IT.'],
                    ['key' => 'tax_iva_rate', 'label' => 'IVA', 'type' => 'percent', 'default' => '13', 'ability' => 'finance.accounting'],
                    ['key' => 'tax_it_rate', 'label' => 'IT', 'type' => 'percent', 'default' => '3', 'ability' => 'finance.accounting'],
                    ['key' => 'accounting_cf_iva_code', 'label' => 'Crédito fiscal IVA', 'type' => 'account', 'default' => '1.1.05', 'advanced' => true, 'ability' => 'finance.accounting'],
                    ['key' => 'accounting_df_iva_code', 'label' => 'Débito fiscal IVA', 'type' => 'account', 'default' => '2.1.11', 'advanced' => true, 'ability' => 'finance.accounting'],
                    ['key' => 'accounting_it_payable_code', 'label' => 'IT por pagar', 'type' => 'account', 'default' => '2.1.12', 'advanced' => true, 'ability' => 'finance.accounting'],
                ],
            ],

            'tienda' => [
                'title' => 'Tienda en línea',
                'description' => 'Tu catálogo público para que te pidan por WhatsApp.',
                'icon' => 'shopping-bag',
                'ability' => 'settings.edit-business',
                'advanced_title' => 'Colores personalizados',
                'advanced_help' => 'Si ninguna paleta te convence, elige cada color.',
                'fields' => [
                    ['key' => 'shop_enabled', 'label' => 'Tienda publicada', 'type' => 'toggle', 'default' => '0', 'wide' => true, 'ability' => 'settings.edit-technical', 'help' => 'Cuando está apagada, nadie puede ver el catálogo.'],
                    ['key' => 'shop_whatsapp_number', 'label' => 'WhatsApp para pedidos', 'type' => 'phone', 'default' => '', 'help' => 'Con código de país, solo números. Ej: 59170012345'],
                    ['key' => 'shop_business_name', 'label' => 'Nombre en la tienda', 'type' => 'text', 'default' => '', 'help' => 'Si lo dejas vacío se usa el nombre de tu negocio.'],
                    ['key' => 'shop_welcome_message', 'label' => 'Mensaje de bienvenida', 'type' => 'textarea', 'default' => '', 'wide' => true],
                    ['key' => 'shop_show_out_of_stock', 'label' => 'Mostrar productos sin stock', 'type' => 'toggle', 'default' => '0', 'wide' => true],
                    ['key' => 'shop_primary_color', 'label' => 'Color principal', 'type' => 'color', 'default' => '#2563EB', 'advanced' => true],
                    ['key' => 'shop_secondary_color', 'label' => 'Color secundario', 'type' => 'color', 'default' => '#64748B', 'advanced' => true],
                    ['key' => 'shop_accent_color', 'label' => 'Color de ofertas', 'type' => 'color', 'default' => '#F59E0B', 'advanced' => true],
                    ['key' => 'shop_text_on_primary', 'label' => 'Texto sobre el color principal', 'type' => 'color', 'default' => '#FFFFFF', 'advanced' => true],
                ],
            ],

            'sueldos' => [
                'title' => 'Sueldos',
                'description' => 'Valores de ley para la planilla. Cámbialos solo si cambia la normativa.',
                'icon' => 'users',
                'ability' => 'users.payroll',
                'advanced_title' => 'Para tu contador',
                'advanced_help' => 'Cuentas contables donde se registra la planilla.',
                'fields' => [
                    ['key' => 'payroll_antiquity_base_amount', 'label' => 'Base del bono de antigüedad', 'type' => 'number', 'default' => '7500', 'suffix' => 'Bs', 'group' => 'Bonos'],
                    ['key' => 'payroll_border_bonus_rate', 'label' => 'Bono frontera', 'type' => 'percent', 'default' => '20', 'group' => 'Bonos'],
                    ['key' => 'payroll_labor_contribution_rate', 'label' => 'Aporte laboral', 'type' => 'percent', 'default' => '12.71', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_rc_iva_rate', 'label' => 'RC-IVA', 'type' => 'percent', 'default' => '13', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_rc_iva_minimum', 'label' => 'RC-IVA: mínimo no imponible', 'type' => 'number', 'default' => '5000', 'suffix' => 'Bs', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_rc_iva_compensable', 'label' => 'RC-IVA: monto compensable', 'type' => 'number', 'default' => '5000', 'suffix' => 'Bs', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_solidarity_1_rate', 'label' => 'Aporte solidario (1er tramo)', 'type' => 'percent', 'default' => '1', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_solidarity_1_threshold', 'label' => 'Desde un sueldo de', 'type' => 'number', 'default' => '13000', 'suffix' => 'Bs', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_solidarity_2_rate', 'label' => 'Aporte solidario (2do tramo)', 'type' => 'percent', 'default' => '5', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_solidarity_2_threshold', 'label' => 'Desde un sueldo de', 'type' => 'number', 'default' => '25000', 'suffix' => 'Bs', 'group' => 'Descuentos al trabajador'],
                    ['key' => 'payroll_employer_contribution_rate', 'label' => 'Aporte patronal', 'type' => 'percent', 'default' => '16.71', 'group' => 'A cargo del empleador'],
                    ['key' => 'payroll_aguinaldo_provision_rate', 'label' => 'Provisión de aguinaldo', 'type' => 'percent', 'default' => '8.33', 'group' => 'A cargo del empleador'],
                    ['key' => 'payroll_indemnization_provision_rate', 'label' => 'Provisión de indemnización', 'type' => 'percent', 'default' => '8.33', 'group' => 'A cargo del empleador'],
                    ['key' => 'payroll_account_mod', 'label' => 'Mano de obra directa', 'type' => 'account', 'default' => '5.2', 'advanced' => true],
                    ['key' => 'payroll_account_moi', 'label' => 'Mano de obra indirecta', 'type' => 'account', 'default' => '5.3', 'advanced' => true],
                    ['key' => 'payroll_account_sales', 'label' => 'Sueldos de ventas', 'type' => 'account', 'default' => '6.2', 'advanced' => true],
                    ['key' => 'payroll_account_admin', 'label' => 'Sueldos de administración', 'type' => 'account', 'default' => '6.1', 'advanced' => true],
                    ['key' => 'payroll_account_net_payable', 'label' => 'Sueldos por pagar', 'type' => 'account', 'default' => '2.1.03', 'advanced' => true],
                    ['key' => 'payroll_account_employer_contribution', 'label' => 'Aporte patronal por pagar', 'type' => 'account', 'default' => '2.1.04', 'advanced' => true],
                    ['key' => 'payroll_account_labor_contribution', 'label' => 'Aporte laboral por pagar', 'type' => 'account', 'default' => '2.1.05', 'advanced' => true],
                    ['key' => 'payroll_account_aguinaldo_provision', 'label' => 'Provisión de aguinaldo', 'type' => 'account', 'default' => '2.1.06', 'advanced' => true],
                    ['key' => 'payroll_account_indemnization_provision', 'label' => 'Provisión de indemnización', 'type' => 'account', 'default' => '2.1.07', 'advanced' => true],
                    ['key' => 'payroll_account_rc_iva', 'label' => 'RC-IVA por pagar', 'type' => 'account', 'default' => '2.1.08', 'advanced' => true],
                    ['key' => 'payroll_account_solidarity', 'label' => 'Aporte solidario por pagar', 'type' => 'account', 'default' => '2.1.09', 'advanced' => true],
                    ['key' => 'payroll_account_other_discounts', 'label' => 'Otras retenciones', 'type' => 'account', 'default' => '2.1.10', 'advanced' => true],
                ],
            ],

            'contabilidad' => [
                'title' => 'Contabilidad',
                'description' => 'Datos para el cierre de gestión y los reportes financieros.',
                'icon' => 'chart-bar',
                'ability' => 'finance.accounting',
                'advanced_title' => 'Para tu contador',
                'advanced_help' => 'Tasas del cierre de gestión y datos de inicio. Cámbialos solo si tu contador lo indica.',
                'fields' => [
                    ['key' => 'company_entity_type', 'label' => 'Tipo de sociedad', 'type' => 'select', 'default' => 'unipersonal', 'options' => [
                        'unipersonal' => 'Unipersonal',
                        'persona_natural' => 'Persona natural',
                        'srl' => 'S.R.L.',
                        'sa' => 'S.A.',
                    ], 'help' => 'Las S.R.L. y S.A. separan una reserva legal al cerrar la gestión.'],
                    ['key' => 'tax_iue_rate', 'label' => 'IUE', 'type' => 'percent', 'default' => '25', 'advanced' => true],
                    ['key' => 'legal_reserve_rate', 'label' => 'Reserva legal', 'type' => 'percent', 'default' => '5', 'advanced' => true],
                    ['key' => 'legal_reserve_cap_pct', 'label' => 'Tope de la reserva (del capital)', 'type' => 'percent', 'default' => '50', 'advanced' => true],
                    ['key' => 'discount_rate_annual', 'label' => 'Tasa para evaluar inversiones (VAN)', 'type' => 'percent', 'default' => '12', 'advanced' => true],
                    ['key' => 'opening_balance_date', 'label' => 'Fecha del saldo inicial de caja', 'type' => 'date', 'default' => '', 'advanced' => true, 'confirm' => true],
                    ['key' => 'opening_balance_amount', 'label' => 'Saldo inicial de caja', 'type' => 'number', 'default' => '0', 'suffix' => 'Bs', 'advanced' => true, 'confirm' => true],
                ],
            ],

            'sistema' => [
                'title' => 'Sistema',
                'description' => 'Conexiones técnicas. Un valor equivocado puede dejar de funcionar el bot o el asistente.',
                'icon' => 'cpu-chip',
                'ability' => 'settings.edit-technical',
                'fields' => [
                    ['key' => 'telegram_enabled', 'label' => 'Bot de Telegram', 'type' => 'toggle', 'default' => '0', 'group' => 'Telegram', 'wide' => true],
                    ['key' => 'telegram_bot_paused', 'label' => 'Pausar el bot', 'type' => 'toggle', 'default' => '0', 'group' => 'Telegram', 'wide' => true, 'help' => 'El bot deja de responder mensajes.'],
                    ['key' => 'telegram_notify_low_stock', 'label' => 'Avisar stock bajo', 'type' => 'toggle', 'default' => '1', 'group' => 'Telegram', 'wide' => true],
                    ['key' => 'telegram_notify_daily', 'label' => 'Enviar resumen diario', 'type' => 'toggle', 'default' => '1', 'group' => 'Telegram', 'wide' => true],
                    ['key' => 'telegram_bot_token', 'label' => 'Token del bot', 'type' => 'secret', 'default' => '', 'group' => 'Telegram'],
                    ['key' => 'telegram_admin_chat_id', 'label' => 'Chat ID del administrador', 'type' => 'text', 'default' => '', 'group' => 'Telegram'],
                    ['key' => 'telegram_webhook_secret', 'label' => 'Secreto del webhook', 'type' => 'secret', 'default' => '', 'group' => 'Telegram'],

                    ['key' => 'ai_chatbot_enabled', 'label' => 'Asistente conversacional', 'type' => 'toggle', 'default' => '0', 'group' => 'Asistente IA', 'wide' => true],
                    ['key' => 'ai_search_enabled', 'label' => 'Búsqueda inteligente en Telegram', 'type' => 'toggle', 'default' => '0', 'group' => 'Asistente IA', 'wide' => true],
                    ['key' => 'ai_provider', 'label' => 'Proveedor', 'type' => 'select', 'default' => 'anthropic', 'group' => 'Asistente IA', 'options' => [
                        'anthropic' => 'Anthropic (Claude)',
                        'openai_compatible' => 'Compatible con OpenAI (OpenAI, Groq, DeepSeek)',
                    ]],
                    ['key' => 'ai_model', 'label' => 'Modelo', 'type' => 'text', 'default' => 'claude-haiku-4-5-20251001', 'group' => 'Asistente IA'],
                    ['key' => 'anthropic_api_key', 'label' => 'API key de Anthropic', 'type' => 'secret', 'default' => '', 'group' => 'Asistente IA'],
                    ['key' => 'openai_api_key', 'label' => 'API key compatible con OpenAI', 'type' => 'secret', 'default' => '', 'group' => 'Asistente IA'],
                    ['key' => 'ai_api_base_url', 'label' => 'URL del endpoint', 'type' => 'url', 'default' => '', 'group' => 'Asistente IA', 'help' => 'Solo para proveedores compatibles con OpenAI. Ej: https://api.groq.com/openai/v1'],
                    ['key' => 'ai_max_tokens_response', 'label' => 'Largo máximo de respuesta (tokens)', 'type' => 'number', 'default' => '1024', 'group' => 'Asistente IA'],
                    ['key' => 'ai_max_cost_usd_per_day', 'label' => 'Gasto máximo por día', 'type' => 'number', 'default' => '5.00', 'suffix' => 'USD', 'optional' => true, 'help' => 'Vacío = sin límite.', 'group' => 'Asistente IA'],
                    ['key' => 'ai_system_prompt', 'label' => 'Instrucciones del asistente', 'type' => 'textarea', 'default' => '', 'group' => 'Asistente IA', 'wide' => true, 'max' => 5000],

                    ['key' => 'ai_voice_enabled', 'label' => 'Entender mensajes de voz', 'type' => 'toggle', 'default' => '0', 'group' => 'Voz', 'wide' => true],
                    ['key' => 'ai_voice_reply', 'label' => 'Responder con voz', 'type' => 'toggle', 'default' => '0', 'group' => 'Voz', 'wide' => true],
                    ['key' => 'whisper_provider', 'label' => 'Transcripción', 'type' => 'select', 'default' => 'openai', 'group' => 'Voz', 'options' => ['openai' => 'OpenAI / Groq (API)', 'local' => 'Local']],
                    ['key' => 'whisper_model', 'label' => 'Modelo de transcripción', 'type' => 'text', 'default' => '', 'group' => 'Voz'],
                    ['key' => 'whisper_language', 'label' => 'Idioma (código)', 'type' => 'text', 'default' => 'es', 'group' => 'Voz', 'max' => 5],
                    ['key' => 'whisper_max_seconds', 'label' => 'Duración máxima del audio', 'type' => 'number', 'default' => '60', 'suffix' => 'seg', 'group' => 'Voz'],
                    ['key' => 'tts_provider', 'label' => 'Síntesis de voz', 'type' => 'select', 'default' => 'openai', 'group' => 'Voz', 'options' => ['openai' => 'OpenAI (API)', 'piper' => 'Piper (local)']],
                    ['key' => 'tts_voice', 'label' => 'Voz', 'type' => 'text', 'default' => 'nova', 'group' => 'Voz'],
                    ['key' => 'tts_binary_path', 'label' => 'Ruta del programa Piper', 'type' => 'text', 'default' => '', 'group' => 'Voz'],
                    ['key' => 'tts_model_path', 'label' => 'Ruta del modelo Piper (.onnx)', 'type' => 'text', 'default' => '', 'group' => 'Voz'],

                    ['key' => 'ai_vision_enabled', 'label' => 'Buscar productos por foto', 'type' => 'toggle', 'default' => '0', 'group' => 'Búsqueda por foto', 'wide' => true],
                    ['key' => 'ai_vision_model', 'label' => 'Modelo de visión', 'type' => 'text', 'default' => 'gpt-4o-mini', 'group' => 'Búsqueda por foto'],

                    ['key' => 'backup_schedule_enabled', 'label' => 'Respaldo automático diario (02:00)', 'type' => 'toggle', 'default' => '1', 'group' => 'Respaldos', 'wide' => true],
                ],
            ],
        ];
    }

    /**
     * Secciones que el usuario puede ver, con sus campos ya filtrados por permiso.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function visibleFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $visible = [];
        foreach (self::all() as $key => $section) {
            if (! $user->can($section['ability'])) {
                continue;
            }

            $section['fields'] = array_values(array_filter(
                $section['fields'],
                fn (array $field) => ! isset($field['ability']) || $user->can($field['ability'])
            ));

            if ($section['fields'] !== []) {
                $visible[$key] = $section;
            }
        }

        return $visible;
    }

    /**
     * Reglas de validación de Laravel para un campo (sobre values.<key>).
     * $current = valor guardado hoy: un valor legado fuera de las opciones sigue siendo válido.
     *
     * @return array<int, mixed>
     */
    public static function rules(array $field, ?string $current = null): array
    {
        $base = ! empty($field['required']) ? ['required'] : ['nullable'];
        $numeric = ! empty($field['optional']) ? ['nullable'] : ['required'];

        return match ($field['type']) {
            'text' => [...$base, 'string', 'max:' . ($field['max'] ?? 255)],
            'textarea' => [...$base, 'string', 'max:' . ($field['max'] ?? 1000)],
            'select' => [...$base, Rule::in(self::optionValues($field, $current))],
            'toggle' => ['required', Rule::in(['0', '1'])],
            'percent' => [...$numeric, 'numeric', 'min:0', 'max:100'],
            'number' => [...$numeric, 'numeric', 'min:0'],
            'account' => ['required', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            'secret' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
            'url' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'regex:/^\d{8,15}$/'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            default => [...$base, 'string'],
        };
    }

    /**
     * Opciones de un select; incluye el valor guardado si es legado (fuera de la lista)
     * para no invalidar la sección entera al guardar otro campo.
     *
     * @return array<string, string>
     */
    public static function options(array $field, ?string $current = null): array
    {
        $options = [];
        foreach ($field['options'] as $value => $label) {
            $options[(string) $value] = $label;
        }

        if ($current !== null && ! array_key_exists($current, $options)) {
            $options[$current] = $current === '' ? 'Ninguno' : $current;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function optionValues(array $field, ?string $current): array
    {
        return array_map('strval', array_keys(self::options($field, $current)));
    }
}
