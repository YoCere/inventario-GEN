<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FinancialStatementService;

class FinancialStatementController extends Controller
{
    public function index(Request $request, FinancialStatementService $service)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $withTaxes = $request->boolean('with_taxes');

        $statements = $service->build($from, $to, $withTaxes);

        return view('finance-statements.index', compact('statements', 'from', 'to', 'withTaxes'));
    }
}
