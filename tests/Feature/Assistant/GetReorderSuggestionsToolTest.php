<?php

namespace Tests\Feature\Assistant;

use App\Models\Product;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\GetReorderSuggestionsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetReorderSuggestionsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_products_at_or_below_min_stock(): void
    {
        Product::factory()->create(['name' => 'Bajo', 'is_active' => true, 'quantity' => 2, 'min_stock' => 5]);
        Product::factory()->create(['name' => 'Suficiente', 'is_active' => true, 'quantity' => 50, 'min_stock' => 5]);

        $tool = new GetReorderSuggestionsTool();
        $result = $tool->execute([], new AgentContext(null, 'web:1', 'web'));

        $names = array_column($result['suggestions'], 'name');
        $this->assertContains('Bajo', $names);
        $this->assertNotContains('Suficiente', $names);
    }

    public function test_suggested_qty_covers_deficit(): void
    {
        Product::factory()->create(['name' => 'Bajo', 'is_active' => true, 'quantity' => 2, 'min_stock' => 5]);

        $tool = new GetReorderSuggestionsTool();
        $result = $tool->execute([], new AgentContext(null, 'web:1', 'web'));

        $this->assertSame(3, $result['suggestions'][0]['suggested_qty']);
    }

    public function test_requires_products_view_permission(): void
    {
        $this->assertSame('products.view', (new GetReorderSuggestionsTool())->requiredPermission());
    }
}
