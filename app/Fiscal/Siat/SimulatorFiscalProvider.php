<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Implementación determinista para desarrollo y tests. No toca la red. Puede simular
 * caída de comunicación con `$this->online = false`.
 */
class SimulatorFiscalProvider implements FiscalProvider
{
    public bool $online = true;

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        return new Cuis(
            value: 'SIM-CUIS-' . strtoupper(Str::random(10)),
            expiresAt: CarbonImmutable::now()->addDays(365),
        );
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        return new Cufd(
            value: 'SIM-CUFD-' . strtoupper(Str::random(16)),
            sucursal: $sucursal,
            puntoVenta: $puntoVenta,
            expiresAt: CarbonImmutable::now()->addDay(),
            codigoControl: strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(2)),
            direccion: 'Dirección de prueba',
        );
    }

    public function verificarComunicacion(string $recurso): bool
    {
        return $this->online;
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        return match ($tipo) {
            'metodo_pago' => [
                ['code' => '1', 'description' => 'Efectivo'],
                ['code' => '2', 'description' => 'Tarjeta'],
                ['code' => '7', 'description' => 'Transferencia bancaria'],
            ],
            'unidad' => [
                ['code' => '58', 'description' => 'Unidad (servicios)'],
                ['code' => '1', 'description' => 'Bolsa'],
            ],
            'tipo_documento' => [
                ['code' => '1', 'description' => 'CI'],
                ['code' => '5', 'description' => 'NIT'],
            ],
            'actividad' => [['code' => '620000', 'description' => 'Actividad de prueba']],
            'leyenda' => [['code' => '1', 'description' => 'Ley N° 453: leyenda de prueba']],
            default => [],
        };
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        return ['codigoRecepcion' => 'SIM-REC-' . strtoupper(Str::random(8)), 'estado' => 'RECIBIDA'];
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        return ['estado' => 'ANULADA'];
    }
}
