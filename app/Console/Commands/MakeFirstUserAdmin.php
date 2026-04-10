<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Console\Command;

class MakeFirstUserAdmin extends Command
{
    protected $signature = 'users:make-first-admin';
    protected $description = 'Promote the first registered user to admin role';

    public function handle(): void
    {
        $user = User::orderBy('id')->first();

        if (!$user) {
            $this->error('No users found.');
            return;
        }

        $user->update(['role' => UserRole::Admin->value]);
        $this->info("User '{$user->name}' (ID: {$user->id}) promoted to admin.");
    }
}
