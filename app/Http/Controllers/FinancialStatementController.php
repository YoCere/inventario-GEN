<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FinancialStatementService;
use App\Support\BusinessTime;

class FinancialStatementController extends Controller
{
    public function index(Request $request, FinancialStatementService $service)
    {
        // Rango por defecto en fechas locales del negocio: con now() (UTC) el estado
        // financiero de la noche se cortaba en el día siguiente.
        $from = $request->input('from', BusinessTime::now()->startOfMonth()->toDateString());
        $to = $request->input('to', BusinessTime::todayString());
        $withTaxes = $request->boolean('with_taxes');

        $statements = $service->build($from, $to, $withTaxes);

        return view('finance-statements.index', compact('statements', 'from', 'to', 'withTaxes'));
    }
}
