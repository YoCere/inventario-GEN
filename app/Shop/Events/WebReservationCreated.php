<?php

namespace App\Shop\Events;

use App\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado cuando se crea una reserva con source='web' desde el catálogo público.
 * Los listeners (notificaciones Telegram, métricas, etc.) reaccionan a este evento
 * en lugar de acoplarse directamente a ReservationService.
 */
class WebReservationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Sale $sale) {}
}
