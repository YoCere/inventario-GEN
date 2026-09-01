<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seedea (insert-if-missing) los settings de mapeo fiscal usados para
     * armar los asientos de IVA/IT en ventas y compras, homologa las tasas
     * de IVA/IT a 13%/3% cuando estén vacías o en '0', y crea la cuenta
     * contable "Gasto IT" (5.2.01) si no existe.
     */
    public function up(): void
    {
        // Códigos de cuenta contable para el mapeo fiscal (insert-if-missing:
        // si el usuario ya configuró otro código, no lo pisamos).
        $codes = [
            'accounting_df_iva_code'     => '2.1.11', // Débito Fiscal IVA (ventas)
            'accounting_cf_iva_code'     => '1.1.05', // Crédito Fiscal IVA (compras)
            'accounting_it_payable_code' => '2.1.12', // IT por Pagar
            'accounting_it_expense_code' => '6.7', // Gasto IT bajo GASTOS (impuesto a las transacciones)
        ];

        foreach ($codes as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Tasas de IVA/IT: homologar a 13/3 si faltan o están en vacío/'0'.
        // No se sobreescribe una tasa real ya configurada por el usuario.
        foreach (['tax_iva_rate' => '13', 'tax_it_rate' => '3'] as $key => $value) {
            $current = DB::table('settings')->where('key', $key)->value('value');

            if ($current === null) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($current === '' || $current === '0') {
                DB::table('settings')->where('key', $key)->update([
                    'value' => $value,
                    'updated_at' => now(),
                ]);
            }
        }

        // Cuenta "Gasto IT" (6.7) bajo GASTOS, idempotente.
        if (! DB::table('chart_of_accounts')->where('code', '6.7')->exists()) {
            $parentId = DB::table('chart_of_accounts')->where('code', '6')->value('id');

            DB::table('chart_of_accounts')->insert([
                'code' => '6.7',
                'name' => 'Impuesto a las Transacciones',
                'level' => 2,
                'parent_id' => $parentId,
                'account_type' => 'expense',
                'normal_balance' => 'debit',
                'allows_posting' => true,
                'is_active' => true,
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_df_iva_code',
            'accounting_cf_iva_code',
            'accounting_it_payable_code',
            'accounting_it_expense_code',
        ])->delete();

        DB::table('chart_of_accounts')->where('code', '6.7')->delete();
    }
};
