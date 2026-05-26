<x-app-layout title="Libro Diario">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Libro Diario') }}
            </h2>
            <div class="print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
            </div>
        </div>
    </x-slot>

    {{-- Print-only header --}}
    <div id="diario-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                @if($logoPath)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" style="height:50px; object-fit:contain;">
                @endif
                <div>
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;">{{ $storeName }}</div>
                    @if($storeAddress)<div style="font-size:9pt; color:#555;">{{ $storeAddress }}</div>@endif
                    @if($storePhone)<div style="font-size:9pt; color:#555;">Tel. {{ $storePhone }}</div>@endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Libro Diario</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong><br>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-journal-entries.journal-entry-table />
        </div>
    </div>

    <livewire:finance-journal-entries.journal-entry-detail />

    <style>
        /* ===== LIBRO DIARIO PRINT STYLES ===== */
        @media print {
            @page { size: letter portrait; margin: 1.5cm; }

            /* Hide navigation and UI chrome */
            nav,
            header,
            footer,
            .print\:hidden { display: none !important; }

            /* Show print header */
            #diario-print-header { display: block !important; }

            /* Reset layout */
            body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .py-4, .max-w-7xl { padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
            .bg-card, .border, .rounded-lg {
                background: transparent !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            .sm\:px-6, .lg\:px-8 { padding: 0 !important; }

            /* Table styles */
            table {
                font-size: 9pt !important;
                border-collapse: collapse !important;
                width: 100% !important;
                table-layout: fixed !important;
            }
            th, td {
                padding: 5px 6px !important;
                border: 1px solid #aaa !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                word-break: break-word !important;
            }
            thead { background: #f0f0f0 !important; }
            thead th { font-size: 8pt !important; text-transform: uppercase !important; }

            /*
             * PowerGrid column order (1-based nth-child):
             * 1=ACCION  2=ASIENTO  3=FECHA  4=DESCRIPCION  5=PERIODO  6=ESTADO  7=DEBE  8=HABER  9=CREADO POR
             * Keep: 2(ASIENTO), 3(FECHA), 4(DESCRIPCION), 7(DEBE), 8(HABER)
             * Hide: 1(ACCION), 5(PERIODO), 6(ESTADO), 9(CREADO POR)
             */
            table th:nth-child(1), table td:nth-child(1),
            table th:nth-child(5), table td:nth-child(5),
            table th:nth-child(6), table td:nth-child(6),
            table th:nth-child(9), table td:nth-child(9) { display: none !important; }

            /* Column widths for visible 5 columns */
            table th:nth-child(2), table td:nth-child(2) { width: 18% !important; }
            table th:nth-child(3), table td:nth-child(3) { width: 12% !important; }
            table th:nth-child(4), table td:nth-child(4) { width: 46% !important; }
            table th:nth-child(7), table td:nth-child(7) { width: 12% !important; text-align: right !important; }
            table th:nth-child(8), table td:nth-child(8) { width: 12% !important; text-align: right !important; }
        }
    </style>
</x-app-layout>
