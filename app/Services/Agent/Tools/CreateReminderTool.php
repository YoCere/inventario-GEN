<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use Carbon\Carbon;

class CreateReminderTool extends BaseTool
{
    public function name(): string
    {
        return 'create_reminder';
    }

    public function description(): string
    {
        return 'Crea un recordatorio para el usuario. La fecha debe estar en formato ISO 8601 (ej. 2026-06-20T15:00:00).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Título o descripción del recordatorio.',
                ],
                'remind_at' => [
                    'type' => 'string',
                    'description' => 'Fecha y hora del recordatorio en formato ISO 8601, ej. 2026-06-20T15:00:00.',
                ],
                'recurrence' => [
                    'type' => 'string',
                    'enum' => ['none', 'daily', 'weekly', 'monthly'],
                    'description' => 'Frecuencia de repetición. Por defecto "none" (una sola vez).',
                ],
            ],
            'required' => ['title', 'remind_at'],
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function confirmationSummary(array $input): string
    {
        try {
            $when = Carbon::parse($input['remind_at'])->format('d/m/Y H:i');
        } catch (\Throwable) {
            $when = $input['remind_at'] ?? '(fecha inválida)';
        }

        $title = $input['title'] ?? '';
        $recurrence = $input['recurrence'] ?? 'none';

        $repeat = [
            'none'    => 'una sola vez',
            'daily'   => 'cada día',
            'weekly'  => 'cada semana',
            'monthly' => 'cada mes',
        ][$recurrence] ?? $recurrence;

        return "Crear recordatorio: \"{$title}\" el {$when} ({$repeat}).";
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'Usuario no autenticado.'];
        }

        try {
            $when = Carbon::parse($input['remind_at'], config('app.timezone'));
        } catch (\Throwable) {
            return ['error' => 'Fecha inválida. Usa formato ISO 8601, ej. 2026-06-20T15:00:00.'];
        }

        if ($when->isPast()) {
            return ['error' => 'La fecha del recordatorio ya ha pasado. Indica una fecha futura.'];
        }

        $recurrence = $input['recurrence'] ?? 'none';
        $allowed = ['none', 'daily', 'weekly', 'monthly'];
        if (! in_array($recurrence, $allowed, true)) {
            return ['error' => "Recurrencia no válida: '{$recurrence}'. Usa none, daily, weekly o monthly."];
        }

        $rule = match ($recurrence) {
            'weekly'  => ['days' => [$when->isoWeekday()]],
            'monthly' => ['day'  => $when->day],
            default   => null,
        };

        $reminder = Reminder::create([
            'user_id'          => $context->user->id,
            'chat_id'          => $context->chatId,
            'title'            => $input['title'],
            'remind_at'        => $when->setTimezone('UTC'),
            'timezone'         => config('app.timezone'),
            'recurrence'       => $recurrence,
            'recurrence_rule'  => $rule,
            'status'           => 'pending',
            'created_via'      => 'nl',
        ]);

        return [
            'id'         => $reminder->id,
            'title'      => $reminder->title,
            'remind_at'  => $reminder->remind_at->toIso8601String(),
            'recurrence' => $reminder->recurrence,
            'status'     => $reminder->status,
        ];
    }
}
