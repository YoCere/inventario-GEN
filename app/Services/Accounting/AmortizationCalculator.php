<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

class AmortizationCalculator
{
    /**
     * Cronograma de amortización francés (cuota constante). Montos en centavos.
     *
     * @return array<int, array{number:int, due_date:string, payment_amount:int,
     *   interest_amount:int, principal_amount:int, balance_after:int}>
     */
    public function schedule(int $principal, float $annualRatePct, int $termMonths, string $startDate, int $paymentDay): array
    {
        $n = max(1, $termMonths);
        $i = $annualRatePct / 100 / 12;

        if ($i <= 0.0) {
            $payment = intdiv($principal, $n);
        } else {
            $factor = 1 - (1 + $i) ** (-$n);
            $payment = (int) round($principal * $i / $factor);
        }

        $start = Carbon::parse($startDate);
        $balance = $principal;
        $rows = [];

        for ($k = 1; $k <= $n; $k++) {
            $interest = (int) round($balance * $i);
            $principalPart = $payment - $interest;

            if ($k === $n) {
                $principalPart = $balance;
                $payment = $principalPart + $interest;
            }

            $balance -= $principalPart;
            $due = $start->copy()->addMonthsNoOverflow($k)->day($paymentDay)->toDateString();

            $rows[] = [
                'number' => $k,
                'due_date' => $due,
                'payment_amount' => $payment,
                'interest_amount' => $interest,
                'principal_amount' => $principalPart,
                'balance_after' => max($balance, 0),
            ];
        }

        return $rows;
    }
}
