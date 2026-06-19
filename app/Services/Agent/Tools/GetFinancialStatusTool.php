<?php

namespace App\Services\Agent\Tools;

use App\Services\Accounting\FinancialReadModel;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetFinancialStatusTool extends BaseTool
{
    public function __construct(private FinancialReadModel $readModel)
    {
    }

    public function name(): string
    {
        return 'get_financial_status';
    }

    public function description(): string
    {
        return 'Estado de la empresa a una fecha de corte: activos, pasivos, patrimonio y resultado acumulado. Útil para "¿cómo está la empresa al [fecha]?". Fecha YYYY-MM-DD, default hoy.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Fecha de corte YYYY-MM-DD. Default: hoy.'],
            ],
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

        $date = (string) ($input['date'] ?? now()->toDateString());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['error' => "Formato de fecha inválido: usa YYYY-MM-DD."];
        }
        return $this->readModel->statusAt($date);
    }
}
