<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use App\Models\Setting;

/**
 * Adaptador real contra los servicios SOAP del SIN (modalidad Computarizada en Línea).
 *
 * ⚠️ NO VERIFICADO EN VIVO: escrito desde los XSD/documentación conocidos, pero sin
 * ejercitar contra el ambiente piloto (credenciales + WSDL pendientes). Hasta configurar
 * el piloto, cada método lanza RuntimeException en vez de devolver datos falsos —
 * el desarrollo/testeo de F1 corre contra SimulatorFiscalProvider. Al habilitar el piloto:
 * completar los cuerpos SOAP (los @todo) y setear `fiscal_provider=siat`.
 *
 * Servicios SOAP del SIN (referencia; los WSDL exactos salen del portal piloto):
 *  - Códigos:        obtenerCuis, obtenerCufd
 *  - Sincronización: verificarComunicacion, sincronizarCatalogo (actividades, productos, unidades, etc.)
 *  - Recepción:      enviarFactura (recepción individual), anularFactura
 */
class SiatFiscalProvider implements FiscalProvider
{
    private function requireEnvironment(): void
    {
        if (! Setting::get('siat_api_token') || ! Setting::get('siat_wsdl_codigos')) {
            throw new \RuntimeException(
                'SIAT real requiere ambiente piloto: configurar WSDL y credenciales (siat_wsdl_codigos, siat_api_token).'
            );
        }
    }

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        $this->requireEnvironment();
        // @todo piloto: SoapClient(siat_wsdl_codigos)->cuis([nit, sistema, ambiente, sucursal, ...]) → mapear a Cuis
        throw new \RuntimeException('obtenerCuis SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        $this->requireEnvironment();
        // @todo piloto: SoapClient(siat_wsdl_codigos)->cufd([cuis, nit, sucursal, puntoVenta, ...]) → mapear a Cufd
        throw new \RuntimeException('obtenerCufd SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function verificarComunicacion(string $recurso): bool
    {
        $this->requireEnvironment();
        // @todo piloto: verificarComunicacion del WSDL correspondiente al $recurso
        throw new \RuntimeException('verificarComunicacion SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        $this->requireEnvironment();
        // @todo piloto: servicio de sincronización de catálogos → array de ['code','description']
        throw new \RuntimeException('sincronizarCatalogo SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        $this->requireEnvironment();
        // @todo F1b + piloto: recepción individual → código de recepción / lista de errores
        throw new \RuntimeException('enviarFactura SOAP pendiente (F1b + piloto).');
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        $this->requireEnvironment();
        // @todo F1b + piloto: servicio de anulación
        throw new \RuntimeException('anularFactura SOAP pendiente (F1b + piloto).');
    }
}
