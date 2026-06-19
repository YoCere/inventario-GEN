@props(['title' => ''])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}{{ !empty($title) ? ' | ' . $title : '' }}</title>

        {{-- Anti-flash dark mode: se ejecuta ANTES de que el CSS cargue para evitar
             el parpadeo blanco cuando el usuario tiene modo oscuro guardado. --}}
        <script>
            (function () {
                var theme = localStorage.getItem('theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (theme === 'dark' || (!theme && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-background text-foreground">
        <div class="min-h-screen bg-background flex flex-col">
            <div class="flex-1">
                @include('layouts.navigation')

                <!-- Page Heading -->
                @isset($header)
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                @endisset

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>

            <!-- Footer -->
            @include('layouts.footer')
        </div>
        <x-toaster />
        <livewire:components.delete-modal />
        @auth
            @if (auth()->user()->can('dashboard.view') && \App\Models\Setting::get('anthropic_api_key', '') !== '')
                <livewire:assistant.assistant-chat :current-route="request()->route()?->getName() ?? request()->path()" />
            @endif
        @endauth
        @livewireScripts
        @stack('scripts')
        <script>
            document.addEventListener('livewire:initialized', () => {
                Livewire.on('open-print-window', (event) => {
                    let url = event.url;
                    if (url) {
                        // Check if current page has 'filters[date_period]' in URL
                        const params = new URLSearchParams(window.location.search);
                        const periodFilter = params.get('filters[date_period]');

                        // If found and not already in target URL, append it
                        if (periodFilter && !url.includes('period=')) {
                            url += (url.includes('?') ? '&' : '?') + 'period=' + periodFilter;
                        }

                        window.open(url, '_blank');
                    }
                });
            });

            // Global Currency Formatter
            window.currencySymbol = "{{ \App\Models\Setting::get('currency_symbol', 'Rp') }}";
            window.currencyPosition = "{{ \App\Models\Setting::get('currency_position', 'left') }}";
            window.currencyFraction = parseInt("{{ \App\Models\Setting::get('currency_fraction_digits', 0) }}");
            window.thousandSeparator = "{{ \App\Models\Setting::get('currency_thousand_separator', '.') }}";
            window.decimalSeparator = "{{ \App\Models\Setting::get('currency_decimal_separator', ',') }}";

            window.formatMoney = function(val) {
                // val is stored in cents; divide ÷100 to display Bs
                let cents = parseFloat(val) || 0;
                let isNegative = cents < 0;
                let amount = Math.abs(cents) / 100;

                // Force 2 fraction digits (cents storage)
                let strAmount = amount.toFixed(2);
                let parts = strAmount.split('.');
                let integerPart = parts[0];
                let decimalPart = parts.length > 1 ? window.decimalSeparator + parts[1] : '';

                // Add thousand separators
                let rgx = /(\d+)(\d{3})/;
                while (rgx.test(integerPart)) {
                    integerPart = integerPart.replace(rgx, '$1' + window.thousandSeparator + '$2');
                }

                let num = integerPart + decimalPart;
                if (isNegative) num = '-' + num;

                return window.currencyPosition === 'left' ? window.currencySymbol + ' ' + num : num + ' ' + window.currencySymbol;
            };

            // Alias for consistency across older components
            window.formatCurrency = window.formatMoney;
        </script>

    </body>
</html>
