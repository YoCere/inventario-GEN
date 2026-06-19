<?php

namespace App\Services\Telegram;

use App\Models\Reminder;
use App\Models\TelegramConversation;
use App\Services\Messaging\TelegramService;
use Carbon\Carbon;

class ReminderHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected BotAuthHandler $auth,
    ) {}

    public function start(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'recordar:titulo',
            'data' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "⏰ <b>Nuevo recordatorio</b>\n\n¿Qué quieres que te recuerde?\n\n(Escribe /cancelar para salir)"
        );
    }

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        match ($conversation->step) {
            'recordar:titulo' => $this->askFecha($chatId, $conversation, $text),
            'recordar:fecha' => $this->askRecurrencia($chatId, $conversation, $text),
            'recordar:recurrencia' => $this->askConfirmar($chatId, $conversation, $text),
            'recordar:confirmar' => $this->finish($chatId, $conversation, $text),
            'recordatorios:gestionar' => $this->cancelByNumber($chatId, $conversation, $text),
            default => null,
        };
    }

    private function askFecha(string $chatId, TelegramConversation $conv, string $text): void
    {
        if ($text === '') {
            $this->telegram->sendMessage($chatId, "❌ Escribe un texto para el recordatorio.");
            return;
        }

        $conv->update([
            'step' => 'recordar:fecha',
            'data' => ['title' => $text],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📅 ¿Cuándo? Escribe fecha y hora.\n\nEjemplos:\n<code>20/06/2026 15:00</code>\n<code>20/06 15:00</code>"
        );
    }

    private function askRecurrencia(string $chatId, TelegramConversation $conv, string $text): void
    {
        $when = $this->parseFecha($text);
        if (! $when) {
            $this->telegram->sendMessage($chatId, "❌ Formato no válido. Usa <code>DD/MM/YYYY HH:MM</code> (ej. 20/06/2026 15:00).");
            return;
        }
        if ($when->isPast()) {
            $this->telegram->sendMessage($chatId, "❌ Esa fecha ya pasó. Escribe una fecha futura.");
            return;
        }

        $conv->update([
            'step' => 'recordar:recurrencia',
            'data' => array_merge($conv->data, ['remind_at' => $when->toIso8601String()]),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔁 ¿Se repite?\n\n1️⃣ No, una sola vez\n2️⃣ Cada día\n3️⃣ Cada semana (este día)\n4️⃣ Cada mes (este día)\n\nEscribe el número."
        );
    }

    private function askConfirmar(string $chatId, TelegramConversation $conv, string $text): void
    {
        $map = ['1' => 'none', '2' => 'daily', '3' => 'weekly', '4' => 'monthly'];
        $recurrence = $map[trim($text)] ?? null;
        if (! $recurrence) {
            $this->telegram->sendMessage($chatId, "❌ Escribe 1, 2, 3 o 4.");
            return;
        }

        $when = Carbon::parse($conv->data['remind_at']);
        $rule = match ($recurrence) {
            'weekly' => ['days' => [$when->isoWeekday()]],
            'monthly' => ['day' => $when->day],
            default => null,
        };

        $conv->update([
            'step' => 'recordar:confirmar',
            'data' => array_merge($conv->data, [
                'recurrence' => $recurrence,
                'recurrence_rule' => $rule,
            ]),
        ]);

        $repeat = [
            'none' => 'una sola vez',
            'daily' => 'cada día',
            'weekly' => 'cada semana',
            'monthly' => 'cada mes',
        ][$recurrence];

        $this->telegram->sendMessage(
            $chatId,
            "✅ Confirma:\n\n📝 <b>{$conv->data['title']}</b>\n📅 {$when->format('d/m/Y H:i')}\n🔁 {$repeat}\n\n1️⃣ Guardar\n2️⃣ Cancelar"
        );
    }

    private function finish(string $chatId, TelegramConversation $conv, string $text): void
    {
        if (trim($text) !== '1') {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ Recordatorio descartado.");
            return;
        }

        $user = $this->auth->getAuthenticatedUser($chatId);
        if (! $user) {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida. Inicia sesión de nuevo.");
            return;
        }

        Reminder::create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'title' => $conv->data['title'],
            'remind_at' => Carbon::parse($conv->data['remind_at'])->setTimezone('UTC'),
            'timezone' => config('app.timezone'),
            'recurrence' => $conv->data['recurrence'],
            'recurrence_rule' => $conv->data['recurrence_rule'],
            'status' => 'pending',
            'created_via' => 'command',
        ]);

        $conv->delete();
        $this->telegram->sendMessage($chatId, "✅ Recordatorio guardado. Te avisaré a tiempo.");
    }

    /** Lista los recordatorios pendientes del usuario y arma estado para cancelar por número. */
    public function listAndManage(string $chatId): void
    {
        $user = $this->auth->getAuthenticatedUser($chatId);
        if (! $user) {
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida.");
            return;
        }

        $reminders = Reminder::forUser($user->id)
            ->whereIn('status', ['pending', 'snoozed'])
            ->orderBy('remind_at')
            ->limit(20)
            ->get();

        if ($reminders->isEmpty()) {
            $this->telegram->sendMessage($chatId, "📭 No tienes recordatorios pendientes.");
            return;
        }

        $msg = "⏰ <b>Tus recordatorios</b>\n\n";
        $ids = [];
        foreach ($reminders as $idx => $r) {
            $n = $idx + 1;
            $ids[(string) $n] = $r->id;
            $title = htmlspecialchars($r->title, ENT_QUOTES, 'UTF-8');
            $msg .= "{$n}. <b>{$title}</b> — {$r->remind_at->format('d/m/Y H:i')}\n";
        }
        $msg .= "\n<i>Escribe el número para cancelar uno, o /cancelar para salir.</i>";

        $conv = TelegramConversation::getOrCreate($chatId);
        $conv->update([
            'step' => 'recordatorios:gestionar',
            'data' => ['ids' => $ids],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function cancelByNumber(string $chatId, TelegramConversation $conv, string $text): void
    {
        $ids = $conv->data['ids'] ?? [];
        $id = $ids[trim($text)] ?? null;
        if (! $id) {
            $this->telegram->sendMessage($chatId, "❌ Número no válido. Escribe uno de la lista o /cancelar.");
            return;
        }

        $user = $this->auth->getAuthenticatedUser($chatId);
        // Doble candado anti-IDOR: solo cancela si es del propio usuario.
        $reminder = Reminder::forUser($user->id)->find($id);
        if (! $reminder) {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ No encontrado.");
            return;
        }

        $reminder->update(['status' => 'cancelled']);
        $conv->delete();
        $this->telegram->sendMessage($chatId, "🗑️ Recordatorio cancelado: <b>" . htmlspecialchars($reminder->title, ENT_QUOTES, 'UTF-8') . "</b>");
    }

    /** Parsea "DD/MM/YYYY HH:MM" o "DD/MM HH:MM" en la tz de la app. */
    private function parseFecha(string $text): ?Carbon
    {
        $text = trim($text);
        foreach (['d/m/Y H:i', 'd/m H:i'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text, config('app.timezone'));
                if ($date !== false) {
                    return $date;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }
}
