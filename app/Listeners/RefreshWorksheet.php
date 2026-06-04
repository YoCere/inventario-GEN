<?php

namespace App\Listeners;

use App\Events\JournalEntryPosted;
use App\Models\AccountingPeriod;
use App\Services\Accounting\WorksheetService;

/**
 * Regenera worksheet_rows del periodo afectado tras cada asiento posteado,
 * para mantener caliente la Hoja Teórica. Registrado por auto-discovery de
 * Laravel 11 (NO agregar Event::listen manual: duplicaría la ejecución).
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
        $period = AccountingPeriod::find($event->entry->accounting_period_id);
        if ($period) {
            $this->worksheet->generate($period);
        }
    }
}
