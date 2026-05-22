<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migra del enum users.role hardcoded al sistema dinámico spatie/laravel-permission.
 *
 * Pasos:
 *   1. Seedea roles + permisos base (developer/admin/staff con sus permisos).
 *   2. Para cada usuario existente, le asigna la role spatie correspondiente
 *      según el valor actual de users.role (case insensitive, default 'staff').
 *   3. Dropa la columna users.role — ahora el rol vive en model_has_roles.
 *
 * Rollback: re-crea columna + lee role spatie del usuario para repoblar (best
 * effort si tiene múltiples roles asignados toma el primero).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Seed roles + permisos canonical.
        (new RolesAndPermissionsSeeder())->run();

        // 2. Asignar role a usuarios existentes según columna actual.
        if (Schema::hasColumn('users', 'role')) {
            $users = DB::table('users')->select('id', 'role')->get();

            foreach ($users as $user) {
                $current = strtolower((string) $user->role);
                $roleName = match ($current) {
                    'developer' => 'developer',
                    'admin' => 'admin',
                    default => 'staff',
                };

                // Usamos el modelo Eloquent (no DB::table) para que spatie maneje
                // la pivot model_has_roles correctamente.
                $eloquentUser = \App\Models\User::find($user->id);
                if ($eloquentUser && ! $eloquentUser->hasRole($roleName)) {
                    $eloquentUser->assignRole($roleName);
                }
            }

            // 3. Dropear la columna role del esquema users.
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        // Recrear columna role + repoblar desde spatie.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('staff')->after('password');
        });

        $users = \App\Models\User::with('roles')->get();
        foreach ($users as $user) {
            $primaryRole = $user->roles->first()?->name ?? 'staff';
            DB::table('users')->where('id', $user->id)->update(['role' => $primaryRole]);
        }
    }
};
