<?php

namespace App\Services;

use App\Enums\PayrollSheetStatus;
use App\Models\Setting;
use App\Models\PayrollSheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollService
{
    /**
     * @param array{
     *   period_month:string,
     *   payment_date:string,
     *   description?:string|null,
     *   items:array<int, array{
     *      employee_name:string,
     *      position?:string|null,
     *      area:string,
     *      antiquity_rate:numeric-string|float|int,
     *      worked_days:int|string,
     *      base_salary:int|string,
     *      hours_extra?:int|string|null,
     *      other_discounts?:int|string|null,
     *      apply_border_bonus?:bool|int|string
     *   }>
     * } $payload
     */
    public function createSheet(array $payload, int $userId): PayrollSheet
    {
        if (empty($payload['items'])) {
            throw new InvalidArgumentException('La planilla debe tener al menos un trabajador.');
        }

        return DB::transaction(function () use ($payload, $userId) {
            $periodMonth = Carbon::parse($payload['period_month'])->startOfMonth()->toDateString();
            $sheet = PayrollSheet::create([
                'sheet_number' => $this->generateSheetNumber($periodMonth),
                'period_month' => $periodMonth,
                'payment_date' => Carbon::parse($payload['payment_date'])->toDateString(),
                'description' => $payload['description'] ?? null,
                'status' => PayrollSheetStatus::DRAFT,
                'created_by' => $userId,
            ]);

            $totals = [
                'total_earned' => 0,
                'total_deductions' => 0,
                'net_payable' => 0,
                'total_employer_contributions' => 0,
                'total_employer_cost' => 0,
            ];

            foreach (array_values($payload['items']) as $index => $row) {
                $calculated = $this->calculateItem($row);

                $sheet->items()->create(array_merge($calculated, [
                    'line_number' => $index + 1,
                    'employee_name' => trim((string) $row['employee_name']),
                    'position' => $row['position'] ?? null,
                    'area' => $row['area'],
                    'antiquity_rate' => (float) $row['antiquity_rate'],
                    'worked_days' => max(0, min(31, (int) $row['worked_days'])),
                    'base_salary' => (int) $row['base_salary'],
                    'hours_extra' => (int) ($row['hours_extra'] ?? 0),
                    'other_discounts' => (int) ($row['other_discounts'] ?? 0),
                    'apply_border_bonus' => (bool) ($row['apply_border_bonus'] ?? false),
                ]));

                $totals['total_earned'] += $calculated['total_earned'];
                $totals['total_deductions'] += $calculated['total_deductions'];
                $totals['net_payable'] += $calculated['net_payable'];
                $totals['total_employer_contributions'] += (
                    $calculated['employer_contribution']
                    + $calculated['aguinaldo_provision']
                    + $calculated['indemnization_provision']
                );
                $totals['total_employer_cost'] += $calculated['total_employer_cost'];
            }

            $sheet->update($totals);

            return $sheet->fresh('items');
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    public function calculateItem(array $row): array
    {
        $config = $this->getRates();

        $workedDays = max(0, min(31, (int) ($row['worked_days'] ?? 30)));
        $baseSalary = max(0, (int) ($row['base_salary'] ?? 0));
        $hoursExtra = max(0, (int) ($row['hours_extra'] ?? 0));
        $otherDiscounts = max(0, (int) ($row['other_discounts'] ?? 0));
        $antiquityRate = max(0.0, (float) ($row['antiquity_rate'] ?? 0));
        $applyBorderBonus = (bool) ($row['apply_border_bonus'] ?? false);

        $earnedBase = (int) round(($baseSalary / 30) * $workedDays);
        $antiquityBonus = (int) round($config['antiquity_base_amount'] * $antiquityRate);
        $borderBonus = $applyBorderBonus
            ? (int) round($earnedBase * ($config['border_bonus_rate'] / 100))
            : 0;

        $totalEarned = $earnedBase + $antiquityBonus + $borderBonus + $hoursExtra;

        $laborContribution = (int) round($totalEarned * ($config['labor_contribution_rate'] / 100));

        $rcBase = max($totalEarned - $laborContribution, 0);
        $rcIvaRaw = (($rcBase - $config['rc_iva_minimum']) * ($config['rc_iva_rate'] / 100))
            - ($config['rc_iva_compensable'] * ($config['rc_iva_rate'] / 100));
        $rcIva = max((int) round($rcIvaRaw), 0);

        $solidarity1 = max(
            (int) round((max($totalEarned - $config['solidarity_1_threshold'], 0)) * ($config['solidarity_1_rate'] / 100)),
            0
        );
        $solidarity2 = max(
            (int) round((max($totalEarned - $config['solidarity_2_threshold'], 0)) * ($config['solidarity_2_rate'] / 100)),
            0
        );

        $totalDeductions = $laborContribution + $rcIva + $solidarity1 + $solidarity2 + $otherDiscounts;
        $netPayable = max($totalEarned - $totalDeductions, 0);

        $employerContribution = (int) round($totalEarned * ($config['employer_contribution_rate'] / 100));
        $aguinaldoProvision = (int) round($totalEarned * ($config['aguinaldo_provision_rate'] / 100));
        $indemnizationProvision = (int) round($totalEarned * ($config['indemnization_provision_rate'] / 100));

        return [
            'earned_base' => $earnedBase,
            'antiquity_bonus' => $antiquityBonus,
            'border_bonus' => $borderBonus,
            'total_earned' => $totalEarned,
            'labor_contribution' => $laborContribution,
            'rc_iva' => $rcIva,
            'solidarity_1' => $solidarity1,
            'solidarity_2' => $solidarity2,
            'total_deductions' => $totalDeductions,
            'net_payable' => $netPayable,
            'employer_contribution' => $employerContribution,
            'aguinaldo_provision' => $aguinaldoProvision,
            'indemnization_provision' => $indemnizationProvision,
            'total_employer_cost' => $totalEarned + $employerContribution + $aguinaldoProvision + $indemnizationProvision,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getRates(): array
    {
        return [
            'antiquity_base_amount' => (float) Setting::get('payroll_antiquity_base_amount', '7500'),
            'border_bonus_rate' => (float) Setting::get('payroll_border_bonus_rate', '20'),
            'labor_contribution_rate' => (float) Setting::get('payroll_labor_contribution_rate', '12.71'),
            'rc_iva_rate' => (float) Setting::get('payroll_rc_iva_rate', '13'),
            'rc_iva_minimum' => (float) Setting::get('payroll_rc_iva_minimum', '5000'),
            'rc_iva_compensable' => (float) Setting::get('payroll_rc_iva_compensable', '5000'),
            'solidarity_1_rate' => (float) Setting::get('payroll_solidarity_1_rate', '1'),
            'solidarity_1_threshold' => (float) Setting::get('payroll_solidarity_1_threshold', '13000'),
            'solidarity_2_rate' => (float) Setting::get('payroll_solidarity_2_rate', '5'),
            'solidarity_2_threshold' => (float) Setting::get('payroll_solidarity_2_threshold', '25000'),
            'employer_contribution_rate' => (float) Setting::get('payroll_employer_contribution_rate', '16.71'),
            'aguinaldo_provision_rate' => (float) Setting::get('payroll_aguinaldo_provision_rate', '8.33'),
            'indemnization_provision_rate' => (float) Setting::get('payroll_indemnization_provision_rate', '8.33'),
        ];
    }

    protected function generateSheetNumber(string $periodMonth): string
    {
        $prefix = 'NOM.' . Carbon::parse($periodMonth)->format('ym') . '.';
        $latest = PayrollSheet::query()
            ->where('sheet_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return $prefix . '0001';
        }

        $last = (int) substr($latest->sheet_number, -4);

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
