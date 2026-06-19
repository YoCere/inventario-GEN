<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\BaseTool;
use App\Services\Agent\AgentContext;
use PHPUnit\Framework\TestCase;

class ToolPermissionTest extends TestCase
{
    public function test_base_tool_defaults_to_no_permission(): void
    {
        $tool = new class extends BaseTool {
            public function name(): string { return 'dummy'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $input, AgentContext $context): array { return []; }
        };

        $this->assertNull($tool->requiredPermission());
    }

    public function test_base_tool_is_web_exposed_by_default(): void
    {
        $tool = new class extends \App\Services\Agent\BaseTool {
            public function name(): string { return 'd'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $input, \App\Services\Agent\AgentContext $context): array { return []; }
        };
        $this->assertTrue($tool->webExposed());
    }

    public function test_finance_tools_require_finance_view(): void
    {
        $tool = new \App\Services\Agent\Tools\GetFinancialStatusTool(
            $this->createMock(\App\Services\Accounting\FinancialReadModel::class)
        );
        $this->assertSame('finance.view', $tool->requiredPermission());
    }

    public function test_sales_tools_require_sales_view(): void
    {
        $tool = new \App\Services\Agent\Tools\GetTopSellersTool();
        $this->assertSame('sales.view', $tool->requiredPermission());
    }

    public function test_product_tools_require_products_view(): void
    {
        $tool = new \App\Services\Agent\Tools\GetLowStockTool();
        $this->assertSame('products.view', $tool->requiredPermission());
    }
}
