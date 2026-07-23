<?php

namespace App\Fiscal\Siat;

use App\Models\Fiscal\FiscalCatalogEntry;

class CatalogSync
{
    /** Tipos que sincroniza el ciclo diario. */
    public const TYPES = ['actividad', 'producto_servicio', 'unidad', 'tipo_documento', 'metodo_pago', 'leyenda', 'mensaje'];

    public function __construct(private FiscalProvider $provider) {}

    public function sync(string $type): int
    {
        $entries = $this->provider->sincronizarCatalogo($type);
        $now = now();

        foreach ($entries as $entry) {
            FiscalCatalogEntry::updateOrCreate(
                ['catalog_type' => $type, 'code' => $entry['code']],
                ['description' => $entry['description'], 'synced_at' => $now],
            );
        }

        return count($entries);
    }

    public function syncAll(): void
    {
        foreach (self::TYPES as $type) {
            $this->sync($type);
        }
    }
}
