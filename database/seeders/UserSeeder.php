<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'admin',
                'username' => 'admin',
                'password' => Hash::make('password'),
            ]
        );

        // Asigna rol developer al user de seed (instalador). Asume que
        // RolesAndPermissionsSeeder ya corrió (la migración se encarga de eso).
        \Spatie\Permission\Models\Role::findOrCreate('developer', 'web');
        if (! $user->hasRole('developer')) {
            $user->assignRole('developer');
        }
    }
}
