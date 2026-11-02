<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aditiva: crea metrics.view y lo da a developer, admin y emprendedor SIN
 * re-sincronizar (no borra permisos personalizados de ningún rol en prod).
 *
 * Por qué un permiso propio y no 'audit.view': lo que muestra la pantalla son
 * tiempos de trabajo de personas con nombre y apellido. Es información del dueño
 * sobre su equipo, así que no viaja con el permiso de ver ventas ni con el de
 * ver productos, que los tiene cualquier cajero. Y no alcanzaba con reusar
 * 'audit.view' porque el emprendedor no lo tiene, y el emprendedor es justo
 * quien pidió el número para mostrárselo a un cliente nuevo.
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'metrics.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => self::NEW_PERMISSION, 'guard_name' => 'web']);

        foreach (['developer', 'admin', 'emprendedor'] as $role) {
            Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo(self::NEW_PERMISSION);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: no revocar permisos en un rollback.
    }
};
