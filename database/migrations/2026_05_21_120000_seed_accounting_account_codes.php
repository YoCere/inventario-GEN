<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Códigos de cuenta del Chart of Accounts usados por los servicios contables.
        // Antes estaban hardcoded en SaleAccountingService y PurchaseAccountingService.
        // Centralizar en Settings permite cambiar el COA sin tocar código.
        $defaults = [
            'accounting_sale_cash_code'      => '1.1.01',  // Caja
            'accounting_sale_transfer_code'  => '1.1.02',  // Banco
            'accounting_sale_other_code'     => '1.1.03',  // Otras cuentas por cobrar
            'accounting_sale_income_code'    => '4.1',     // Ingresos por ventas
            'accounting_cogs_code'           => '5.1',     // Costo de venta
            'accounting_inventory_code'      => '1.1.04',  // Inventario
            'accounting_purchase_cash_code'  => '1.1.01',  // Caja (mismo que sale_cash)
        ];

        foreach ($defaults as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();
            if (!$exists) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_sale_cash_code',
            'accounting_sale_transfer_code',
            'accounting_sale_other_code',
            'accounting_sale_income_code',
            'accounting_cogs_code',
            'accounting_inventory_code',
            'accounting_purchase_cash_code',
        ])->delete();
    }
};
