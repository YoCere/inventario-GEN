<?php

namespace Database\Seeders;

use App\Enums\FinanceCategoryType;
use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Categorías de entradas y salidas de caja, en el lenguaje del negocio.
 *
 * Idempotente (firstOrCreate por slug): correrlo dos veces no duplica, y una
 * instancia existente no pierde las categorías que el cliente haya creado.
 */
class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // Entradas de dinero.
            [
                'name' => 'Venta de productos',
                'type' => FinanceCategoryType::Income,
                'description' => 'Lo que entra por vender tu mercadería.',
            ],
            [
                'name' => 'Servicios y trabajos',
                'type' => FinanceCategoryType::Income,
                'description' => 'Arreglos, trabajos a pedido o servicios que cobres aparte.',
            ],
            [
                'name' => 'Otros ingresos',
                'type' => FinanceCategoryType::Income,
                'description' => 'Dinero que entra por fuera de la venta habitual.',
            ],

            // Salidas de dinero.
            [
                'name' => 'Compra de mercadería',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Lo que pagas por la mercadería o los materiales que vendes.',
            ],
            [
                'name' => 'Sueldos',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Sueldos, aguinaldos y beneficios del personal.',
            ],
            [
                'name' => 'Alquiler',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Alquiler del local, taller o depósito.',
            ],
            [
                'name' => 'Luz, agua e internet',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Servicios básicos y comunicación.',
            ],
            [
                'name' => 'Transporte y envíos',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Combustible, pasajes, fletes y entregas.',
            ],
            [
                'name' => 'Publicidad',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Promoción, redes sociales e impresiones.',
            ],
            [
                'name' => 'Mantenimiento y reparaciones',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Arreglo de máquinas, herramientas y equipos.',
            ],
            [
                'name' => 'Impuestos y trámites',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Pagos a Impuestos, patentes y trámites del negocio.',
            ],
            [
                'name' => 'Otros gastos',
                'type' => FinanceCategoryType::Expense,
                'description' => 'Gastos que no entran en las categorías de arriba.',
            ],
        ];

        foreach ($categories as $category) {
            FinanceCategory::firstOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                    'description' => $category['description'],
                ]
            );
        }
    }
}
