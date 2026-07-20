{{--
    Selector de destino: catálogo / WhatsApp / URL propia.

    $field   = nombre del binding wire:model (ej. 'form.cta_target' o 'form.target')
    $current = valor actual del campo (ej. $form['cta_target'] ?? 'catalog')

    NOTA: no usamos data_get($this, $field) acá porque las vistas Blade compiladas
    se ejecutan dentro de una closure estática (Filesystem::getRequire), así que
    $this no está enlazado al componente Livewire. El valor actual se pasa explícito
    desde el caller.
--}}
<select wire:model.live="{{ $field }}" class="w-full rounded-md border-input bg-background text-sm">
    <option value="catalog">El catálogo de la tienda</option>
    <option value="whatsapp">WhatsApp del negocio</option>
    <option value="{{ in_array($current, ['catalog', 'whatsapp'], true) ? '' : $current }}">Otra dirección…</option>
</select>

@if(! in_array($current, ['catalog', 'whatsapp'], true))
    <input type="text" wire:model="{{ $field }}" placeholder="https://… o /pagina"
           class="mt-2 w-full rounded-md border-input bg-background text-sm">
@endif
@error($field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
