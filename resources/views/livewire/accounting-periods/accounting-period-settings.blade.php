<div class="rounded-lg border border-border bg-card shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-4">

        {{-- Estado del periodo activo --}}
        <div class="flex items-start gap-3 min-w-0">
            @if($activePeriodStatus === 'ok')
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-emerald-600 dark:text-emerald-400">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-foreground">{{ $activePeriodName }}</p>
                    <p class="text-xs text-muted-foreground">
                        Vence el {{ $activePeriodEnd }}
                        @if($activePeriodDaysLeft !== null)
                            · {{ $activePeriodDaysLeft }} día(s) restantes
                        @endif
                    </p>
                </div>

            @elseif($activePeriodStatus === 'warning')
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-amber-600 dark:text-amber-400">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-amber-700 dark:text-amber-400">{{ $activePeriodName }}</p>
                    <p class="text-xs text-amber-600 dark:text-amber-500">
                        Vence el {{ $activePeriodEnd }}
                        @if($activePeriodDaysLeft !== null && $activePeriodDaysLeft >= 0)
                            · {{ $activePeriodDaysLeft === 0 ? 'hoy' : $activePeriodDaysLeft . ' día(s)' }}
                        @elseif($activePeriodDaysLeft !== null && $activePeriodDaysLeft < 0)
                            · vence hoy
                        @endif
                        — prepare el siguiente
                    </p>
                </div>

            @elseif($activePeriodStatus === 'critical')
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/40">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-red-600 dark:text-red-400">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-red-700 dark:text-red-400">Periodo vencido sin cerrar</p>
                    <p class="text-xs text-red-600 dark:text-red-500">
                        "{{ $activePeriodName }}" venció el {{ $activePeriodEnd }}.
                        @if($autoCreate === '1')
                            Se auto-cerrará y creará uno nuevo en la próxima venta.
                        @else
                            Se extenderá automáticamente en la próxima venta.
                        @endif
                    </p>
                </div>

            @else
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/40">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-red-600 dark:text-red-400">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-red-700 dark:text-red-400">Sin periodo contable activo</p>
                    <p class="text-xs text-red-600 dark:text-red-500">Cree un periodo ahora para poder registrar ventas y compras.</p>
                </div>
            @endif
        </div>

        {{-- Controles de configuración (solo admins) --}}
        @if(auth()->user()?->isAdmin())
        <div class="flex flex-wrap items-center gap-4 shrink-0">

            {{-- Tipo de periodo --}}
            <div class="flex items-center gap-2">
                <label for="ap-period-type" class="text-xs font-medium text-muted-foreground whitespace-nowrap">
                    Tipo por defecto
                </label>
                <select id="ap-period-type"
                        wire:model.live="periodType"
                        class="h-8 rounded-md border border-input bg-background px-2 py-1 text-xs shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                    <option value="monthly">Mensual</option>
                    <option value="quarterly">Trimestral</option>
                    <option value="biannual">Semestral</option>
                    <option value="annual">Anual</option>
                </select>
            </div>

            {{-- Divisor --}}
            <div class="hidden sm:block h-6 w-px bg-border"></div>

            {{-- Auto-crear siguiente --}}
            <div class="flex items-center gap-2">
                <label for="ap-auto-create" class="text-xs font-medium text-muted-foreground whitespace-nowrap">
                    Auto-crear siguiente
                </label>
                <button
                    type="button"
                    wire:click="$set('autoCreate', autoCreate === '1' ? '0' : '1')"
                    x-data="{ on: @entangle('autoCreate') }"
                    :aria-checked="on === '1'"
                    role="switch"
                    class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    :class="on === '1' ? 'bg-primary' : 'bg-input'"
                >
                    <span
                        class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-background shadow-lg ring-0 transition duration-200 ease-in-out"
                        :class="on === '1' ? 'translate-x-4' : 'translate-x-0'"
                    ></span>
                </button>
            </div>

        </div>
        @endif

    </div>

    {{-- Nota descriptiva --}}
    @if(auth()->user()?->isAdmin() && $autoCreate === '1')
    <div class="border-t border-border px-4 py-2">
        <p class="text-xs text-muted-foreground">
            <span class="font-medium">Auto-crear activo:</span>
            al cerrar un periodo se creará automáticamente el siguiente ({{ match($periodType) {
                'monthly'   => 'mensual',
                'quarterly' => 'trimestral',
                'biannual'  => 'semestral',
                'annual'    => 'anual',
                default     => $periodType
            } }}). Si un periodo vence sin ser cerrado, el sistema lo cerrará y creará el nuevo en la próxima venta.
        </p>
    </div>
    @elseif(auth()->user()?->isAdmin() && $autoCreate === '0')
    <div class="border-t border-border px-4 py-2">
        <p class="text-xs text-muted-foreground">
            <span class="font-medium text-amber-600">Auto-crear desactivado:</span>
            debe crear manualmente cada nuevo periodo. Si un periodo vence sin ser cerrado, se extenderá hasta la fecha de la siguiente operación.
        </p>
    </div>
    @endif
</div>
