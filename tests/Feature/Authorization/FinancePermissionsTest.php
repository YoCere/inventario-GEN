<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_grants_new_finance_permissions_to_admin_not_staff(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'web');
        $staff = Role::findByName('staff', 'web');

        foreach (['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'] as $perm) {
            $this->assertTrue($admin->hasPermissionTo($perm), "admin debe tener {$perm}");
            $this->assertFalse($staff->hasPermissionTo($perm), "staff NO debe tener {$perm}");
        }
    }
}
