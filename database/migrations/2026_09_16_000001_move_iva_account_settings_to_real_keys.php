<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes guardaba las cuentas de IVA en accounting_iva_receivable_code /
 * accounting_iva_payable_code, pero la contabilidad lee accounting_cf_iva_code /
 * accounting_df_iva_code: editarlas no tenía efecto. Copia el valor legado a la clave
 * real (solo si la real no existe) y borra las claves huérfanas.
 */
return new class extends Migration
{
    private const MOVES = [
        'accounting_iva_receivable_code' => 'accounting_cf_iva_code',
        'accounting_iva_payable_code' => 'accounting_df_iva_code',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $legacy => $real) {
            $legacyValue = DB::table('settings')->where('key', $legacy)->value('value');
            $realExists = DB::table('settings')->where('key', $real)->exists();

            if ($legacyValue !== null && $legacyValue !== '' && ! $realExists) {
                DB::table('settings')->insert([
                    'key' => $real,
                    'value' => $legacyValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('settings')->where('key', $legacy)->delete();
            Cache::forget("settings.{$legacy}");
            Cache::forget("settings.{$real}");
        }
    }

    public function down(): void
    {
        // Irreversible a propósito: las claves legadas no las lee ningún código.
    }
};
