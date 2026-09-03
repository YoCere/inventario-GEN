<?php

namespace Tests\Feature\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmprendedorRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_emprendedor_role_exists_with_expected_permissions(): void
    {
        $rol = Role::findByName('emprendedor', 'web');

        foreach (['products.manage', 'sales.create', 'purchases.manage', 'shop.admin', 'dashboard.view'] as $perm) {
            $this->assertTrue($rol->hasPermissionTo($perm), "emprendedor debe tener {$perm}");
        }
        foreach (['finance.accounting', 'finance.view', 'users.payroll', 'audit.view', 'users.manage'] as $perm) {
            $this->assertFalse($rol->hasPermissionTo($perm), "emprendedor NO debe tener {$perm}");
        }
    }
}
