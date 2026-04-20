<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            // 1 - ACTIVO
            ['code' => '1', 'name' => 'ACTIVO', 'level' => 1, 'parent_code' => null, 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.1', 'name' => 'ACTIVO CORRIENTE', 'level' => 2, 'parent_code' => '1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.1.01', 'name' => 'Caja General', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '1.1.02', 'name' => 'Bancos', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '1.1.03', 'name' => 'Cuentas por Cobrar', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '1.1.04', 'name' => 'Inventario Mercaderías', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '1.2', 'name' => 'ACTIVO NO CORRIENTE', 'level' => 2, 'parent_code' => '1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.2.01', 'name' => 'Propiedad, Planta y Equipo', 'level' => 3, 'parent_code' => '1.2', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],

            // 2 - PASIVO
            ['code' => '2', 'name' => 'PASIVO', 'level' => 1, 'parent_code' => null, 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.1', 'name' => 'PASIVO CORRIENTE', 'level' => 2, 'parent_code' => '2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.1.01', 'name' => 'Cuentas por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.02', 'name' => 'Impuestos por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.2', 'name' => 'PASIVO NO CORRIENTE', 'level' => 2, 'parent_code' => '2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.2.01', 'name' => 'Préstamos por Pagar LP', 'level' => 3, 'parent_code' => '2.2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 3 - PATRIMONIO
            ['code' => '3', 'name' => 'PATRIMONIO', 'level' => 1, 'parent_code' => null, 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '3.1', 'name' => 'Capital Social', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '3.2', 'name' => 'Resultados Acumulados', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '3.3', 'name' => 'Resultado del Ejercicio', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 4 - INGRESOS
            ['code' => '4', 'name' => 'INGRESOS', 'level' => 1, 'parent_code' => null, 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '4.1', 'name' => 'Ventas de Mercaderías', 'level' => 2, 'parent_code' => '4', 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '4.2', 'name' => 'Otros Ingresos', 'level' => 2, 'parent_code' => '4', 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 5 - COSTOS
            ['code' => '5', 'name' => 'COSTOS', 'level' => 1, 'parent_code' => null, 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '5.1', 'name' => 'Costo de Ventas', 'level' => 2, 'parent_code' => '5', 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => true],

            // 6 - GASTOS
            ['code' => '6', 'name' => 'GASTOS', 'level' => 1, 'parent_code' => null, 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '6.1', 'name' => 'Gastos Administrativos', 'level' => 2, 'parent_code' => '6', 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '6.2', 'name' => 'Gastos de Venta', 'level' => 2, 'parent_code' => '6', 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '6.3', 'name' => 'Gastos Financieros', 'level' => 2, 'parent_code' => '6', 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'level' => $account['level'],
                    'parent_id' => null,
                    'account_type' => $account['account_type'],
                    'normal_balance' => $account['normal_balance'],
                    'allows_posting' => $account['allows_posting'],
                    'is_active' => true,
                ]
            );
        }

        foreach ($accounts as $account) {
            if (!$account['parent_code']) {
                continue;
            }

            $current = ChartOfAccount::query()->where('code', $account['code'])->first();
            $parent = ChartOfAccount::query()->where('code', $account['parent_code'])->first();

            if ($current && $parent) {
                $current->update(['parent_id' => $parent->id]);
            }
        }
    }
}
