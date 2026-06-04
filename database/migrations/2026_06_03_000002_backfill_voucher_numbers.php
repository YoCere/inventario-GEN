<?php

use App\Support\VoucherBackfiller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        VoucherBackfiller::run();
    }

    public function down(): void
    {
        // Backfill de datos: no se revierte.
    }
};
