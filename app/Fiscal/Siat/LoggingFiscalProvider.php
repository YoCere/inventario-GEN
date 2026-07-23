<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use App\Models\Fiscal\FiscalLog;
use App\Models\Setting;

/** Envuelve un FiscalProvider y registra cada llamada en fiscal_logs. */
class LoggingFiscalProvider implements FiscalProvider
{
    public function __construct(private FiscalProvider $wrapped) {}

    public function inner(): FiscalProvider
    {
        return $this->wrapped;
    }

    private function log(string $service, array $request, callable $call)
    {
        $env = Setting::get('siat_environment', 'piloto');
        try {
            $result = $call();
            FiscalLog::create([
                'service' => $service, 'environment' => $env,
                'request' => json_encode($request), 'response' => json_encode(['ok' => true]),
                'success' => true,
            ]);
            return $result;
        } catch (\Throwable $e) {
            FiscalLog::create([
                'service' => $service, 'environment' => $env,
                'request' => json_encode($request), 'response' => $e->getMessage(),
                'success' => false, 'error_code' => (string) $e->getCode(),
            ]);
            throw $e;
        }
    }

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        return $this->log('obtenerCuis', compact('sucursal', 'puntoVenta'), fn () => $this->wrapped->obtenerCuis($sucursal, $puntoVenta));
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        return $this->log('obtenerCufd', compact('sucursal', 'puntoVenta'), fn () => $this->wrapped->obtenerCufd($sucursal, $puntoVenta));
    }

    public function verificarComunicacion(string $recurso): bool
    {
        return $this->log('verificarComunicacion', compact('recurso'), fn () => $this->wrapped->verificarComunicacion($recurso));
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        return $this->log('sincronizarCatalogo', compact('tipo'), fn () => $this->wrapped->sincronizarCatalogo($tipo));
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        return $this->log('enviarFactura', $meta, fn () => $this->wrapped->enviarFactura($xmlFirmadoOComprimido, $meta));
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        return $this->log('anularFactura', compact('cuf', 'motivo'), fn () => $this->wrapped->anularFactura($cuf, $motivo, $meta));
    }
}
