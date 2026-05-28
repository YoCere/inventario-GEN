<div class="space-y-6">

    {{-- ── Header ── --}}
    <div class="flex flex-col gap-1">
        <h2 class="text-xl font-semibold text-foreground">Gestión de Backups</h2>
        <p class="text-sm text-muted-foreground">Copia de seguridad de base de datos y archivos</p>
    </div>

    {{-- ── Action buttons ── --}}
    <div class="rounded-lg border border-border bg-card p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">

            <x-primary-button
                wire:click="runBackup"
                wire:loading.attr="disabled"
                wire:target="runBackup"
                class="inline-flex items-center gap-2"
            >
                {{-- Spinner (visible while loading) --}}
                <svg wire:loading wire:target="runBackup"
                     class="animate-spin h-4 w-4 text-white"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{-- Icon (hidden while loading) --}}
                <svg wire:loading.remove wire:target="runBackup"
                     xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                <span wire:loading.remove wire:target="runBackup">Ejecutar Backup Ahora</span>
                <span wire:loading wire:target="runBackup">Ejecutando…</span>
            </x-primary-button>

            <x-secondary-button
                wire:click="runClean"
                wire:loading.attr="disabled"
                wire:target="runClean"
                class="inline-flex items-center gap-2"
            >
                <svg wire:loading wire:target="runClean"
                     class="animate-spin h-4 w-4"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg wire:loading.remove wire:target="runClean"
                     xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <span wire:loading.remove wire:target="runClean">Limpiar Backups Antiguos</span>
                <span wire:loading wire:target="runClean">Limpiando…</span>
            </x-secondary-button>

            <p class="text-xs text-muted-foreground sm:ml-auto">
                El backup automático está programado diariamente a las 02:00 AM
            </p>
        </div>
    </div>

    {{-- ── Error alert ── --}}
    @if($lastError)
        <div class="rounded-lg border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30 p-4 flex gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-red-500 mt-0.5" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <p class="text-sm font-medium text-red-700 dark:text-red-400">Error al ejecutar el backup</p>
                <p class="text-xs text-red-600 dark:text-red-300 mt-0.5 break-all">{{ $lastError }}</p>
            </div>
        </div>
    @endif

    {{-- ── Backups table ── --}}
    <div class="rounded-lg border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-4 py-3 flex items-center justify-between">
            <h3 class="text-base font-semibold text-foreground">Archivos de Backup</h3>
            <span class="text-xs text-muted-foreground">{{ count($this->backups) }} archivo(s)</span>
        </div>

        @if(empty($this->backups))
            {{-- Empty state --}}
            <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-muted-foreground/40 mb-4" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <p class="text-sm font-medium text-foreground mb-1">No hay backups disponibles</p>
                <p class="text-xs text-muted-foreground">
                    Ejecuta tu primer backup con el botón de arriba.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-muted-foreground border-b border-border bg-muted/40">
                            <th class="px-4 py-3 font-medium">Archivo</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Tamaño</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Fecha</th>
                            <th class="px-4 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($this->backups as $backup)
                            <tr class="hover:bg-muted/20 transition-colors">
                                {{-- Filename --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-muted-foreground" fill="none"
                                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="truncate max-w-xs font-mono text-xs text-foreground"
                                              title="{{ $backup['name'] }}">
                                            {{ $backup['name'] }}
                                        </span>
                                    </div>
                                </td>

                                {{-- Size --}}
                                <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                    {{ $backup['size'] }}
                                </td>

                                {{-- Date --}}
                                <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                    {{ $backup['date'] }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        {{-- Download --}}
                                        <x-secondary-button
                                            wire:click="download('{{ $backup['path'] }}')"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1.5 text-xs"
                                            title="Descargar backup"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none"
                                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                            </svg>
                                            Descargar
                                        </x-secondary-button>

                                        {{-- Delete --}}
                                        <x-danger-button
                                            x-on:click="confirm('¿Eliminar este backup?\n{{ addslashes($backup['name']) }}') && $wire.delete('{{ $backup['path'] }}')"
                                            class="inline-flex items-center gap-1.5 text-xs"
                                            title="Eliminar backup"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none"
                                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                            Eliminar
                                        </x-danger-button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ── Global loading overlay ── --}}
    <div wire:loading wire:target="runBackup"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
        <div class="rounded-xl border border-border bg-card px-8 py-6 shadow-xl flex flex-col items-center gap-4">
            <svg class="animate-spin h-10 w-10 text-primary"
                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div class="text-center">
                <p class="text-sm font-semibold text-foreground">Ejecutando backup…</p>
                <p class="text-xs text-muted-foreground mt-1">Esto puede tardar unos momentos. No cierres esta página.</p>
            </div>
        </div>
    </div>

</div>
