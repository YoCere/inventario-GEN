<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Models\Fiscal\FiscalCufd;
use App\Models\Fiscal\FiscalCuis;

/**
 * Orquesta el ciclo de códigos de autorización. F1b consume `currentCufd()` para emitir.
 * El CUFD vence a las 24h (no al cierre comercial) → se re-pide on-demand si venció.
 */
class FiscalAuthority
{
    public function __construct(private FiscalProvider $provider) {}

    public function currentCufd(int $sucursal, int $puntoVenta): Cufd
    {
        $stored = FiscalCufd::validFor($sucursal, $puntoVenta)->first();
        if ($stored) {
            return new Cufd(
                $stored->value, $stored->sucursal, $stored->punto_venta,
                \Carbon\CarbonImmutable::instance($stored->expires_at),
                $stored->codigo_control, $stored->direccion,
            );
        }

        $cufd = $this->provider->obtenerCufd($sucursal, $puntoVenta);
        FiscalCufd::create([
            'value' => $cufd->value,
            'sucursal' => $cufd->sucursal,
            'punto_venta' => $cufd->puntoVenta,
            'codigo_control' => $cufd->codigoControl,
            'direccion' => $cufd->direccion,
            'expires_at' => $cufd->expiresAt,
        ]);

        return $cufd;
    }

    /** Asegura un CUIS vigente (renueva si falta o está por vencer, umbral 5 días). */
    public function ensureCuis(int $sucursal = 0, int $puntoVenta = 0): void
    {
        $valid = FiscalCuis::where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now()->addDays(5))
            ->exists();

        if ($valid) {
            return;
        }

        $cuis = $this->provider->obtenerCuis($sucursal, $puntoVenta);
        FiscalCuis::create([
            'value' => $cuis->value,
            'sucursal' => $sucursal,
            'punto_venta' => $puntoVenta,
            'expires_at' => $cuis->expiresAt,
        ]);
    }

    /** True si el CUIS vence dentro de la ventana de aviso (para alertar). */
    public function cuisExpiringSoon(int $sucursal = 0, int $puntoVenta = 0): bool
    {
        return ! FiscalCuis::where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now()->addDays(5))
            ->exists();
    }
}
