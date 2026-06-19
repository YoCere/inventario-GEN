<?php

namespace App\Services\Agent\Tools;

use App\Models\Location;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class ListLocationsTool extends BaseTool
{
    public function name(): string
    {
        return 'list_locations';
    }

    public function description(): string
    {
        return 'Lista almacenes y ubicaciones activas del sistema con ids para referenciar en otras operaciones.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'products.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        $locations = Location::with('warehouse')
            ->where('is_active', true)
            ->orderBy('warehouse_id')
            ->orderBy('name')
            ->get();

        return [
            'locations' => $locations->map(fn ($l) => [
                'id' => $l->id,
                'warehouse' => $l->warehouse?->name,
                'name' => $l->name,
                'type' => $l->type,
                'is_default' => $l->is_default,
            ])->toArray(),
        ];
    }
}
