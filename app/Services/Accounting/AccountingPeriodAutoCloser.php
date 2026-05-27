<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Maneja el cierre automático de periodos contables vencidos.
 *
 * Extraído de AccountingPeriod::resolveOpenForDate() para eliminar
 * side-effects dentro de operaciones de lectura del modelo.
 */
class AccountingPeriodAutoCloser
{
    public function handleExpired(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $autoCreate = Setting::get('auto_create_next_period', '1') === '1';

        if ($autoCreate) {
            return $this->closeAndCreateNext($expiredOpen, $date);
        }

        return $this->extend($expiredOpen, $date);
    }

    private function closeAndCreateNext(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $expiredOpen->update([
            'status'    => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $newPeriod = AccountingPeriod::autoCreateNext($expiredOpen);

        if ($newPeriod->end_date->lt($date)) {
            $newPeriod->update(['end_date' => $date]);
            $newPeriod->refresh();
        }

        Log::warning("Auto-cierre de '{$expiredOpen->name}' y auto-creación de '{$newPeriod->name}' para cubrir fecha {$date}.", [
            'closed_period_id' => $expiredOpen->id,
            'new_period_id'    => $newPeriod->id,
            'sale_date'        => $date,
        ]);

        return $newPeriod;
    }

    private function extend(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $originalEnd = $expiredOpen->end_date->toDateString();
        $expiredOpen->update(['end_date' => $date]);
        $expiredOpen->refresh();

        Log::warning("Periodo '{$expiredOpen->name}' extendido automáticamente hasta {$date} (auto-creación desactivada).", [
            'period_id'    => $expiredOpen->id,
            'original_end' => $originalEnd,
            'extended_to'  => $date,
        ]);

        return $expiredOpen;
    }
}
