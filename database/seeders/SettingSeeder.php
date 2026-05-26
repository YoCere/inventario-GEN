<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::set('store_name', 'Importadora El Cóndor');
        Setting::set('store_nit', '');
        Setting::set('store_address', 'Av. Antofagasta N° 145, Oruro, Bolivia');
        Setting::set('store_phone', '72345678');
        Setting::set('opening_balance_date', now()->startOfYear()->toDateString());
        Setting::set('opening_balance_amount', '50000');
        Setting::set('discount_rate_annual', '12');
        Setting::set('dashboard_display_mode', 'percent');
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');
        Setting::set('tax_include_iva', '1');
        Setting::set('tax_include_it', '1');

        // Cuentas contables para IVA e IT
        Setting::set('accounting_iva_receivable_code', '1.1.05');
        Setting::set('accounting_iva_payable_code', '2.1.11');
        Setting::set('accounting_it_payable_code', '2.1.12');
        Setting::set('currency_symbol', 'Bs');
        Setting::set('currency_position', 'left');
        Setting::set('currency_fraction_digits', '2');
        Setting::set('currency_thousand_separator', '.');
        Setting::set('currency_decimal_separator', ',');

        // Nomina Bolivia
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

        // Cuentas contables para asiento de planilla
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
