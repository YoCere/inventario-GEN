<?php

namespace App\Livewire\Accounting;

use App\Services\Accounting\GestionCierreService;
use Livewire\Component;

class GestionCierreWizard extends Component
{
    public int $year;

    public function mount(): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->year = (int) now()->year;
    }

    public function getPreviewProperty(): array
    {
        return app(GestionCierreService::class)->preview($this->year);
    }

    public function cerrar(GestionCierreService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        try {
            $entry = $service->close($this->year, (int) auth()->id());
            $msg = $entry ? 'Cierre de gestión registrado.' : 'No hay IUE ni reserva que provisionar para esta gestión.';
            session()->flash('ok', $msg);
            $this->dispatch('toast', message: $msg, type: 'success');
        } catch (\Throwable $e) {
            $this->addError('year', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.accounting.gestion-cierre-wizard');
    }
}
