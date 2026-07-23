<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;

/**
 * Contrato único con los servicios del SIN. Dos implementaciones: SimulatorFiscalProvider
 * (default, para desarrollar/testear sin el SIN) y SiatFiscalProvider (SOAP real).
 * enviarFactura/anularFactura las implementa F1b; se declaran acá para fijar el contrato.
 */
interface FiscalProvider
{
    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis;

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd;

    public function verificarComunicacion(string $recurso): bool;

    /** @return array<int,array{code:string,description:string}> */
    public function sincronizarCatalogo(string $tipo): array;

    // --- F1b (declaradas para el contrato; el simulador puede responder OK) ---
    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array;

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array;
}
