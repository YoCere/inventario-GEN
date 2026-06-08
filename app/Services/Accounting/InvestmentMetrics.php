<?php

namespace App\Services\Accounting;

class InvestmentMetrics
{
    /**
     * TIR (Newton-Raphson). Tasa por periodo (fracción) o null.
     * @param array<int, float|int> $cashFlows
     */
    public function irr(array $cashFlows): ?float
    {
        if (count($cashFlows) < 2) {
            return null;
        }
        $hasPositive = false; $hasNegative = false;
        foreach ($cashFlows as $flow) {
            if ($flow > 0) { $hasPositive = true; }
            if ($flow < 0) { $hasNegative = true; }
        }
        if (! $hasPositive || ! $hasNegative) {
            return null;
        }
        $rate = 0.1;
        for ($i = 0; $i < 100; $i++) {
            $npv = 0.0; $derivative = 0.0;
            foreach ($cashFlows as $t => $flow) {
                $base = (1 + $rate) ** $t;
                $npv += $flow / $base;
                if ($t > 0) {
                    $derivative -= ($t * $flow) / ((1 + $rate) ** ($t + 1));
                }
            }
            if (abs($derivative) < 1e-10) { return null; }
            $nextRate = $rate - ($npv / $derivative);
            if ($nextRate <= -0.9999) { return null; }
            if (abs($nextRate - $rate) < 1e-7) { return $nextRate; }
            $rate = $nextRate;
        }
        return null;
    }

    /**
     * VAN.
     * @param array<int, float|int> $cashFlows
     */
    public function npv(array $cashFlows, float $ratePerPeriod): float
    {
        $npv = 0.0;
        foreach ($cashFlows as $t => $flow) {
            $npv += $flow / ((1 + $ratePerPeriod) ** $t);
        }
        return $npv;
    }

    /**
     * Payback (periodos con fracción).
     * @param array<int, float|int> $flows
     */
    public function payback(int $investmentBase, array $flows): ?float
    {
        if ($investmentBase <= 0) {
            return null;
        }
        $remaining = $investmentBase;
        foreach (array_values($flows) as $index => $flow) {
            if ($flow <= 0) { continue; }
            if ($flow >= $remaining) {
                return round($index + ($remaining / $flow), 2);
            }
            $remaining -= $flow;
        }
        return null;
    }
}
