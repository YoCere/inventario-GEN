<x-app-layout title="Nueva planilla de sueldo">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Nueva planilla de sueldo</h2>
            <a href="{{ route('finance.payroll.index') }}" class="text-sm text-muted-foreground hover:underline">Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm">
                    <p class="font-semibold mb-1">Corrige los siguientes errores:</p>
                    <ul class="list-disc pl-5 space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('finance.payroll.store') }}" class="bg-card border border-border rounded-lg p-5 space-y-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="period_month" class="block text-sm font-medium mb-1">Periodo (mes)</label>
                        <input type="date" name="period_month" id="period_month" value="{{ old('period_month', now()->startOfMonth()->toDateString()) }}" class="w-full rounded-md border-input bg-background text-sm" required />
                    </div>
                    <div>
                        <label for="payment_date" class="block text-sm font-medium mb-1">Fecha de pago</label>
                        <input type="date" name="payment_date" id="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" class="w-full rounded-md border-input bg-background text-sm" required />
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium mb-1">Descripcion</label>
                        <input type="text" name="description" id="description" value="{{ old('description') }}" class="w-full rounded-md border-input bg-background text-sm" placeholder="Ej: Planilla abril 2026" />
                    </div>
                </div>

                <div class="rounded-md border border-border p-3 bg-muted/20 text-xs text-muted-foreground">
                    <p class="font-medium mb-1">Parametros activos (Ajustes > Nomina):</p>
                    <p>
                        Bono frontera: {{ number_format($rates['border_bonus_rate'], 2, '.', ',') }}% |
                        Aporte laboral: {{ number_format($rates['labor_contribution_rate'], 2, '.', ',') }}% |
                        RC-IVA: {{ number_format($rates['rc_iva_rate'], 2, '.', ',') }}%
                    </p>
                    <p>
                        Aporte patronal: {{ number_format($rates['employer_contribution_rate'], 2, '.', ',') }}% |
                        Aguinaldo: {{ number_format($rates['aguinaldo_provision_rate'], 2, '.', ',') }}% |
                        Indemnizacion: {{ number_format($rates['indemnization_provision_rate'], 2, '.', ',') }}%
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Detalle de trabajadores</h3>
                    <button type="button" id="add-row" class="inline-flex items-center px-3 py-1.5 rounded-md border border-border text-sm hover:bg-muted/40">
                        Agregar fila
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs border border-border rounded-md overflow-hidden">
                        <thead class="bg-muted/50 border-b border-border">
                            <tr>
                                <th class="px-2 py-2 text-left">Trabajador</th>
                                <th class="px-2 py-2 text-left">Cargo</th>
                                <th class="px-2 py-2 text-left">Area</th>
                                <th class="px-2 py-2 text-right">Antig. (%)</th>
                                <th class="px-2 py-2 text-right">Dias</th>
                                <th class="px-2 py-2 text-right">Haber basico</th>
                                <th class="px-2 py-2 text-right">Horas extra</th>
                                <th class="px-2 py-2 text-right">Otros desc.</th>
                                <th class="px-2 py-2 text-center">Bono frontera</th>
                                <th class="px-2 py-2 text-center">Quitar</th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>

                <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-border">
                    <a href="{{ route('finance.payroll.index') }}" class="inline-flex items-center px-4 py-2 rounded-md border border-border text-sm">Cancelar</a>
                    <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium">
                        Guardar planilla
                    </button>
                </div>
            </form>
        </div>
    </div>

    <template id="row-template">
        <tr class="border-b border-border">
            <td class="px-2 py-2">
                <input type="text" name="items[__IDX__][employee_name]" class="w-44 rounded-md border-input bg-background text-xs" required />
            </td>
            <td class="px-2 py-2">
                <input type="text" name="items[__IDX__][position]" class="w-36 rounded-md border-input bg-background text-xs" />
            </td>
            <td class="px-2 py-2">
                <select name="items[__IDX__][area]" class="w-28 rounded-md border-input bg-background text-xs" required>
                    <option value="mod">MOD</option>
                    <option value="moi">MOI</option>
                    <option value="sales">Ventas</option>
                    <option value="admin" selected>Administracion</option>
                </select>
            </td>
            <td class="px-2 py-2">
                <input type="number" step="0.0001" min="0" max="1" name="items[__IDX__][antiquity_rate]" value="0.05" class="w-24 rounded-md border-input bg-background text-xs text-right" required />
            </td>
            <td class="px-2 py-2">
                <input type="number" min="0" max="31" name="items[__IDX__][worked_days]" value="30" class="w-20 rounded-md border-input bg-background text-xs text-right" required />
            </td>
            <td class="px-2 py-2">
                <input type="number" min="0" name="items[__IDX__][base_salary]" value="0" class="w-28 rounded-md border-input bg-background text-xs text-right" required />
            </td>
            <td class="px-2 py-2">
                <input type="number" min="0" name="items[__IDX__][hours_extra]" value="0" class="w-24 rounded-md border-input bg-background text-xs text-right" />
            </td>
            <td class="px-2 py-2">
                <input type="number" min="0" name="items[__IDX__][other_discounts]" value="0" class="w-24 rounded-md border-input bg-background text-xs text-right" />
            </td>
            <td class="px-2 py-2 text-center">
                <input type="hidden" name="items[__IDX__][apply_border_bonus]" value="0" />
                <input type="checkbox" name="items[__IDX__][apply_border_bonus]" value="1" checked />
            </td>
            <td class="px-2 py-2 text-center">
                <button type="button" class="remove-row text-red-600 hover:underline">Quitar</button>
            </td>
        </tr>
    </template>

    <script>
        (function () {
            const body = document.getElementById('items-body');
            const template = document.getElementById('row-template').innerHTML;
            const addRowBtn = document.getElementById('add-row');
            let idx = 0;

            function addRow(defaults = {}) {
                const html = template.replaceAll('__IDX__', String(idx));
                const holder = document.createElement('tbody');
                holder.innerHTML = html.trim();
                const row = holder.firstChild;
                body.appendChild(row);

                if (defaults.employee_name) row.querySelector(`[name="items[${idx}][employee_name]"]`).value = defaults.employee_name;
                if (defaults.position) row.querySelector(`[name="items[${idx}][position]"]`).value = defaults.position;
                if (defaults.area) row.querySelector(`[name="items[${idx}][area]"]`).value = defaults.area;
                if (defaults.antiquity_rate) row.querySelector(`[name="items[${idx}][antiquity_rate]"]`).value = defaults.antiquity_rate;
                if (defaults.worked_days) row.querySelector(`[name="items[${idx}][worked_days]"]`).value = defaults.worked_days;
                if (defaults.base_salary) row.querySelector(`[name="items[${idx}][base_salary]"]`).value = defaults.base_salary;
                if (defaults.hours_extra) row.querySelector(`[name="items[${idx}][hours_extra]"]`).value = defaults.hours_extra;
                if (defaults.other_discounts) row.querySelector(`[name="items[${idx}][other_discounts]"]`).value = defaults.other_discounts;
                if (defaults.apply_border_bonus === '0') {
                    row.querySelector(`[name="items[${idx}][apply_border_bonus]"][value="1"]`).checked = false;
                }

                idx++;
            }

            addRowBtn.addEventListener('click', () => addRow());
            body.addEventListener('click', (event) => {
                if (!event.target.classList.contains('remove-row')) return;
                const rows = body.querySelectorAll('tr');
                if (rows.length <= 1) return;
                event.target.closest('tr').remove();
            });

            @if(old('items'))
                const oldItems = @json(old('items'));
                oldItems.forEach((item) => addRow(item));
            @else
                addRow();
            @endif
        })();
    </script>
</x-app-layout>

