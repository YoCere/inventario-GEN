<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Ajustes que son iguales para CUALQUIER negocio boliviano: tasas de ley,
 * cuentas contables del plan y formato de moneda.
 *
 * Acá no va nada propio de un cliente (nombre, NIT, teléfono, dirección): eso lo
 * pregunta `php artisan instalar:cliente`. Separarlo evita que una instancia nueva
 * arranque llamándose como el negocio de otro.
 */
class BaseSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Impuestos Bolivia.
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');
        Setting::set('tax_iue_rate', '25');
        Setting::set('legal_reserve_rate', '5');
        Setting::set('legal_reserve_cap_pct', '50');
        Setting::set('company_entity_type', 'unipersonal');

        // Cuentas contables para IVA e IT.
        Setting::set('accounting_cf_iva_code', '1.1.05');
        Setting::set('accounting_df_iva_code', '2.1.11');
        Setting::set('accounting_it_payable_code', '2.1.12');

        // Períodos contables y evaluación de inversiones.
        Setting::set('default_accounting_period_type', 'monthly');
        Setting::set('auto_create_next_period', '1');
        Setting::set('discount_rate_annual', '12');

        // Moneda.
        Setting::set('currency_symbol', 'Bs');
        Setting::set('currency_position', 'left');
        Setting::set('currency_fraction_digits', '2');
        Setting::set('currency_thousand_separator', '.');
        Setting::set('currency_decimal_separator', ',');

        // Nómina Bolivia.
        Setting::set('payroll_antiquity_base_amount', '7500');
        Setting::set('payroll_border_bonus_rate', '20');
        Setting::set('payroll_labor_contribution_rate', '12.71');
        Setting::set('payroll_rc_iva_rate', '13');
        Setting::set('payroll_rc_iva_minimum', '5000');
        Setting::set('payroll_rc_iva_compensable', '5000');
        Setting::set('payroll_solidarity_1_rate', '1');
        Setting::set('payroll_solidarity_1_threshold', '13000');
        Setting::set('payroll_solidarity_2_rate', '5');
        Setting::set('payroll_solidarity_2_threshold', '25000');
        Setting::set('payroll_employer_contribution_rate', '16.71');
        Setting::set('payroll_aguinaldo_provision_rate', '8.33');
        Setting::set('payroll_indemnization_provision_rate', '8.33');

        // Cuentas contables del asiento de planilla.
        Setting::set('payroll_account_mod', '5.2');
        Setting::set('payroll_account_moi', '5.3');
        Setting::set('payroll_account_sales', '6.2');
        Setting::set('payroll_account_admin', '6.1');
        Setting::set('payroll_account_net_payable', '2.1.03');
        Setting::set('payroll_account_employer_contribution', '2.1.04');
        Setting::set('payroll_account_labor_contribution', '2.1.05');
        Setting::set('payroll_account_aguinaldo_provision', '2.1.06');
        Setting::set('payroll_account_indemnization_provision', '2.1.07');
        Setting::set('payroll_account_rc_iva', '2.1.08');
        Setting::set('payroll_account_solidarity', '2.1.09');
        Setting::set('payroll_account_other_discounts', '2.1.10');
    }
}
