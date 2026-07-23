<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_cufd', function (Blueprint $table) {
            $table->id();
            $table->string('value');
            $table->unsignedInteger('sucursal')->default(0);
            $table->unsignedInteger('punto_venta')->default(0);
            $table->string('codigo_control')->nullable();
            $table->string('direccion')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['sucursal', 'punto_venta', 'expires_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_cufd'); }
};
