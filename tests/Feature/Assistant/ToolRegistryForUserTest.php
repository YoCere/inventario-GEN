<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\Tools\GetFinancialStatusTool;
use App\Services\Agent\Tools\GetTopSellersTool;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistryForUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_does_not_see_finance_tools(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $registry = (new ToolRegistry())
            ->register($this->app->make(GetFinancialStatusTool::class))
            ->register(new GetTopSellersTool());

        $filtered = $registry->forUser($staff);
        $names = array_keys($filtered->all());

        $this->assertContains('get_top_sellers', $names);
        $this->assertNotContains('get_financial_status', $names);
    }

    public function test_admin_sees_finance_tools(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $registry = (new ToolRegistry())->register($this->app->make(GetFinancialStatusTool::class));
        $names = array_keys($registry->forUser($admin)->all());

        $this->assertContains('get_financial_status', $names);
    }

    public function test_null_permission_tool_visible_to_everyone(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $public = new class extends \App\Services\Agent\BaseTool {
            public function name(): string { return 'help_topic'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $input, \App\Services\Agent\AgentContext $context): array { return []; }
        };

        $registry = (new ToolRegistry())->register($public);
        $this->assertContains('help_topic', array_keys($registry->forUser($staff)->all()));
    }

    public function test_for_web_excludes_write_tools(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $registry = (new ToolRegistry())
            ->register($this->app->make(\App\Services\Agent\Tools\StartSaleTool::class))
            ->register(new GetTopSellersTool());

        $names = array_keys($registry->forWeb($admin)->all());

        $this->assertContains('get_top_sellers', $names);
        $this->assertNotContains('start_sale', $names);
    }

    public function test_developer_sees_all_tools_via_for_user(): void
    {
        $dev = User::factory()->create();
        $dev->assignRole('developer');

        $registry = (new ToolRegistry())
            ->register($this->app->make(GetFinancialStatusTool::class))
            ->register($this->app->make(\App\Services\Agent\Tools\StartSaleTool::class));

        $names = array_keys($registry->forUser($dev)->all());

        $this->assertContains('get_financial_status', $names);
        $this->assertContains('start_sale', $names);
    }

    public function test_new_business_tools_are_registered_in_container(): void
    {
        $registry = $this->app->make(\App\Services\Agent\ToolRegistry::class);
        $names = array_keys($registry->all());

        $this->assertContains('get_slow_sellers', $names);
        $this->assertContains('get_reorder_suggestions', $names);
    }

    public function test_developer_cannot_use_write_tools_via_for_web(): void
    {
        $dev = User::factory()->create();
        $dev->assignRole('developer');

        $registry = (new ToolRegistry())
            ->register($this->app->make(\App\Services\Agent\Tools\StartSaleTool::class))
            ->register(new GetTopSellersTool());

        $names = array_keys($registry->forWeb($dev)->all());

        $this->assertContains('get_top_sellers', $names);
        $this->assertNotContains('start_sale', $names);
    }
}
