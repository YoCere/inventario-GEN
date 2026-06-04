<?php

namespace App\Livewire\Accounting;

use App\Models\AccountingPeriod;
use App\Models\WorksheetAnnotation;
use App\Services\Accounting\WorksheetService;
use Livewire\Component;

class Worksheet extends Component
{
    public ?int $periodId = null;

    public function mount(): void
    {
        $this->periodId ??= AccountingPeriod::orderByDesc('start_date')->value('id');
    }

    public function saveNote(int $accountId, ?string $note, string $status): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        WorksheetAnnotation::updateOrCreate(
            ['accounting_period_id' => $this->periodId, 'chart_of_account_id' => $accountId],
            ['manual_note' => $note, 'action_status' => $status, 'user_id' => auth()->id()],
        );
        session()->flash('saved', 'Nota guardada.');
    }

    public function render()
    {
        $period = $this->periodId ? AccountingPeriod::find($this->periodId) : null;
        $data = null;
        if ($period) {
            $svc = app(WorksheetService::class);
            $svc->generate($period);
            $data = $svc->present($period);
        }

        return view('livewire.accounting.worksheet', [
            'period'  => $period,
            'data'    => $data,
            'periods' => AccountingPeriod::orderByDesc('start_date')->get(),
        ]);
    }
}
