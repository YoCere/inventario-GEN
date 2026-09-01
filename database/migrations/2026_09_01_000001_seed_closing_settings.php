<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'company_entity_type'           => 'unipersonal',
            'tax_iue_rate'                  => '25',
            'legal_reserve_rate'            => '5',
            'legal_reserve_cap_pct'         => '50',
            'accounting_legal_reserve_code' => '3.4',
        ];
        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'company_entity_type', 'tax_iue_rate', 'legal_reserve_rate',
            'legal_reserve_cap_pct', 'accounting_legal_reserve_code',
        ])->delete();
    }
};
