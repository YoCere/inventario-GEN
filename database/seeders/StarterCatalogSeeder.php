<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Categorías y unidades sugeridas según el rubro, para que el cliente no arranque
 * con la pantalla en blanco. Son solo un punto de partida: puede borrarlas o
 * renombrarlas desde Productos.
 *
 * NO carga productos: esos los carga el dueño del negocio, que es quien sabe sus
 * precios y su stock.
 */
class StarterCatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, categories: array<int, string>, units: array<int, array{0: string, 1: string}>}>
     */
    public const RUBROS = [
        'talabarteria' => [
            'label' => 'Talabartería y artículos de cuero',
            'categories' => [
                'Cintos',
                'Billeteras',
                'Carteras y bolsos',
                'Instrumentos',
                'Accesorios',
                'Trabajos a pedido',
            ],
            'units' => [
                ['Pieza', 'pza'],
                ['Par', 'par'],
                ['Metro', 'm'],
                ['Juego', 'jgo'],
            ],
        ],
        'tienda' => [
            'label' => 'Tienda o almacén general',
            'categories' => [
                'Bebidas',
                'Alimentos',
                'Limpieza',
                'Cuidado personal',
                'Otros',
            ],
            'units' => [
                ['Pieza', 'pza'],
                ['Caja', 'cja'],
                ['Kilogramo', 'kg'],
                ['Litro', 'ltr'],
            ],
        ],
    ];

    public string $rubro = 'talabarteria';

    public function run(): void
    {
        $rubro = self::RUBROS[$this->rubro] ?? null;
        if (! $rubro) {
            return;
        }

        foreach ($rubro['units'] as [$name, $symbol]) {
            Unit::firstOrCreate(['name' => $name], ['symbol' => $symbol]);
        }

        foreach ($rubro['categories'] as $name) {
            // El slug no se genera solo en el modelo: hay que darlo acá.
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
