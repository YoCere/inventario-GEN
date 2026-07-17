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

    public function test_migration_creates_permissions_and_assigns_to_admin_developer_without_seeder(): void
    {
        $admin = Role::findByName('admin', 'web');
        $developer = Role::findByName('developer', 'web');

        foreach (['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'] as $perm) {
            $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', $perm)->exists(), "{$perm} debe existir");
            $this->assertTrue($admin->hasPermissionTo($perm), "admin (via migración) debe tener {$perm}");
            $this->assertTrue($developer->hasPermissionTo($perm), "developer (via migración) debe tener {$perm}");
        }
    }

    public function test_staff_cannot_access_finance_routes(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.chart-of-accounts.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.fixed-assets.index'))->assertForbidden();
    }

    public function test_admin_can_access_finance_routes(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('finance.index'))->assertOk();
        $this->actingAs($admin)->get(route('finance.chart-of-accounts.index'))->assertOk();
        $this->actingAs($admin)->get(route('finance.fixed-assets.index'))->assertOk();
    }

    public function test_gating_is_permission_driven_not_role(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(); // sin rol
        $user->givePermissionTo('finance.view');

        $this->actingAs($user)->get(route('finance.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.chart-of-accounts.index'))->assertForbidden();
    }

    public function test_developer_accesses_everything(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $dev = User::factory()->developer()->create();

        $this->actingAs($dev)->get(route('finance.index'))->assertOk();
        $this->actingAs($dev)->get(route('finance.production.index'))->assertOk();
    }

    public function test_kardex_gated_by_products_kardex_permission(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff)->get(route('products.kardex.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('products.kardex.index'))->assertOk();
    }

    public function test_each_finance_permission_isolates_its_group(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $map = [
            'finance.view'       => 'finance.index',
            'finance.accounting' => 'finance.chart-of-accounts.index',
            'assets.manage'      => 'finance.fixed-assets.index',
            'loans.manage'       => 'finance.loans.index',
            'budgets.manage'     => 'finance.budgets.index',
            'production.manage'  => 'finance.production.index',
        ];
        foreach ($map as $perm => $routeName) {
            $u = User::factory()->create();
            $u->givePermissionTo($perm);

            $this->actingAs($u)->get(route($routeName))->assertOk();      // entra a SU grupo
            if ($perm !== 'finance.view') {
                $this->actingAs($u)->get(route('finance.index'))->assertForbidden(); // no a otro
            }
        }
    }

    public function test_bot_reports_denied_to_staff_without_finance_view(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $sent = [];
        $telegram = \Mockery::mock(\App\Services\Messaging\TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function ($chatId, $msg) use (&$sent) {
            $sent[] = $msg; return [];
        });
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(\App\Services\Messaging\TelegramService::class, $telegram);

        $staff = \App\Models\User::factory()->staff()->create();
        \App\Models\TelegramUser::create(['chat_id' => '778', 'user_id' => $staff->id, 'identifier' => 's2', 'last_login' => now()]);

        app(\App\Services\Telegram\BotHandler::class)->dispatch(['message' => ['from' => ['id' => 778], 'text' => '/reportes']]);

        $this->assertTrue(
            collect($sent)->contains(fn ($m) => str_contains($m, 'restringid') || str_contains($m, 'permiso')),
            'staff sin finance.view debe ver acceso restringido'
        );
    }

    public function test_bot_reports_allowed_with_finance_view(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $sent = [];
        $telegram = \Mockery::mock(\App\Services\Messaging\TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function ($chatId, $msg) use (&$sent) {
            $sent[] = $msg; return [];
        });
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(\App\Services\Messaging\TelegramService::class, $telegram);

        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('finance.view');
        \App\Models\TelegramUser::create(['chat_id' => '779', 'user_id' => $user->id, 'identifier' => 'f1', 'last_login' => now()]);

        app(\App\Services\Telegram\BotHandler::class)->dispatch(['message' => ['from' => ['id' => 779], 'text' => '/reportes']]);

        // NO debe ver el mensaje de acceso restringido.
        $this->assertFalse(
            collect($sent)->contains(fn ($m) => str_contains($m, 'restringid')),
            'usuario con finance.view NO debe ver acceso restringido'
        );
    }
}
