<?php

use App\Shop\Http\Controllers\ReservationController;
use App\Shop\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

// Rutas públicas del módulo Tienda. Cargadas SOLO si shop_enabled='1'.
// Registro vía App\Shop\Providers\ShopServiceProvider::boot().
//
// El prefix '/tienda' se eligió en Bloque B para no chocar con el redirect
// '/' → '/dashboard' existente del POS.

Route::prefix('tienda')->name('shop.')->group(function () {
    Route::get('/', [ShopController::class, 'index'])->name('index');
    Route::get('/catalogo', [ShopController::class, 'catalog'])->name('catalog');
    Route::get('/api/search', [ShopController::class, 'search'])->name('search');
    Route::get('/producto/{slug}', [ShopController::class, 'show'])->name('product');

    // Checkout + reserva.
    Route::get('/checkout', [ReservationController::class, 'checkout'])->name('checkout');
    Route::post('/reservar', [ReservationController::class, 'store'])
        ->middleware('throttle:5,1') // 5 reservas / IP / minuto, anti-spam básico
        ->name('reservar');
});
