<?php

namespace App\Fiscal\Siat;

use App\Models\Setting;

/**
 * Verifica la comunicación con el SIN POR RECURSO (el doc insiste: existe por recurso,
 * no una vez global) y mantiene el flag `fiscal_offline`. El manejo de contingencia
 * (empaquetar y reenviar) es F2; acá solo se prende/apaga el flag.
 */
class FiscalConnectivity
{
    public function __construct(private FiscalProvider $provider) {}

    public function check(string $recurso): bool
    {
        $ok = $this->provider->verificarComunicacion($recurso);
        Setting::set('fiscal_offline', $ok ? '0' : '1');

        return $ok;
    }

    public function isOffline(): bool
    {
        return Setting::get('fiscal_offline', '0') === '1';
    }
}
