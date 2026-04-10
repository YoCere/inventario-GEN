<x-guest-layout title="Iniciar Sesión">
    <!-- Estado de la Sesión -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="space-y-6">
        <div class="space-y-2 text-center">
            <h1 class="text-2xl font-semibold tracking-tight">Iniciar Sesión</h1>
            <p class="text-sm text-muted-foreground">Ingrese su nombre de usuario para acceder a su cuenta</p>
        </div>

        <form method="POST" action="{{ route('login') }}" x-data="{ loading: false }" @submit="loading = true">
            @csrf

            <!-- Nombre de Usuario -->
            <x-form-input
                name="username"
                label="Nombre de Usuario"
                type="text"
                :value="old('username')"
                required
                autofocus
                autocomplete="username"
                :messages="$errors->get('username')"
            />

            <!-- Contraseña -->
            <div class="mt-4 space-y-2">
                <div class="flex items-center justify-between">
                    <x-input-label for="password" :value="__('Contraseña')" :required="true" />
                    @if (Route::has('password.request'))
                        <a class="text-sm font-medium text-primary hover:underline" href="{{ route('password.request') }}">
                            {{ __('¿Olvidó su contraseña?') }}
                        </a>
                    @endif
                </div>
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Recordarme -->
            <div class="block mt-4">
                <label for="remember_me" class="inline-flex items-center">
                    <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-primary shadow-sm focus:ring-ring" name="remember">
                    <span class="ms-2 text-sm text-muted-foreground">{{ __('Recordarme') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button class="w-full" ::disabled="loading">
                    <svg x-show="loading" style="display: none;" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('Iniciar Sesión') }}
                </x-primary-button>
            </div>

            <div class="mt-4 text-center text-sm">
                ¿No tiene una cuenta?
                <a href="{{ route('register') }}" class="underline text-primary">
                    Regístrese
                </a>
            </div>
        </form>
    </div>
</x-guest-layout>