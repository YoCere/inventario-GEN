<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * El usuario de arranque para desarrollo.
 *
 * Antes creaba `admin@admin.com` con la contraseña `password` y rol developer,
 * y era el primero de DatabaseSeeder. Con el repositorio público, esa
 * credencial está escrita en internet: cualquiera que corra `db:seed` contra
 * una instalación real —o que la haya corrido alguna vez— deja abierta una
 * cuenta con permisos totales y clave conocida.
 *
 * Ahora: en producción no corre, y fuera de desarrollo la contraseña se genera
 * al azar y se imprime una sola vez. Para instalar un cliente de verdad está
 * `php artisan instalar:cliente`, que ya hace esto bien.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'UserSeeder no corre en producción. Usá: php artisan instalar:cliente'
            );

            return;
        }

        // En desarrollo y en las pruebas la clave fija es una comodidad real y
        // la base es descartable. Fuera de ahí, se genera.
        $esDesarrollo = app()->environment(['local', 'testing']);
        $clave = $esDesarrollo ? 'password' : Str::password(16);

        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'admin',
                'username' => 'admin',
                'password' => Hash::make($clave),
            ]
        );

        // Asigna rol developer al user de seed (instalador). Asume que
        // RolesAndPermissionsSeeder ya corrió (la migración se encarga de eso).
        \Spatie\Permission\Models\Role::findOrCreate('developer', 'web');
        if (! $user->hasRole('developer')) {
            $user->assignRole('developer');
        }

        if (! $esDesarrollo && $user->wasRecentlyCreated) {
            // Se imprime una sola vez: no queda escrita en ningún lado.
            $this->command?->warn("Usuario admin creado. Contraseña: {$clave}");
        }
    }
}
