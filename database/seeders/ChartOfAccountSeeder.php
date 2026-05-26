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
            ['code' => '1.1.04', 'name' => 'Inventario Mercaderias', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            // IVA — Crédito Fiscal (IVA pagado en compras, deducible del Débito Fiscal)
            ['code' => '1.1.05', 'name' => 'Credito Fiscal IVA', 'level' => 3, 'parent_code' => '1.1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '1.2', 'name' => 'ACTIVO NO CORRIENTE', 'level' => 2, 'parent_code' => '1', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.2.01', 'name' => 'Propiedad, Planta y Equipo', 'level' => 3, 'parent_code' => '1.2', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true],

            // 2 - PASIVO
            ['code' => '2', 'name' => 'PASIVO', 'level' => 1, 'parent_code' => null, 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.1', 'name' => 'PASIVO CORRIENTE', 'level' => 2, 'parent_code' => '2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.1.01', 'name' => 'Cuentas por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.02', 'name' => 'Impuestos por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.03', 'name' => 'Sueldos y Salarios por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.04', 'name' => 'Aporte Patronal por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.05', 'name' => 'Aporte Laboral por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.06', 'name' => 'Provision de Aguinaldo', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.07', 'name' => 'Provision de Indemnizacion', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.08', 'name' => 'RC-IVA por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.09', 'name' => 'Aporte Solidario por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.1.10', 'name' => 'Otras Retenciones por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            // IVA — Débito Fiscal (IVA cobrado en ventas, obligación tributaria)
            ['code' => '2.1.11', 'name' => 'Debito Fiscal IVA', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            // IT — Impuesto a las Transacciones 3% sobre ingresos brutos
            ['code' => '2.1.12', 'name' => 'IT por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '2.2', 'name' => 'PASIVO NO CORRIENTE', 'level' => 2, 'parent_code' => '2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.2.01', 'name' => 'Prestamos por Pagar LP', 'level' => 3, 'parent_code' => '2.2', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 3 - PATRIMONIO
            ['code' => '3', 'name' => 'PATRIMONIO', 'level' => 1, 'parent_code' => null, 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '3.1', 'name' => 'Capital Social', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '3.2', 'name' => 'Resultados Acumulados', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '3.3', 'name' => 'Resultado del Ejercicio', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 4 - INGRESOS
            ['code' => '4', 'name' => 'INGRESOS', 'level' => 1, 'parent_code' => null, 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '4.1', 'name' => 'Ventas de Mercaderias', 'level' => 2, 'parent_code' => '4', 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '4.2', 'name' => 'Otros Ingresos', 'level' => 2, 'parent_code' => '4', 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => true],

            // 5 - COSTOS
            ['code' => '5', 'name' => 'COSTOS', 'level' => 1, 'parent_code' => null, 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '5.1', 'name' => 'Costo de Ventas', 'level' => 2, 'parent_code' => '5', 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '5.2', 'name' => 'Mano de Obra Directa', 'level' => 2, 'parent_code' => '5', 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => true],
            ['code' => '5.3', 'name' => 'Mano de Obra Indirecta', 'level' => 2, 'parent_code' => '5', 'account_type' => 'cost', 'normal_balance' => 'debit', 'allows_posting' => true],

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
            if (! $account['parent_code']) {
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
