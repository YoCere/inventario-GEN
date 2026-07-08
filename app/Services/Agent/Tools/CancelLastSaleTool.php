<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\QuickSaleService;

class CancelLastSaleTool extends BaseTool
{
    public function __construct(private QuickSaleService $quickSale) {}

    public function webExposed(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'cancel_last_sale';
    }

    public function description(): string
    {
        return 'Anula (deshace) la última venta del usuario. Úsalo cuando pida "anula/deshaz la última venta" o "me equivoqué en la venta".';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        try {
            $sale = $this->quickSale->voidLast($context->user);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'ok'      => true,
            'sale_id' => $sale->id,
            'invoice' => $sale->invoice_number,
            'message' => "Venta {$sale->invoice_number} anulada y stock restaurado.",
        ];
    }
}
