<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op. Las cuentas de activo fijo/depreciación (1.2.02, 1.2.03, 1.2.04,
 * 6.4, 6.5, 6.6) se siembran en ChartOfAccountSeeder con su jerarquía correcta
 * (parent_id wired). Antes esta migración las insertaba a tiempo de migrate
 * (antes de los seeders), dejándolas huérfanas (parent_id null) y alterando el
 * orden de ids del plan de cuentas en tests. Para reparar entornos que ya la
 * aplicaron, correr: php artisan db:seed --class=Database\\Seeders\\ChartOfAccountSeeder
 */
return new class extends Migration {
    public function up(): void
    {
        // no-op (ver ChartOfAccountSeeder)
    }

    public function down(): void
    {
        // no-op
    }
};
