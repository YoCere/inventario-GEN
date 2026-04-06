<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $suppliers = [
            ['name' => 'Importadora Andina S.R.L.', 'contact_person' => 'Roberto Vega', 'email' => 'contacto@importandina.com', 'phone' => '22345678', 'address' => 'La Paz, Bolivia'],
            ['name' => 'Distribuidora El Cóndor', 'contact_person' => 'Carmen Rojas', 'email' => 'ventas@elcondor.com', 'phone' => '44567890', 'address' => 'Santa Cruz, Bolivia'],
            ['name' => 'Comercial Pacífico Ltda.', 'contact_person' => 'Fernando Díaz', 'email' => 'info@comercialpacifico.com', 'phone' => '44123456', 'address' => 'Santa Cruz, Bolivia'],
            ['name' => 'Grupo Empresarial Altiplano', 'contact_person' => 'Miriam Copa', 'email' => 'gerencia@altiplano.com', 'phone' => '22678901', 'address' => 'La Paz, Bolivia'],
            ['name' => 'Importaciones del Sur S.A.', 'contact_person' => 'Héctor Soria', 'email' => 'hector@impsur.com', 'phone' => '46789012', 'address' => 'Tarija, Bolivia'],
            ['name' => 'TecnoImport Bolivia', 'contact_person' => 'Patricia Salinas', 'email' => 'ventas@tecnoimport.bo', 'phone' => '44234567', 'address' => 'Cochabamba, Bolivia'],
            ['name' => 'Agro Comercial Yungas', 'contact_person' => 'David Mamani', 'email' => 'david@agroyungas.com', 'phone' => '22789012', 'address' => 'La Paz, Bolivia'],
            ['name' => 'Ferretería Industrial Norte', 'contact_person' => 'Sandra Ríos', 'email' => 'sandra@ferrenorte.com', 'phone' => '33456789', 'address' => 'Oruro, Bolivia'],
        ];
    
        foreach ($suppliers as $supplier) {
            \App\Models\Supplier::firstOrCreate(['email' => $supplier['email']], array_merge($supplier, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
