<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollSheetItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_sheet_id',
        'line_number',
        'employee_name',
        'position',
        'area',
        'antiquity_rate',
        'worked_days',
        'base_salary',
        'hours_extra',
        'other_discounts',
        'apply_border_bonus',
        'earned_base',
        'antiquity_bonus',
        'border_bonus',
        'total_earned',
        'labor_contribution',
        'rc_iva',
        'solidarity_1',
        'solidarity_2',
        'total_deductions',
        'net_payable',
        'employer_contribution',
        'aguinaldo_provision',
        'indemnization_provision',
        'total_employer_cost',
        'notes',
    ];

    protected $casts = [
        'payroll_sheet_id' => 'integer',
        'line_number' => 'integer',
        'antiquity_rate' => 'decimal:4',
        'worked_days' => 'integer',
        'base_salary' => 'integer',
        'hours_extra' => 'integer',
        'other_discounts' => 'integer',
        'apply_border_bonus' => 'boolean',
        'earned_base' => 'integer',
        'antiquity_bonus' => 'integer',
        'border_bonus' => 'integer',
        'total_earned' => 'integer',
        'labor_contribution' => 'integer',
        'rc_iva' => 'integer',
        'solidarity_1' => 'integer',
        'solidarity_2' => 'integer',
        'total_deductions' => 'integer',
        'net_payable' => 'integer',
        'employer_contribution' => 'integer',
        'aguinaldo_provision' => 'integer',
        'indemnization_provision' => 'integer',
        'total_employer_cost' => 'integer',
    ];

    public function payrollSheet(): BelongsTo
    {
        return $this->belongsTo(PayrollSheet::class);
    }
}

