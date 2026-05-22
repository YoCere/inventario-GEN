<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class MakeFirstUserAdmin extends Command
{
    protected $signature = 'users:make-first-admin {--role=admin : Rol a asignar (admin, developer, staff)}';
    protected $description = 'Promote the first registered user to the given role (default admin)';

    public function handle(): void
    {
        $user = User::orderBy('id')->first();

        if (!$user) {
            $this->error('No users found.');
            return;
        }

        $roleName = (string) $this->option('role');
        Role::findOrCreate($roleName, 'web');
        $user->syncRoles([$roleName]);

        $this->info("User '{$user->name}' (ID: {$user->id}) promoted to {$roleName}.");
    }
}
