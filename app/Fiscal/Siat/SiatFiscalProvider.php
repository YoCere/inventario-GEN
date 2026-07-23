<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;

/**
 * Adaptador SOAP real contra los servicios del SIN. Esqueleto mínimo: aún no verificado
 * contra el ambiente piloto del SIN. Se completa en la Tarea 7 de F1a.
 */
class SiatFiscalProvider implements FiscalProvider
{
    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }

    public function verificarComunicacion(string $recurso): bool
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        throw new \RuntimeException('SIAT real pendiente de ambiente piloto');
    }
}
