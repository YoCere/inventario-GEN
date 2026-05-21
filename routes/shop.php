<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Route;

// Rutas públicas del módulo Tienda. Cargadas SOLO si shop_enabled='1'.
// Registro vía App\Shop\Providers\ShopServiceProvider::boot().
//
// El prefix '/tienda' se eligió en Bloque B para no chocar con el redirect
// '/' → '/dashboard' existente del POS. Si en el futuro se quiere mover al
// dominio raíz, basta con quitar el prefix y ajustar SettingGroups::shopPublicUrl.

Route::prefix('tienda')->name('shop.')->group(function () {

    // Placeholder mientras se construye el catálogo real (Bloque D).
    // Sirve para validar que el feature flag + provider funcionan y para
    // que el QR del panel de Settings apunte a una página accesible.
    Route::get('/', function () {
        return view('shop.placeholder', [
            'businessName' => Setting::get('shop_business_name') ?: config('app.name'),
            'logoUrl' => Setting::get('shop_logo_path')
                ? \Illuminate\Support\Facades\Storage::url(Setting::get('shop_logo_path'))
                : null,
            'primaryColor' => Setting::get('shop_primary_color', '#2563EB'),
            'textOnPrimary' => Setting::get('shop_text_on_primary', '#FFFFFF'),
            'welcomeMessage' => Setting::get('shop_welcome_message'),
        ]);
    })->name('index');

});
