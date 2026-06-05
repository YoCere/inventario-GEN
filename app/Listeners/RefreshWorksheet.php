<?php

namespace App\Listeners;

use App\Events\JournalEntryPosted;
use App\Models\AccountingPeriod;
use App\Services\Accounting\WorksheetService;

/**
 * Refresca worksheet_rows del periodo afectado tras cada asiento posteado,
 * SOLO si la hoja ya existe para ese periodo (fue visualizada al menos una vez).
 * Esto evita generar filas para periodos nunca abiertos; la UI genera on-demand
 * al primer acceso. Registrado por auto-discovery de Laravel 11 (NO agregar
 * Event::listen manual: duplicaría la ejecución).
 * El orden frente a UpdateLedgerSnapshot no está garantizado; si corre antes,
 * worksheet_rows puede quedar rezagado un asiento, pero se corrige en el
 * siguiente posteo o al visualizar la hoja (que regenera on-demand).
 */
class RefreshWorksheet
{
    public function __construct(private WorksheetService $worksheet)
    {
    }

    public function handle(JournalEntryPosted $event): void
    {
        $periodId = $event->entry->accounting_period_id;
        $exists = \App\Models\WorksheetRow::where('accounting_period_id', $periodId)->exists();
        if (!$exists) {
            return; // se generará on-demand al abrir la Hoja Teórica
        }
        $period = AccountingPeriod::find($periodId);
        if ($period) {
            $this->worksheet->generate($period);
        }
    }
}
