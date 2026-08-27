<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'accounting_opening_cash_code'         => '1.1.01',
            'accounting_opening_bank_code'         => '1.1.02',
            'accounting_opening_receivable_code'   => '1.1.03',
            'accounting_opening_inventory_code'    => '1.1.04',
            'accounting_opening_ppe_code'          => '1.2.01',
            'accounting_opening_depreciation_code' => '1.2.02',
            'accounting_opening_payable_code'      => '2.1.01',
            'accounting_opening_loan_code'         => '2.1.01',
            'accounting_opening_capital_code'      => '3.1',
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_opening_cash_code', 'accounting_opening_bank_code',
            'accounting_opening_receivable_code', 'accounting_opening_inventory_code',
            'accounting_opening_ppe_code', 'accounting_opening_depreciation_code',
            'accounting_opening_payable_code', 'accounting_opening_loan_code',
            'accounting_opening_capital_code',
        ])->delete();
    }
};
