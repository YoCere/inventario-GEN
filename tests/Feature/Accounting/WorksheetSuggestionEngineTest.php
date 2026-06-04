<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\WorksheetSuggestionEngine;
use Tests\TestCase;

class WorksheetSuggestionEngineTest extends TestCase
{
    private function engine(): WorksheetSuggestionEngine
    {
        return new WorksheetSuggestionEngine();
    }

    private function ctx(): array
    {
        return ['liquidity_target' => 1500000, 'high_expense_pct' => 40, 'variation_pct' => 50];
    }

    public function test_excess_liquidity_rule(): void
    {
        $row = ['account_type' => 'asset', 'is_liquidity' => true, 'saldo' => 2000000,
                'porcentaje_total' => null, 'variacion_pct' => null];
        $this->assertStringContainsString('Excedente de liquidez', $this->engine()->evaluate($row, $this->ctx()));
    }

    public function test_low_liquidity_rule(): void
    {
        $row = ['account_type' => 'asset', 'is_liquidity' => true, 'saldo' => 500000,
                'porcentaje_total' => null, 'variacion_pct' => null];
        $this->assertStringContainsString('Liquidez por debajo', $this->engine()->evaluate($row, $this->ctx()));
    }

    public function test_high_expense_rule(): void
    {
        $row = ['account_type' => 'expense', 'is_liquidity' => false, 'saldo' => 150000,
                'porcentaje_total' => 50.0, 'variacion_pct' => null];
        $this->assertStringContainsString('% del total', $this->engine()->evaluate($row, $this->ctx()));
    }

    public function test_sharp_variation_rule(): void
    {
        $row = ['account_type' => 'income', 'is_liquidity' => false, 'saldo' => 300000,
                'porcentaje_total' => null, 'variacion_pct' => 120.0];
        $this->assertStringContainsString('Variación brusca', $this->engine()->evaluate($row, $this->ctx()));
    }

    public function test_liability_rule(): void
    {
        $row = ['account_type' => 'liability', 'is_liquidity' => false, 'saldo' => 80000,
                'porcentaje_total' => null, 'variacion_pct' => null];
        $this->assertStringContainsString('Pasivo pendiente', $this->engine()->evaluate($row, $this->ctx()));
    }

    public function test_no_rule_returns_empty(): void
    {
        $row = ['account_type' => 'equity', 'is_liquidity' => false, 'saldo' => 2000000,
                'porcentaje_total' => null, 'variacion_pct' => null];
        $this->assertSame('', $this->engine()->evaluate($row, $this->ctx()));
    }
}
