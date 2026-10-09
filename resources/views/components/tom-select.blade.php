@props([
    'options' => [],
    'placeholder' => 'Select option...',
    'url' => null,
    'method' => 'GET',
    /**
     * Nombre del método Livewire que da de alta el registro con el texto que
     * escribió el usuario. Al pasarlo, el desplegable muestra una opción para
     * crear cuando lo tecleado no coincide con nada.
     *
     * El método recibe el nombre y debe devolver ['value' => ..., 'text' => ...]
     * (o null si no se pudo crear). Va vacío donde no corresponda: así el
     * selector se comporta como siempre para quien no tiene el permiso.
     */
    'createAction' => null,
    /** Verbo que acompaña al texto tecleado: Crear «cintos». */
    'createLabel' => 'Crear',
])

<div wire:ignore class="w-full">
    <select
        x-data="{
            tom: null,
            @if($attributes->has('wire:model'))
                value: @entangle($attributes->wire('model')),
            @elseif($attributes->has('x-model'))
                value: {{ $attributes->get('x-model') }},
            @else
                value: null,
            @endif

            init() {
                if (this.tom || this.$el.tomselect) return;

                this.$nextTick(() => {
                    if (this.tom || this.$el.tomselect) return;

                    // Handle Initial Search (Unresolved Items) - Prepare Data
                    const initialSearch = this.$el.getAttribute('data-initial-search');

                    let config = {
                        items: this.value ? [this.value] : [],
                        placeholder: (initialSearch && !this.value) ? initialSearch : '{{ $placeholder }}',
                        valueField: 'value',
                        labelField: 'text',
                        searchField: ['text'],
                        preload: 'focus',
                        plugins: ['clear_button'],
                        create: false,
                        sortField: {
                            field: 'text',
                            direction: 'asc'
                        },
                        onItemAdd: (value, item) => {
                            this.value = value;
                            if (this.tom.options[value]) {
                                this.$dispatch('option-selected', { name: '{{ $attributes->get("name") }}', value: value, item: this.tom.options[value] });
                            }
                        },
                        onItemRemove: (value) => {
                            this.value = null;
                            /* If removed, revert placeholder to the initial product name */
                        },
                        onClear: () => {
                            this.value = null;
                        }
                    };

                    /* Pre-load initial option if label is provided */
                    let initialLabel = this.$el.getAttribute('data-initial-label');
                    if (this.value && initialLabel) {
                        config.options = [{value: this.value, text: initialLabel, type: 'unknown'}];
                        config.items = [this.value];
                    }

                    if ('{{ $url }}') {
                        config.load = (query, callback) => {
                            let url = '{{ $url }}';
                            const method = '{{ strtoupper($method) }}';
                            let body = null;

                            if (method === 'GET') {
                                url += (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                            } else {
                                body = JSON.stringify({ q: query });
                            }

                            /* Check for dynamic params */
                            const dataParams = this.$el.getAttribute('data-params');
                            if (dataParams) {
                                try {
                                    const params = JSON.parse(dataParams);
                                    if (method === 'GET') {
                                        const queryString = new URLSearchParams(params).toString();
                                        url += '&' + queryString;
                                    } else {
                                        body = JSON.stringify({ ...JSON.parse(body || '{}'), ...params });
                                    }
                                } catch (e) {
                                    console.error('Invalid data-params JSON', e);
                                }
                            }

                            /* Get CSRF Token from Meta Tag */
                            const csrfToken = document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content');

                            fetch(url, {
                                method: method,
                                body: body,
                                credentials: 'include',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken || ''
                                }
                            })
                                .then(response => {
                                    if (!response.ok) throw new Error('Network response was not ok');
                                    return response.json();
                                })
                                .then(json => {
                                    if (Array.isArray(json)) {
                                        callback(json);
                                    } else {
                                        console.warn('TomSelect load: Expected array, got', json);
                                        callback();
                                    }
                                })
                                .catch((error) => {
                                    console.error('TomSelect load error:', error);
                                    callback();
                                });
                        };
                    }

@if($createAction)
                    /* Alta rápida desde el propio desplegable: el usuario escribe un
                       nombre que todavía no existe y lo crea sin salir del formulario.
                       La llamada va por Livewire, así que lo que ya tenía cargado viaja
                       y vuelve en el mismo request: no se pierde nada. */
                    const normalizar = (texto) => (texto || '').toString()
                        .normalize('NFD').replace(/[̀-ͯ]/g, '')
                        .toLowerCase().replace(/\s+/g, ' ').trim();

                    config.create = (input, callback) => {
                        const nombre = (input || '').replace(/\s+/g, ' ').trim();

                        if (!nombre || !this.$wire) {
                            callback();
                            return;
                        }

                        /* Mismo indicador que usa Tom Select mientras busca, para que
                           se vea que algo está pasando durante el alta. */
                        this.tom.wrapper.classList.add('loading');

                        this.$wire.call('{{ $createAction }}', nombre)
                            .then((creado) => {
                                if (creado && creado.value) {
                                    callback({ value: String(creado.value), text: creado.text });
                                } else {
                                    callback();
                                }
                            })
                            .catch(() => callback())
                            .finally(() => this.tom.wrapper.classList.remove('loading'));
                    };

                    /* La opción de crear solo aparece si lo tecleado no coincide con
                       ninguna de las opciones ya cargadas (ignorando tildes y mayúsculas). */
                    config.createFilter = (input) => {
                        const buscado = normalizar(input);

                        if (buscado.length < 2) return false;

                        const cargadas = this.tom ? Object.values(this.tom.options) : [];

                        return !cargadas.some((opcion) => normalizar(opcion.text) === buscado);
                    };

                    config.render = Object.assign({}, config.render, {
                        option_create: (data, escape) =>
                            `<div class='create flex items-center gap-2 px-3 py-2 text-sm font-medium text-amber-800 dark:text-amber-300 cursor-pointer'>`
                            + `<span class='flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200'>+</span>`
                            + `<span>{{ $createLabel }} «${escape(data.input)}»</span>`
                            + `</div>`,
                    });
@endif

                    this.tom = new TomSelect(this.$el, config);

                    /* Handle Initial Search - Search on Focus */
                    this.tom.on('focus', () => {
                        /* Only trigger if no value selected and search box is empty */
                        if (initialSearch && !this.value && this.tom.getValue() === '') {
                             /* Use setTimeout to ensure focus is fully handled */
                            setTimeout(() => {
                                if (!this.tom) return;
                                this.tom.setTextboxValue(initialSearch);
                                this.tom.search(initialSearch);
                            }, 50);
                        }
                    });

                    this.$watch('value', (newValue) => {
                        if (!this.tom) return;
                        const current = this.tom.getValue();
                        if (newValue !== current) {
                            if (!newValue) {
                                this.tom.clear(true);
                            } else {
                                this.tom.setValue(newValue, true);
                            }
                        }
                    });
                });
            }
        }"
        x-init="init"
        {{ $attributes->whereDoesntStartWith('wire:model') }}
        autocomplete="off"
    >
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $option)
            <option value="{{ $option['value'] }}">{{ $option['text'] ?? $option['label'] }}</option>
        @endforeach
    </select>
</div>
