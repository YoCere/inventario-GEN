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

@php
    /**
     * REGLA DE ORO DE ESTE ARCHIVO: nada de @if / @foreach dentro del x-data.
     *
     * Livewire inserta marcadores HTML (`<!--[if BLOCK]><![endif]-->`) donde hay
     * un bloque condicional. Dentro de un atributo de JavaScript eso es veneno:
     * en JS `<!--` comenta HASTA EL FINAL DE LA LÍNEA, así que se traga lo que
     * venga detrás en esa misma línea — la apertura de un `/* ... *\/`, o la
     * propia línea del `value:` — y lo que sigue se ejecuta como código suelto.
     * Resultado: SyntaxError, el selector nunca se inicializa y queda un
     * `<select>` pelado, sin búsqueda y sin opciones (se cargan por AJAX).
     *
     * Por eso todo lo condicional se arma acá, en PHP, y se imprime de una sola
     * vez. Si alguien vuelve a poner un @if adentro del x-data, el test
     * TomSelectExpressionTest se cae.
     */

    // Enlace con la propiedad de Livewire. $wire.entangle hace lo mismo que la
    // directiva @entangle, pero sin necesitar un bloque condicional.
    $valueExpression = 'null';

    if ($attributes->has('wire:model')) {
        $wireModel = $attributes->wire('model');
        $valueExpression = "\$wire.entangle('{$wireModel->value()}')";

        if ($wireModel->hasModifier('live')) {
            $valueExpression .= '.live';
        }
    } elseif ($attributes->has('x-model')) {
        $valueExpression = $attributes->get('x-model');
    }

    // Alta rápida desde el propio desplegable: el usuario escribe un nombre que
    // todavía no existe y lo crea sin salir del formulario. La llamada va por
    // Livewire, así que lo que ya tenía cargado viaja y vuelve en el mismo
    // request: no se pierde nada.
    $createSnippet = '';

    if ($createAction) {
        // Nowdoc (no heredoc) a propósito: adentro hay ${...} de plantillas de
        // JavaScript que PHP intentaría interpolar.
        $createSnippet = <<<'JS'
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

                        this.$wire.call('__CREATE_ACTION__', nombre)
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
                            + `<span>__CREATE_LABEL__ «${escape(data.input)}»</span>`
                            + `</div>`,
                    });
JS;

        $createSnippet = str_replace(
            ['__CREATE_ACTION__', '__CREATE_LABEL__'],
            [$createAction, $createLabel],
            $createSnippet
        );
    }

    // Carga de opciones por AJAX. Sin url, el selector trabaja con las opciones
    // que ya vienen en el HTML.
    $loadSnippet = '';

    if ($url) {
        $loadSnippet = <<<'JS'
                    config.load = (query, callback) => {
                        let url = '__URL__';
                        const method = '__METHOD__';
                        let body = null;

                        if (method === 'GET') {
                            url += (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                        } else {
                            body = JSON.stringify({ q: query });
                        }

                        /* Parámetros extra que el formulario pueda agregar en caliente. */
                        const dataParams = this.$el.getAttribute('data-params');
                        if (dataParams) {
                            try {
                                const params = JSON.parse(dataParams);
                                if (method === 'GET') {
                                    url += '&' + new URLSearchParams(params).toString();
                                } else {
                                    body = JSON.stringify({ ...JSON.parse(body || '{}'), ...params });
                                }
                            } catch (e) {
                                console.error('Invalid data-params JSON', e);
                            }
                        }

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
JS;

        $loadSnippet = str_replace(
            ['__URL__', '__METHOD__'],
            [$url, strtoupper($method)],
            $loadSnippet
        );
    }
@endphp

<div wire:ignore class="w-full">
    <select
        x-data="{
            tom: null,
            value: {!! $valueExpression !!},

            init() {
                if (this.tom || this.$el.tomselect) return;

                this.$nextTick(() => {
                    if (this.tom || this.$el.tomselect) return;

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
                        },
                        onClear: () => {
                            this.value = null;
                        }
                    };

                    /* Opción ya elegida: se precarga para que se vea la etiqueta
                       sin esperar a la búsqueda. */
                    let initialLabel = this.$el.getAttribute('data-initial-label');
                    if (this.value && initialLabel) {
                        config.options = [{value: this.value, text: initialLabel, type: 'unknown'}];
                        config.items = [this.value];
                    }

{!! $loadSnippet !!}

{!! $createSnippet !!}

                    this.tom = new TomSelect(this.$el, config);

                    /* Búsqueda inicial sugerida: se dispara al enfocar. */
                    this.tom.on('focus', () => {
                        if (initialSearch && !this.value && this.tom.getValue() === '') {
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
