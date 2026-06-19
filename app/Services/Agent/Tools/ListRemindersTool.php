<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class ListRemindersTool extends BaseTool
{
    public function name(): string
    {
        return 'list_reminders';
    }

    public function description(): string
    {
        return 'Lista los recordatorios pendientes del usuario actual.';
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

        $reminders = Reminder::forUser($context->user->id)
            ->whereIn('status', ['pending', 'snoozed'])
            ->orderBy('remind_at')
            ->limit(20)
            ->get();

        return [
            'count' => $reminders->count(),
            'reminders' => $reminders->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'remind_at' => $r->remind_at->format('d/m/Y H:i'),
                'recurrence' => $r->recurrence,
            ])->toArray(),
        ];
    }
}
