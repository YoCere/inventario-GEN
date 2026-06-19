<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class CancelReminderTool extends BaseTool
{
    public function name(): string
    {
        return 'cancel_reminder';
    }

    public function description(): string
    {
        return 'Cancela un recordatorio del usuario por su id (obtenido de list_reminders).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID del recordatorio a cancelar'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        // Scope anti-IDOR: solo cancela si pertenece al usuario.
        $reminder = Reminder::forUser($context->user->id)->find($input['id']);
        if (! $reminder) {
            return ['error' => 'Recordatorio no encontrado.'];
        }

        $reminder->update(['status' => 'cancelled']);

        return ['ok' => true, 'message' => "Cancelado: {$reminder->title}"];
    }
}
