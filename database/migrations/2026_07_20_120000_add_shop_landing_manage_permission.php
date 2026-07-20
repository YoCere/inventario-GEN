<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aditiva: crea shop.landing.manage y lo asigna a developer + admin SIN
 * re-sincronizar (no borra permisos personalizados de ningún rol en prod).
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'shop.landing.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => self::NEW_PERMISSION, 'guard_name' => 'web']);

        Role::where('name', 'developer')->where('guard_name', 'web')->first()?->givePermissionTo(self::NEW_PERMISSION);
        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo(self::NEW_PERMISSION);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: no revocar permisos en un rollback.
    }
};
