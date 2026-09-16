@php
    $key = $field['key'];
    $model = 'values.' . $key;
    $id = 'setting-' . $key;
    $type = $field['type'];
    $live = $live ?? false;
    $wireModel = $live ? 'wire:model.live.debounce.300ms' : 'wire:model';
    // Mismas clases que <x-text-input>; inputs planos porque el nombre del wire:model es dinámico.
    $inputClass = 'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
    $selectClass = 'flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
@endphp

@if($type === 'toggle')
    <div class="flex items-start justify-between gap-6 rounded-lg border border-border px-4 py-3.5 sm:col-span-2"
         x-data="{ on: $wire.entangle('{{ $model }}') }">
        <div class="min-w-0">
            <label for="{{ $id }}" class="text-sm font-medium text-foreground">{{ $field['label'] }}</label>
            @isset($field['help'])
                <p class="mt-0.5 text-sm text-muted-foreground">{{ $field['help'] }}</p>
            @endisset
        </div>
        <button type="button" id="{{ $id }}" role="switch"
                :aria-checked="on === '1' ? 'true' : 'false'"
                x-on:click="on = on === '1' ? '0' : '1'; dirty = true"
                :class="on === '1' ? 'bg-emerald-600' : 'bg-zinc-300 dark:bg-zinc-700'"
                class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
            <span class="sr-only">{{ $field['label'] }}</span>
            <span :class="on === '1' ? 'translate-x-6' : 'translate-x-1'"
                  class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"></span>
        </button>
    </div>
@else
    <div @class(['space-y-1.5', 'sm:col-span-2' => ! empty($field['wide'])])>
        <x-input-label :for="$id" :value="$field['label']" />

        @switch($type)
            @case('select')
                <select id="{{ $id }}" {{ $wireModel }}="{{ $model }}" class="{{ $selectClass }}">
                    @foreach(\App\Settings\SettingsCatalog::options($field, \App\Models\Setting::get($key) ?? $field['default']) as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @break

            @case('textarea')
                <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="{{ $key === 'ai_system_prompt' ? 8 : 3 }}"
                          class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"></textarea>
                @break

            @case('secret')
                <div x-data="{ show: false }" class="relative">
                    <input id="{{ $id }}" wire:model="{{ $model }}" x-bind:type="show ? 'text' : 'password'" type="password"
                           autocomplete="new-password" class="{{ $inputClass }} pr-10"
                           placeholder="{{ $this->secretHint($key) ? $this->secretHint($key) . ' — escribe para reemplazar' : 'Sin configurar' }}">
                    <button type="button" x-on:click="show = !show" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground hover:text-foreground"
                            :aria-label="show ? 'Ocultar' : 'Mostrar'">
                        <x-heroicon-o-eye x-show="!show" class="h-4 w-4" />
                        <x-heroicon-o-eye-slash x-show="show" x-cloak class="h-4 w-4" />
                    </button>
                </div>
                @break

            @case('color')
                <div class="flex items-center gap-2">
                    <input type="color" wire:model.live="{{ $model }}" aria-label="{{ $field['label'] }}"
                           class="h-10 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1">
                    <input id="{{ $id }}" type="text" wire:model.live.debounce.500ms="{{ $model }}" maxlength="7" class="{{ $inputClass }} font-mono uppercase">
                </div>
                @break

            @default
                @php
                    $inputType = match ($type) {
                        'percent', 'number' => 'number',
                        'date' => 'date',
                        'url' => 'url',
                        'phone' => 'tel',
                        default => 'text',
                    };
                    $suffix = $field['suffix'] ?? ($type === 'percent' ? '%' : null);
                @endphp
                <div class="relative">
                    <input id="{{ $id }}" type="{{ $inputType }}" {{ $wireModel }}="{{ $model }}"
                           @class([$inputClass, 'pr-14' => $suffix, 'font-mono' => $type === 'account'])
                           @if(in_array($type, ['percent', 'number'], true)) step="any" min="0" @endif
                           @if($type === 'phone') inputmode="numeric" @endif>
                    @if($suffix)
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-muted-foreground">{{ $suffix }}</span>
                    @endif
                </div>
        @endswitch

        @isset($field['help'])
            <p class="text-sm text-muted-foreground">{{ $field['help'] }}</p>
        @endisset
        @error($model)
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>
@endif
