<?php

namespace App\Services\Reminders;

use Carbon\Carbon;

/**
 * Servicio puro: calcula la próxima ocurrencia de un recordatorio recurrente.
 * Recibe y devuelve instantes en UTC; los cálculos de calendario se hacen en
 * la zona horaria del recordatorio para preservar la hora de pared (DST-safe).
 */
class RecurrenceCalculator
{
    /**
     * @param  Carbon  $current  Instante que acaba de dispararse (UTC).
     * @param  string  $recurrence  none|daily|weekly|monthly|custom
     * @param  array<string,mixed>|null  $rule
     * @return Carbon|null  Próxima ocurrencia en UTC, o null si no recurre.
     */
    public function next(Carbon $current, string $recurrence, ?array $rule, string $tz): ?Carbon
    {
        if ($recurrence === 'none') {
            return null;
        }

        $local = $current->copy()->setTimezone($tz);

        $next = match ($recurrence) {
            'daily' => $local->copy()->addDay(),
            'weekly' => $this->nextWeekly($local, $rule),
            'monthly' => $this->nextMonthly($local, $rule),
            'custom' => $local->copy()->addDays(max(1, (int) ($rule['interval_days'] ?? 1))),
            default => null,
        };

        return $next?->setTimezone('UTC');
    }

    private function nextWeekly(Carbon $local, ?array $rule): Carbon
    {
        $days = $rule['days'] ?? [$local->isoWeekday()];
        for ($i = 1; $i <= 7; $i++) {
            $candidate = $local->copy()->addDays($i);
            if (in_array($candidate->isoWeekday(), $days, true)) {
                return $candidate;
            }
        }
        return $local->copy()->addWeek();
    }

    private function nextMonthly(Carbon $local, ?array $rule): Carbon
    {
        $day = (int) ($rule['day'] ?? $local->day);
        $candidate = $local->copy()->addMonthNoOverflow();
        $candidate->day(min($day, $candidate->daysInMonth));
        return $candidate;
    }
}
