<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seedea (insert-if-missing) los settings de mapeo contable usados por el
     * cierre de gestión (IUE + reserva legal). Si el usuario ya configuró otro
     * código, no lo pisamos.
     *
     * NOTA: las cuentas contables (6.8 Gasto IUE, 2.1.13 IUE por Pagar, 3.4
     * Reserva Legal) NO se crean aquí. En un install fresco las migraciones
     * corren ANTES del ChartOfAccountSeeder, así que insertarlas acá las
     * dejaría huérfanas (parent_id null) — el mismo anti-patrón ya neutralizado
     * en 2026_06_05_000001 y documentado en 2026_08_27_000002 para la cuenta
     * 6.7. Esas cuentas viven en ChartOfAccountSeeder (idempotente), que es la
     * fuente de verdad del plan de cuentas.
     */
    public function up(): void
    {
        $defaults = [
            'accounting_iue_expense_code'   => '6.8',
            'accounting_iue_payable_code'   => '2.1.13',
            'accounting_period_result_code' => '3.3',
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_iue_expense_code',
            'accounting_iue_payable_code',
            'accounting_period_result_code',
        ])->delete();
    }
};
