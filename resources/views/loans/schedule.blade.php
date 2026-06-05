<x-app-layout title="Cronograma de Préstamo">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Cronograma de Préstamo</h2>
            <a href="{{ route('finance.loans.index') }}">
                <x-secondary-button type="button">
                    <x-heroicon-o-arrow-left class="w-4 h-4 mr-2" />
                    Volver a préstamos
                </x-secondary-button>
            </a>
        </div>
    </x-slot>
    <div class="py-4">
        @if(session('saved'))<div class="mb-2 text-green-700">{{ session('saved') }}</div>@endif
        @php($loanModel = \App\Models\Loan::find((int) $loan))
        @if($loanModel && $loanModel->status->value === 'active')
            <div class="mb-3">
                <button type="button"
                    x-data
                    x-on:click="if (confirm('¿Pagar el saldo y cancelar el préstamo?')) $dispatch('payoff-loan')"
                    class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-md text-sm">
                    Pagar saldo (cancelar préstamo)
                </button>
            </div>
        @endif
        <livewire:loans.loan-schedule-table :loan="(int) $loan" />
    </div>
</x-app-layout>
