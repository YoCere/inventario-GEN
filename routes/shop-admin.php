<?php

use App\Shop\Http\Controllers\ReservationAdminController;
use Illuminate\Support\Facades\Route;

// Rutas admin del módulo Tienda (panel reservas web). Cargadas SOLO si
// shop_enabled='1'. Registro vía App\Shop\Providers\ShopServiceProvider::boot().

Route::middleware(['web', 'auth', 'verified', 'admin'])->group(function () {
    Route::prefix('admin/reservations')->name('shop.admin.')->group(function () {
        Route::get('/', [ReservationAdminController::class, 'index'])->name('reservations');
        Route::post('/{sale}/confirm', [ReservationAdminController::class, 'confirm'])->name('reservations.confirm');
        Route::post('/{sale}/cancel', [ReservationAdminController::class, 'cancel'])->name('reservations.cancel');
    });
});
