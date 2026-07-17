<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aditiva: crea los 4 permisos del menú finanzas y los asigna a developer + admin
 * SIN re-sincronizar (no borra permisos personalizados de ningún rol en prod).
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $developer = Role::where('name', 'developer')->where('guard_name', 'web')->first();
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        foreach (self::NEW_PERMISSIONS as $name) {
            $developer?->givePermissionTo($name);
            $admin?->givePermissionTo($name);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: no revocar permisos en un rollback (evita romper configuración del negocio).
    }
};
