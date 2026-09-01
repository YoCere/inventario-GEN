<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeStatementViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_statement_shows_accountant_structure(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($admin)
            ->get(route('finance.statements.index', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
                'with_taxes' => 1,
            ]));

        $response->assertOk();
        $response->assertSee('Utilidad Bruta');
        $response->assertSee('IUE');
        $response->assertSee('Utilidad de la Gestión');
    }
}
