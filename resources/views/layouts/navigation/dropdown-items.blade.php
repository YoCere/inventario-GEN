{{-- Ítems de un desplegable de escritorio, agrupados: arriba lo diario, abajo
     lo que se configura una vez (grupo con título). --}}
@foreach($section['groups'] as $groupIndex => $group)
    @if($groupIndex > 0)
        <div class="my-1 border-t border-border"></div>
    @endif

    @if($group['label'])
        <div class="px-3 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            {{ $group['label'] }}
        </div>
    @endif

    @foreach($group['items'] as $item)
        <a href="{{ $item['url'] }}"
           class="flex items-center gap-2 rounded-sm px-3 py-2 text-sm leading-5 transition-colors {{ $item['active'] ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted' }}">
            @if($item['icon'])
                <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-4 w-4 shrink-0 {{ $section['icon_color'] }}" />
            @endif
            <span class="flex-1">{{ $item['label'] }}</span>
            @if($item['badge'])
                <span class="ml-1 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-bold {{ \App\Support\Ui\Tone::badge(\App\Support\Ui\Tone::WARNING) }}">
                    {{ $item['badge'] }}
                </span>
            @endif
        </a>
    @endforeach
@endforeach
