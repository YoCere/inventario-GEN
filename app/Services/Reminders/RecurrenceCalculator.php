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
     * @param  array<string,mixed>|null  $rule  weekly: ['days'=>[int...]] (null = día actual); monthly: ['day'=>int]; custom: ['interval_days'=>int]
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
            default => null, // tipo de recurrencia desconocido; se trata como no-recurrente
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
        // Unreachable for valid ISO weekdays (1–7): a 7-day window contains every weekday.
        // Reaching here means $rule['days'] held an invalid value.
        throw new \LogicException('nextWeekly: no match in 7-day window; invalid weekday in rule.');
    }

    private function nextMonthly(Carbon $local, ?array $rule): Carbon
    {
        $day = (int) ($rule['day'] ?? $local->day);
        $candidate = $local->copy()->addMonthNoOverflow();
        $candidate->day(min($day, $candidate->daysInMonth));
        return $candidate;
    }
}
