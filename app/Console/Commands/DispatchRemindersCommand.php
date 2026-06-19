<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramMessage;
use App\Models\Reminder;
use App\Models\TelegramUser;
use App\Services\Reminders\RecurrenceCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch';
    protected $description = 'Envía los recordatorios vencidos y re-agenda los recurrentes';

    public function handle(RecurrenceCalculator $calc): int
    {
        Reminder::query()
            ->whereIn('status', ['pending', 'snoozed'])
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->chunkById(100, function ($reminders) use ($calc) {
                foreach ($reminders as $reminder) {
                    $this->dispatchOne($reminder, $calc);
                }
            });

        return self::SUCCESS;
    }

    private function dispatchOne(Reminder $reminder, RecurrenceCalculator $calc): void
    {
        try {
            $chatId = $reminder->chat_id
                ?: TelegramUser::where('user_id', $reminder->user_id)->value('chat_id');

            if (! $chatId) {
                Log::warning('Reminder sin chat_id resoluble', ['reminder_id' => $reminder->id]);
                return;
            }

            // Entrega at-least-once: despachamos el job ANTES de persistir el nuevo estado.
            // Si el proceso muere aquí, el recordatorio sigue 'pending' y se reenvía en el
            // próximo tick (preferimos repetir un aviso a perderlo).
            SendTelegramMessage::dispatch((string) $chatId, $this->buildMessage($reminder));

            $next = $calc->next($reminder->remind_at, $reminder->recurrence, $reminder->recurrence_rule, $reminder->timezone);

            $reminder->forceFill([
                'last_sent_at' => now(),
                'sent_count' => $reminder->sent_count + 1,
            ]);

            if ($next) {
                $reminder->remind_at = $next;
                $reminder->status = 'pending';
            } else {
                $reminder->status = 'sent';
            }

            $reminder->save();
        } catch (\Throwable $e) {
            Log::error('reminders:dispatch falló para un recordatorio', [
                'reminder_id' => $reminder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage(Reminder $reminder): string
    {
        $title = htmlspecialchars($reminder->title, ENT_QUOTES, 'UTF-8');
        $msg = "⏰ <b>Recordatorio</b>\n\n{$title}";
        if ($reminder->body) {
            $body = htmlspecialchars($reminder->body, ENT_QUOTES, 'UTF-8');
            $msg .= "\n\n{$body}";
        }
        return $msg;
    }
}
