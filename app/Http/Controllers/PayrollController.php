<?php

namespace App\Http\Controllers;

use App\Models\PayrollSheet;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use App\Services\Accounting\PayrollAccountingService;

class PayrollController extends Controller
{
    public function index()
    {
        $sheets = PayrollSheet::query()
            ->withCount('items')
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate(15);

        return view('finance-payroll.index', compact('sheets'));
    }

    public function create(PayrollService $payrollService)
    {
        $rates = $payrollService->getRates();

        return view('finance-payroll.create', compact('rates'));
    }

    public function store(Request $request, PayrollService $payrollService)
    {
        $validated = $request->validate([
            'period_month' => ['required', 'date'],
            'payment_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_name' => ['required', 'string', 'max:255'],
            'items.*.position' => ['nullable', 'string', 'max:255'],
            'items.*.area' => ['required', 'in:mod,moi,sales,admin'],
            'items.*.antiquity_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.worked_days' => ['required', 'integer', 'min:0', 'max:31'],
            'items.*.base_salary' => ['required', 'integer', 'min:0'],
            'items.*.hours_extra' => ['nullable', 'integer', 'min:0'],
            'items.*.other_discounts' => ['nullable', 'integer', 'min:0'],
            'items.*.apply_border_bonus' => ['nullable', 'in:0,1'],
        ]);

        $validated['items'] = collect($validated['items'])
            ->map(function (array $item) {
                $item['apply_border_bonus'] = ($item['apply_border_bonus'] ?? '0') === '1';
                return $item;
            })
            ->values()
            ->all();

        $sheet = $payrollService->createSheet($validated, (int) auth()->id());

        return redirect()
            ->route('finance.payroll.show', $sheet)
            ->with('success', 'Planilla creada correctamente.');
    }

    public function show(PayrollSheet $sheet)
    {
        $sheet->load(['items', 'creator', 'postedBy', 'journalEntry']);

        return view('finance-payroll.show', compact('sheet'));
    }

    public function post(PayrollSheet $sheet, PayrollAccountingService $payrollAccountingService)
    {
        $payrollAccountingService->postSheet($sheet, (int) auth()->id());

        return redirect()
            ->route('finance.payroll.show', $sheet)
            ->with('success', 'Planilla contabilizada en libro diario.');
    }

    public function print(PayrollSheet $sheet)
    {
        $sheet->load(['items', 'creator', 'postedBy', 'journalEntry']);

        return view('finance-payroll.print', compact('sheet'));
    }
}

