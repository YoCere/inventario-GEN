<?php

namespace App\Services\Agent\Tools;

use App\Services\Accounting\FinancialReadModel;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetBalanceSheetTool extends BaseTool
{
    public function __construct(private FinancialReadModel $readModel)
    {
    }

    public function name(): string
    {
        return 'get_balance_sheet';
    }

    public function description(): string
    {
        return 'Balance General (situación financiera) a una fecha: lista de activos, pasivos y patrimonio con montos. Fecha YYYY-MM-DD, default hoy.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'as_of' => ['type' => 'string', 'description' => 'Fecha de corte YYYY-MM-DD. Default: hoy.'],
            ],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (!$context->user || !$context->user->isAdmin()) {
            return ['error' => 'Solo el administrador puede consultar información financiera.'];
        }

        $date = (string) ($input['as_of'] ?? now()->toDateString());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['error' => "Formato de fecha inválido: usa YYYY-MM-DD."];
        }
        return $this->readModel->balanceSheet($date);
    }
}
