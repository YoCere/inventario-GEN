<?php

use Database\Seeders\DefaultLandingTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DefaultLandingTemplateSeeder())->run();
    }

    public function down(): void
    {
        // No-op: no borramos contenido del usuario en rollback.
    }
};
