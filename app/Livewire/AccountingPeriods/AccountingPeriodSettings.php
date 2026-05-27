<?php

namespace App\Livewire\AccountingPeriods;

use App\Models\AccountingPeriod;
use App\Models\Setting;
use Carbon\Carbon;
use Livewire\Component;

class AccountingPeriodSettings extends Component
{
    public string $autoCreate    = '1';
    public string $periodType    = 'monthly';

    // Estado del periodo activo (para el panel de estado)
    public ?string $activePeriodName     = null;
    public ?string $activePeriodEnd      = null;
    public ?int    $activePeriodDaysLeft = null;
    public string  $activePeriodStatus   = 'none'; // ok | warning | critical | none

    public function mount(): void
    {
        $this->autoCreate = Setting::get('auto_create_next_period', '1');
        $this->periodType = Setting::get('default_accounting_period_type', 'monthly');
        $this->loadPeriodStatus();
    }

    public function updatedAutoCreate(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        Setting::set('auto_create_next_period', $this->autoCreate);
        $this->dispatch('toast', message: 'Configuración guardada.', type: 'success');
    }

    public function updatedPeriodType(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        Setting::set('default_accounting_period_type', $this->periodType);
        $this->dispatch('toast', message: 'Configuración guardada.', type: 'success');
    }

    protected function loadPeriodStatus(): void
    {
        $today = now()->toDateString();

        $active = AccountingPeriod::query()
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('start_date')
            ->first();

        if ($active) {
            $daysLeft = (int) now()->diffInDays($active->end_date, false);
            $this->activePeriodName     = $active->name;
            $this->activePeriodEnd      = $active->end_date->format('d/m/Y');
            $this->activePeriodDaysLeft = $daysLeft;
            $this->activePeriodStatus   = $daysLeft <= 0 ? 'warning' : ($daysLeft <= 7 ? 'warning' : 'ok');
            return;
        }

        // ¿Hay periodo vencido sin cerrar?
        $expired = AccountingPeriod::query()
            ->where('status', 'open')
            ->whereDate('end_date', '<', $today)
            ->orderByDesc('end_date')
            ->first();

        if ($expired) {
            $this->activePeriodName   = $expired->name;
            $this->activePeriodEnd    = $expired->end_date->format('d/m/Y');
            $this->activePeriodStatus = 'critical';
            return;
        }

        $this->activePeriodStatus = 'none';
    }

    public function render()
    {
        return view('livewire.accounting-periods.accounting-period-settings');
    }
}
