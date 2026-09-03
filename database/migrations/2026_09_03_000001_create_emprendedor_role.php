<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Rol "Emprendedor": vendedores informales con acceso completo a
     * productos/ventas/compras/tienda pero SIN contabilidad ni administración
     * de usuarios/roles.
     *
     * @var string[]
     */
    private array $perms = [
        'dashboard.view',
        'products.view', 'products.manage', 'categories.manage', 'units.manage',
        'customers.manage', 'suppliers.manage',
        'purchases.view', 'purchases.manage',
        'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
        'shop.admin', 'shop.landing.manage',
        'settings.view', 'settings.edit-business',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate(['name' => 'emprendedor', 'guard_name' => 'web']);
        $rol->syncPermissions($this->perms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'emprendedor')->where('guard_name', 'web')->first()?->delete();
    }
};
