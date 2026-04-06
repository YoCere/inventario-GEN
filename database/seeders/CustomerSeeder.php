<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
    $customers = [
        ['name' => 'Juan Mamani', 'email' => 'juan.mamani@gmail.com', 'phone' => '71234567', 'address' => 'La Paz, Bolivia'],
        ['name' => 'María Quispe', 'email' => 'maria.quispe@hotmail.com', 'phone' => '72345678', 'address' => 'Cochabamba, Bolivia'],
        ['name' => 'Carlos Flores', 'email' => 'carlos.flores@gmail.com', 'phone' => '73456789', 'address' => 'Santa Cruz, Bolivia'],
        ['name' => 'Ana Condori', 'email' => 'ana.condori@gmail.com', 'phone' => '74567890', 'address' => 'Oruro, Bolivia'],
        ['name' => 'Pedro Huanca', 'email' => 'pedro.huanca@yahoo.com', 'phone' => '75678901', 'address' => 'Potosí, Bolivia'],
        ['name' => 'Rosa Apaza', 'email' => 'rosa.apaza@gmail.com', 'phone' => '76789012', 'address' => 'Sucre, Bolivia'],
        ['name' => 'Luis Choque', 'email' => 'luis.choque@gmail.com', 'phone' => '77890123', 'address' => 'Tarija, Bolivia'],
        ['name' => 'Elena Marca', 'email' => 'elena.marca@hotmail.com', 'phone' => '78901234', 'address' => 'Beni, Bolivia'],
        ['name' => 'Jorge Limachi', 'email' => 'jorge.limachi@gmail.com', 'phone' => '79012345', 'address' => 'Pando, Bolivia'],
        ['name' => 'Sofía Tarqui', 'email' => 'sofia.tarqui@gmail.com', 'phone' => '71122334', 'address' => 'La Paz, Bolivia'],
    ];

    foreach ($customers as $customer) {
        Customer::firstOrCreate(['email' => $customer['email']], array_merge($customer, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
}