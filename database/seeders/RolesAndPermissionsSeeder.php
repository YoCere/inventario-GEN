<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catálogo canonical de roles + permisos del sistema.
 *
 * Roles base (no se borran):
 *   - developer  → super-usuario vía Gate::before. Permisos asignados igual
 *                  por trazabilidad pero técnicamente ya pasan todos los gates.
 *   - admin      → todo lo de negocio. Excluye técnicos + admin de roles/users.
 *   - staff      → POS básico: ventas + clientes + ver productos.
 *
 * Permisos: por feature.acción. Granularidad pragmática (no over-engineered).
 * El admin puede crear roles custom desde la UI y asignar combos de estos
 * permisos según necesite (ej. "Cajero", "Contador", "Compras").
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var array<string,string> permission name => human label
     */
    public const PERMISSIONS = [
        // Dashboard / inicio
        'dashboard.view' => 'Ver dashboard',

        // Productos y catálogo interno
        'products.view' => 'Ver productos',
        'products.manage' => 'Crear/editar/eliminar productos',
        'categories.manage' => 'Gestionar categorías',
        'units.manage' => 'Gestionar unidades',
        'warehouses.manage' => 'Gestionar almacenes y ubicaciones',
        'transfers.manage' => 'Gestionar transferencias de stock',

        // Personas
        'customers.manage' => 'Gestionar clientes',
        'suppliers.manage' => 'Gestionar proveedores',

        // Ventas
        'sales.view' => 'Ver ventas',
        'sales.create' => 'Registrar venta nueva',
        'sales.complete' => 'Completar / cobrar venta',
        'sales.cancel' => 'Cancelar venta (restaura stock)',

        // Compras
        'purchases.view' => 'Ver compras',
        'purchases.manage' => 'Registrar y gestionar compras',

        // Finanzas
        'finance.view' => 'Ver resumen y transacciones financieras',
        'finance.accounting' => 'Plan de cuentas + libro diario + estados',
        'finance.payroll' => 'Planilla de sueldos',
        'finance.kardex' => 'Kardex valorizado',

        // Auditoría
        'audit.view' => 'Ver bitácora de auditoría',

        // Ajustes
        'settings.view' => 'Ver ajustes del sistema',
        'settings.edit-business' => 'Editar ajustes de negocio (empresa, moneda, impuestos, tienda, nómina)',
        'settings.edit-technical' => 'Editar ajustes técnicos (Telegram, IA, voz, webhook, API keys)',

        // Tienda en línea (Shop module)
        'shop.admin' => 'Gestionar reservas web del catálogo público',

        // Administración del sistema
        'users.manage' => 'Gestionar usuarios',
        'roles.manage' => 'Gestionar roles y permisos',
    ];

    /**
     * Permisos por rol base. Developer omite — el Gate::before le da todo.
     * @var array<string,string[]>
     */
    public const ROLE_PERMISSIONS = [
        'admin' => [
            'dashboard.view',
            'products.view', 'products.manage',
            'categories.manage', 'units.manage',
            'warehouses.manage', 'transfers.manage',
            'customers.manage', 'suppliers.manage',
            'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
            'purchases.view', 'purchases.manage',
            'finance.view', 'finance.accounting', 'finance.payroll', 'finance.kardex',
            'audit.view',
            'settings.view', 'settings.edit-business',
            'shop.admin',
            'users.manage',
            // NO incluye: settings.edit-technical, roles.manage
        ],
        'staff' => [
            'dashboard.view',
            'products.view',
            'customers.manage',
            'sales.view', 'sales.create',
        ],
    ];

    public function run(): void
    {
        // Limpia la cache de permisos antes de seedear.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Crea permisos (firstOrCreate idempotente).
        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Crea roles base (firstOrCreate idempotente).
        $developer = Role::firstOrCreate(['name' => 'developer', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        // 3. Asigna permisos a roles (syncPermissions = idempotente).
        // Developer recibe TODOS por consistencia, aunque Gate::before en
        // AppServiceProvider lo hace pasar como super-usuario igual.
        $developer->syncPermissions(Permission::all());

        $admin->syncPermissions(self::ROLE_PERMISSIONS['admin']);
        $staff->syncPermissions(self::ROLE_PERMISSIONS['staff']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
