<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'Pieza',     'symbol' => 'pza'],
            ['name' => 'Caja',      'symbol' => 'cja'],
            ['name' => 'Kilogramo', 'symbol' => 'kg'],
            ['name' => 'Litro',     'symbol' => 'ltr'],
            ['name' => 'Metro',     'symbol' => 'm'],
            ['name' => 'Par',       'symbol' => 'par'],
            ['name' => 'Rollo',     'symbol' => 'roll'],
            ['name' => 'Bolsa',     'symbol' => 'bol'],
            ['name' => 'Docena',    'symbol' => 'doc'],
            ['name' => 'Pallet',    'symbol' => 'plt'],
        ];

        foreach ($units as $unit) {
            Unit::firstOrCreate(
                ['symbol' => $unit['symbol']],
                ['name' => $unit['name']]
            );
        }
    }
}
