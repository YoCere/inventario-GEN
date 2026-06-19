<?php

namespace App\Services\Agent\Tools;

use App\Services\Accounting\FinancialReadModel;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetIncomeAndExpensesTool extends BaseTool
{
    public function __construct(private FinancialReadModel $readModel)
    {
    }

    public function name(): string
    {
        return 'get_income_and_expenses';
    }

    public function description(): string
    {
        return 'Estado de resultados de un rango: ingresos, costos, gastos y utilidad neta, más desglose de gastos por categoría. Fechas en formato YYYY-MM-DD.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD.'],
                'to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD.'],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'finance.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (!$context->user || !$context->user->isAdmin()) {
            return ['error' => 'Solo el administrador puede consultar información financiera.'];
        }

        foreach (['from', 'to'] as $k) {
            $v = (string) ($input[$k] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return ['error' => "Formato de fecha inválido en '{$k}': usa YYYY-MM-DD."];
            }
        }

        return [
            'resumen' => $this->readModel->incomeStatement($input['from'], $input['to']),
            'gastos_por_categoria' => $this->readModel->expensesByCategory($input['from'], $input['to']),
        ];
    }
}
