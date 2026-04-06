<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electrónica e Informática', 'description' => 'Computadoras, tablets, celulares, accesorios y periféricos importados.'],
            ['name' => 'Electrodomésticos', 'description' => 'Refrigeradores, lavadoras, microondas, licuadoras y pequeños aparatos del hogar.'],
            ['name' => 'Ropa y Textiles', 'description' => 'Prendas de vestir, telas, ropa deportiva y accesorios de moda importados.'],
            ['name' => 'Herramientas y Ferretería', 'description' => 'Herramientas eléctricas y manuales, tornillos, tuberías y materiales de construcción.'],
            ['name' => 'Alimentos y Bebidas', 'description' => 'Productos alimenticios envasados, conservas, bebidas y condimentos importados.'],
            ['name' => 'Cosméticos y Cuidado Personal', 'description' => 'Perfumes, cremas, shampoo, maquillaje y artículos de higiene personal.'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['slug' => Str::slug($cat['name'])],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
