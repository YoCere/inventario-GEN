<?php

namespace App\Services\Telegram;

use App\Services\CategoryService;
use App\Settings\ProductIntakeFields;
use Illuminate\Support\Facades\Log;

/**
 * Qué falta todavía para dar de alta un producto, y qué se usa de lo que no se
 * pregunta.
 *
 * Vive aparte porque hay dos puertas de entrada al mismo alta: el flujo escrito
 * paso a paso (BotProductHandler) y el audio, que pre-llena campos desde la
 * herramienta del agente (StartProductCreationTool). Cuando cada uno tenía su
 * propia lista de pasos, el audio terminaba preguntando cosas que el flujo
 * escrito ya no preguntaba.
 *
 * La regla de la dueña ("que no adivine el sistema") se cumple acá: un dato que
 * no se pregunta se completa con el valor por defecto que ella ve en Ajustes.
 * Nunca con una cuenta inventada sobre otro dato.
 */
class PasosDelAlta
{
    public function __construct(
        private UnidadDeVenta $unidades,
        private CategoryService $categorias,
    ) {}

    /**
     * Completa los datos que hoy no se preguntan. Devuelve el arreglo nuevo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function conDefectos(array $data): array
    {
        if (! ProductIntakeFields::pide('categoria') && empty($data['categoria_id'])) {
            $nombre = trim((string) ProductIntakeFields::porDefecto('categoria'));

            try {
                $categoria = $this->categorias->findOrCreateByName($nombre !== '' ? $nombre : 'General');
                $data['categoria_id']     = $categoria->id;
                $data['categoria_nombre'] = $categoria->name;
            } catch (\Throwable $e) {
                // Sin categoría el alta no puede cerrar, pero romperla acá sería
                // peor: el paso de categoría vuelve a pedirla.
                Log::warning('No se pudo usar la categoría por defecto del alta', ['error' => $e->getMessage()]);
            }
        }

        // Una sola unidad cargada también entra acá: no hay nada que elegir, así
        // que se usa esa y el alta no gasta una pregunta.
        if (! $this->unidades->hayQueElegir() && empty($data['unidad_id'])) {
            $unidad = $this->unidades->porDefecto();

            if ($unidad) {
                $data['unidad_id']     = $unidad->id;
                $data['unidad_nombre'] = $unidad->name;
            }
        }

        if (! ProductIntakeFields::pide('cantidad') && ! isset($data['cantidad'])) {
            $data['cantidad'] = (int) ProductIntakeFields::porDefecto('cantidad');
        }

        if (! ProductIntakeFields::pide('stock_minimo') && ! isset($data['stock_minimo'])) {
            $data['stock_minimo'] = (int) ProductIntakeFields::porDefecto('stock_minimo');
        }

        return $data;
    }

    /**
     * Primer paso que falta, en el orden en que se piden los datos.
     * 'nuevo:revisar' significa que ya no falta nada.
     *
     * @param array<string, mixed> $data
     */
    public function siguiente(array $data): string
    {
        return match (true) {
            empty($data['nombre'])             => 'nuevo:nombre',
            $this->faltaCategoria($data)       => 'nuevo:categoria',
            $this->faltaUnidad($data)          => 'nuevo:unidad',
            empty($data['precio_compra'])      => 'nuevo:precio_compra',
            empty($data['precio_venta'])       => 'nuevo:precio_venta',
            $this->faltaCantidad($data)        => 'nuevo:cantidad',
            $this->faltaStockMinimo($data)     => 'nuevo:stock_minimo',
            ProductIntakeFields::pide('foto')  => 'nuevo:foto',
            default                            => 'nuevo:revisar',
        };
    }

    /** @param array<string, mixed> $data */
    private function faltaCategoria(array $data): bool
    {
        return empty($data['categoria_id']) && ProductIntakeFields::pide('categoria');
    }

    /** @param array<string, mixed> $data */
    private function faltaUnidad(array $data): bool
    {
        return empty($data['unidad_id']) && $this->unidades->hayQueElegir();
    }

    /** @param array<string, mixed> $data */
    private function faltaCantidad(array $data): bool
    {
        return ! isset($data['cantidad']) && ProductIntakeFields::pide('cantidad');
    }

    /** @param array<string, mixed> $data */
    private function faltaStockMinimo(array $data): bool
    {
        return ! isset($data['stock_minimo']) && ProductIntakeFields::pide('stock_minimo');
    }
}
