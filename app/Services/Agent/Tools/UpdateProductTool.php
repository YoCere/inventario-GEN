<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Telegram\BotProductEditHandler;

/**
 * Manos para cambiar un producto que ya existe.
 *
 * Sin esto, cuando le pedían "cambiale la categoría al mate camionero", la IA
 * solo podía conversar: pedía la categoría, se la daban, y la volvía a pedir.
 * No entendía mal — no tenía ninguna herramienta para modificar un producto.
 *
 * La herramienta NO escribe en la base. Abre el mismo flujo de edición que el
 * camino escrito (BotProductEditHandler), con los datos ya anotados y el
 * resumen "260 → 300" a la vista. Guardar sigue siendo un toque de la persona:
 * un precio de venta mal transcrito es plata que se pierde en cada venta.
 */
class UpdateProductTool extends BaseTool
{
    public function __construct(private BotProductEditHandler $edicion) {}

    public function webExposed(): bool
    {
        return false; // El asistente de la web es solo lectura.
    }

    public function requiredPermission(): ?string
    {
        return 'products.manage';
    }

    public function name(): string
    {
        return 'update_product';
    }

    public function description(): string
    {
        return <<<DESC
        Cambia los datos de un producto que YA EXISTE en el inventario. Usala
        apenas el usuario pida editar, modificar, corregir, actualizar, subir o
        bajar algo de un producto ("cambiale el precio de venta del mate
        camionero a 300", "el mate camionero pasalo a la categoría Cintos",
        "corregile el nombre al P.251010.ABCD").

        NO sirve para crear productos (para eso está start_product_creation) ni
        para vender.

        Reglas:
        - 'product' es obligatorio: el nombre o el código (SKU) del producto.
          Pasá lo que dijo el usuario, sin adornos ("el mate camionero ese" →
          "mate camionero").
        - Mandá SOLO los campos que el usuario pidió cambiar. Si no dijo un
          valor, no lo inventes: dejalo afuera y la herramienta se lo pregunta
          con botones.
        - Los precios van en bolivianos, como los dijo el usuario (300, no
          30000).
        - La herramienta ya le manda al usuario el resumen y el botón Guardar.
          NADA queda guardado hasta que él toque Guardar: no le digas que el
          cambio ya está hecho, pedile que confirme.
        - Si hay varios productos parecidos, la herramienta le manda botones
          para que elija. No elijas vos ni adivines.
        DESC;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'        => ['type' => 'string',  'description' => 'Nombre o código (SKU) del producto a cambiar'],
                'name'           => ['type' => 'string',  'description' => 'Nombre nuevo del producto'],
                'category'       => ['type' => 'string',  'description' => 'Categoría nueva (puede ser parcial)'],
                'unit'           => ['type' => 'string',  'description' => 'Cómo se vende: "caja", "unidad", "par", "docena", "kilo"'],
                'purchase_price' => ['type' => 'number',  'description' => 'Precio de compra nuevo, en bolivianos'],
                'selling_price'  => ['type' => 'number',  'description' => 'Precio de venta nuevo, en bolivianos'],
                'quantity'       => ['type' => 'integer', 'description' => 'Cuántas unidades hay en realidad (corrección de stock)'],
                'min_stock'      => ['type' => 'integer', 'description' => 'Con cuántas unidades avisar para reponer. 0 = no avisar.'],
                'featured'       => ['type' => 'boolean', 'description' => 'true para destacarlo en la tienda, false para quitarlo'],
                'is_public'      => ['type' => 'boolean', 'description' => 'true para mostrarlo en la tienda en línea, false para esconderlo'],
            ],
            'required' => ['product'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $producto = trim((string) ($input['product'] ?? ''));

        if ($producto === '') {
            return ['error' => 'Falta decir qué producto hay que cambiar (nombre o código).'];
        }

        $cambios = [];

        if (! empty($input['name'])) {
            $cambios['nombre'] = trim((string) $input['name']);
        }

        if (! empty($input['category'])) {
            $cambios['categoria'] = trim((string) $input['category']);
        }

        if (! empty($input['unit'])) {
            $cambios['unidad'] = trim((string) $input['unit']);
        }

        // El esquema es solo orientativo: el modelo puede mandar texto donde se
        // espera un número. Un casteo silencioso dejaría un precio en 0.00 y se
        // vendería a ese precio, así que se rechaza antes de tocar el flujo.
        foreach (['purchase_price' => 'precio_compra', 'selling_price' => 'precio_venta'] as $entrada => $campo) {
            if (! isset($input[$entrada]) || $input[$entrada] === '') {
                continue;
            }

            if (! is_numeric($input[$entrada])) {
                return ['error' => 'Ese precio no es un número. Preguntale al usuario cuánto es.'];
            }

            if ((float) $input[$entrada] <= 0) {
                return ['error' => 'Un precio tiene que ser mayor a cero. Preguntale al usuario cuánto es.'];
            }

            // Los precios se guardan en centavos, como en el alta.
            $cambios[$campo] = (int) round(((float) $input[$entrada]) * 100);
        }

        foreach (['quantity' => 'cantidad', 'min_stock' => 'stock_minimo'] as $entrada => $campo) {
            if (! isset($input[$entrada]) || $input[$entrada] === '') {
                continue;
            }

            if (! is_numeric($input[$entrada]) || (int) $input[$entrada] < 0) {
                return ['error' => "Ese valor de {$campo} no sirve: tiene que ser un número de cero para arriba."];
            }

            $cambios[$campo] = (int) $input[$entrada];
        }

        if (array_key_exists('featured', $input) && $input['featured'] !== null) {
            $cambios['destacado'] = (bool) $input['featured'];
        }

        if (array_key_exists('is_public', $input) && $input['is_public'] !== null) {
            $cambios['publicar'] = (bool) $input['is_public'];
        }

        // La categoría y la unidad viajan como lo que dijo el usuario: las
        // resuelve el flujo de edición, con la misma lógica que cuando se
        // escribe, y si no corresponden a ninguna las pregunta con botones. Dos
        // resoluciones distintas para el mismo dato terminarían contestando
        // cosas distintas.
        return $this->edicion->iniciarConCambios($context->chatId, $producto, $cambios);
    }
}
