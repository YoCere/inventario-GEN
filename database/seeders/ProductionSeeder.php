<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Lo mínimo que necesita una instancia de cliente real para funcionar: roles,
 * plan de cuentas, período contable abierto, categorías de caja, unidades y la
 * plantilla de la página de la tienda.
 *
 * Deliberadamente NO siembra datos de ejemplo (productos, clientes, proveedores)
 * ni el usuario admin/password de desarrollo: de eso se encarga
 * `php artisan instalar:cliente`, que pide los datos reales del negocio.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BaseSettingsSeeder::class,
            ChartOfAccountSeeder::class,
            AccountingPeriodSeeder::class,
            AssetCategorySeeder::class,
            FinanceCategorySeeder::class,
            UnitSeeder::class,
            DefaultLandingTemplateSeeder::class,
        ]);
    }
}
