<?php

use App\Enums\AccountNormalBalance;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $accounts = [
            ['1.2.02', 'Depreciación Acumulada', AccountType::Asset, AccountNormalBalance::Credit, '1.2'],
            ['1.2.03', 'Activo Diferido', AccountType::Asset, AccountNormalBalance::Debit, '1.2'],
            ['1.2.04', 'Amortización Acumulada Diferido', AccountType::Asset, AccountNormalBalance::Credit, '1.2'],
            ['6.4', 'Gasto Depreciación', AccountType::Expense, AccountNormalBalance::Debit, '6'],
            ['6.5', 'Gasto Amortización', AccountType::Expense, AccountNormalBalance::Debit, '6'],
            ['6.6', 'Pérdida en Venta de Activos', AccountType::Expense, AccountNormalBalance::Debit, '6'],
        ];

        foreach ($accounts as [$code, $name, $type, $nb, $parentCode]) {
            $parent = ChartOfAccount::where('code', $parentCode)->first();
            ChartOfAccount::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_type' => $type->value,
                    'normal_balance' => $nb->value,
                    'parent_id' => $parent?->id,
                    'level' => substr_count($code, '.') + 1,
                    'allows_posting' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        ChartOfAccount::whereIn('code', ['1.2.02', '1.2.03', '1.2.04', '6.4', '6.5', '6.6'])->delete();
    }
};
