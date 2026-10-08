@php
    // Fuente única del menú: escritorio y móvil renderizan de lo mismo.
    $navSections = \App\Support\Ui\Navigation::mainFor(auth()->user());
    $navSettings = \App\Support\Ui\Navigation::settingsFor(auth()->user());
    $navAccount  = \App\Support\Ui\Navigation::accountFor(auth()->user());

    $companyLogoPath = \App\Models\Setting::get('store_logo_path');
    $companyLogoUrl  = $companyLogoPath ? \Illuminate\Support\Facades\Storage::url($companyLogoPath) : null;
    $companyName     = \App\Models\Setting::get('store_name', config('app.name'));
@endphp

<section class="py-4 bg-background border-b border-border print:hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data="{ mobileMenuOpen: false }">
        @include('layouts.navigation.desktop', [
            'sections' => $navSections,
            'settings' => $navSettings,
            'account' => $navAccount,
            'companyLogoUrl' => $companyLogoUrl,
            'companyName' => $companyName,
        ])

        @include('layouts.navigation.mobile', [
            'sections' => $navSections,
            'settings' => $navSettings,
            'account' => $navAccount,
            'companyLogoUrl' => $companyLogoUrl,
            'companyName' => $companyName,
        ])
    </div>
</section>
