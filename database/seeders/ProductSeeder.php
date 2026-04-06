<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Cache ID untuk performa (Slug Kategori & Simbol Unit)
        $cats = Category::pluck('id', 'slug')->toArray();
        $units = Unit::pluck('id', 'symbol')->toArray();

       

        $getCat = fn($name) => $cats[Str::slug($name)] ?? array_values($cats)[0];
$getUnit = fn($sym) => $units[$sym] ?? array_values($units)[0];

$products = [
    // Electrónica
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Laptop Lenovo IdeaPad 15"', 'p' => 4200],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Tablet Samsung Galaxy A7', 'p' => 1350],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Celular Xiaomi Redmi 13C', 'p' => 980],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Audífonos Bluetooth JBL Tune 510', 'p' => 320],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Teclado Inalámbrico Logitech MK270', 'p' => 210],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Mouse Óptico USB Genérico', 'p' => 45],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Memoria USB 64GB Kingston', 'p' => 85],
    ['cat' => 'Electrónica e Informática', 'u' => 'pza', 'n' => 'Cargador Rápido 65W Type-C', 'p' => 120],

    // Electrodomésticos
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Refrigerador LG 260L No Frost', 'p' => 3800],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Lavadora Samsung 10kg', 'p' => 2900],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Microondas Whirlpool 20L', 'p' => 680],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Licuadora Oster 600W', 'p' => 280],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Plancha a Vapor Philips', 'p' => 195],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Ventilador de Pie 16" Honeywell', 'p' => 320],
    ['cat' => 'Electrodomésticos', 'u' => 'pza', 'n' => 'Cafetera Eléctrica 12 tazas', 'p' => 245],

    // Ropa y Textiles
    ['cat' => 'Ropa y Textiles', 'u' => 'pza', 'n' => 'Camiseta Deportiva Dry-Fit (Talla M)', 'p' => 65],
    ['cat' => 'Ropa y Textiles', 'u' => 'pza', 'n' => 'Pantalón Jean Clásico Hombre', 'p' => 180],
    ['cat' => 'Ropa y Textiles', 'u' => 'pza', 'n' => 'Zapatillas Running Marca Genérica', 'p' => 220],
    ['cat' => 'Ropa y Textiles', 'u' => 'doc', 'n' => 'Calcetines Algodón (Docena)', 'p' => 90],
    ['cat' => 'Ropa y Textiles', 'u' => 'm',   'n' => 'Tela Polar por Metro', 'p' => 28],
    ['cat' => 'Ropa y Textiles', 'u' => 'pza', 'n' => 'Chompa Lana Alpaca Mujer', 'p' => 310],

    // Herramientas
    ['cat' => 'Herramientas y Ferretería', 'u' => 'pza', 'n' => 'Taladro Percutor Bosch 650W', 'p' => 850],
    ['cat' => 'Herramientas y Ferretería', 'u' => 'pza', 'n' => 'Amoladora Angular 4.5" Black+Decker', 'p' => 620],
    ['cat' => 'Herramientas y Ferretería', 'u' => 'pza', 'n' => 'Juego de Llaves Combinadas 12 pzas', 'p' => 180],
    ['cat' => 'Herramientas y Ferretería', 'u' => 'cja', 'n' => 'Tornillos Autorroscantes 1" (Caja 200u)', 'p' => 35],
    ['cat' => 'Herramientas y Ferretería', 'u' => 'roll', 'n' => 'Cinta Métrica 5m Stanley', 'p' => 55],
    ['cat' => 'Herramientas y Ferretería', 'u' => 'pza', 'n' => 'Nivel de Burbuja 60cm', 'p' => 75],

    // Alimentos
    ['cat' => 'Alimentos y Bebidas', 'u' => 'cja', 'n' => 'Atún en Lata Van Camps (Caja 48u)', 'p' => 420],
    ['cat' => 'Alimentos y Bebidas', 'u' => 'cja', 'n' => 'Aceite de Soya Fino (Caja 12x1L)', 'p' => 380],
    ['cat' => 'Alimentos y Bebidas', 'u' => 'bol', 'n' => 'Arroz Largo Fino 25kg', 'p' => 185],
    ['cat' => 'Alimentos y Bebidas', 'u' => 'cja', 'n' => 'Gaseosa Coca-Cola 2.5L (Caja 6u)', 'p' => 95],
    ['cat' => 'Alimentos y Bebidas', 'u' => 'kg',  'n' => 'Café Molido Exportador', 'p' => 85],
    ['cat' => 'Alimentos y Bebidas', 'u' => 'cja', 'n' => 'Leche Evaporada Gloria (Caja 24u)', 'p' => 310],

    // Cosméticos
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'pza', 'n' => 'Shampoo Pantene 400ml', 'p' => 38],
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'pza', 'n' => 'Crema Corporal Nivea 400ml', 'p' => 52],
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'pza', 'n' => 'Perfume Importado 100ml (Surtido)', 'p' => 145],
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'cja', 'n' => 'Jabón Dove (Caja 72u)', 'p' => 360],
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'pza', 'n' => 'Desodorante Rexona 150ml Aerosol', 'p' => 42],
    ['cat' => 'Cosméticos y Cuidado Personal', 'u' => 'pza', 'n' => 'Pasta Dental Colgate Triple 150g', 'p' => 28],
];

        foreach ($products as $item) {
            Product::create([
                'category_id' => $getCat($item['cat']),
                'unit_id' => $getUnit($item['u']),
                'sku' => 'P.' . date('ymd') . '.' . strtoupper(Str::random(4)),
                'name' => $item['n'],
                'description' => 'Stok tersedia untuk ' . $item['n'],
                'purchase_price' => $item['p'] * 0.85, // Margin 15%
                'selling_price' => $item['p'],
                'quantity' => rand(10, 100),
                'min_stock' => 5,
                'is_active' => true,
            ]);
        }
    }
}
