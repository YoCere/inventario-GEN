@props(['status' => null, 'tone' => null, 'label' => null])

@php
    use App\Support\Ui\Tone;

    // Acepta un enum con tone()/label(), o tono y texto sueltos.
    $tone ??= (is_object($status) && method_exists($status, 'tone')) ? $status->tone() : Tone::NEUTRAL;
    $label ??= (is_object($status) && method_exists($status, 'label')) ? $status->label() : (string) $status;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ' . Tone::badge($tone)]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ Tone::dot($tone) }}"></span>
    {{ $label }}
</span>
