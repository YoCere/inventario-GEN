<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IvaAccountKeysMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_16_000001_move_iva_account_settings_to_real_keys.php');
        $migration->up();
    }

    private function putSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_copia_la_clave_legada_si_la_real_no_existe(): void
    {
        DB::table('settings')->whereIn('key', ['accounting_cf_iva_code', 'accounting_df_iva_code'])->delete();
        $this->putSetting('accounting_iva_receivable_code', '1.1.09');
        $this->putSetting('accounting_iva_payable_code', '2.1.20');

        $this->runMigration();

        $this->assertSame('1.1.09', DB::table('settings')->where('key', 'accounting_cf_iva_code')->value('value'));
        $this->assertSame('2.1.20', DB::table('settings')->where('key', 'accounting_df_iva_code')->value('value'));
        $this->assertFalse(DB::table('settings')->where('key', 'accounting_iva_receivable_code')->exists());
        $this->assertFalse(DB::table('settings')->where('key', 'accounting_iva_payable_code')->exists());
    }

    public function test_no_pisa_la_clave_real_existente(): void
    {
        $this->putSetting('accounting_cf_iva_code', '1.1.05');
        $this->putSetting('accounting_iva_receivable_code', '9.9.99');

        $this->runMigration();

        $this->assertSame('1.1.05', DB::table('settings')->where('key', 'accounting_cf_iva_code')->value('value'));
        $this->assertFalse(DB::table('settings')->where('key', 'accounting_iva_receivable_code')->exists());
    }
}
