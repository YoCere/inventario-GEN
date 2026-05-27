<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memoria persistente del bot.
 *
 * Guarda lo que la IA aprende con el uso:
 *  - vision: descripción de imagen → producto confirmado
 *  - text_search: alias/sinónimos → producto  (futuro)
 *
 * La IA consulta esta tabla ANTES de llamar a la API externa, reduciendo
 * latencia y costo. Cada acierto incrementa 'hits' para medir utilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_knowledge', function (Blueprint $table) {
            $table->id();

            // Tipo de aprendizaje: 'vision', 'text_search', etc.
            $table->string('type', 32)->index();

            // Clave de búsqueda normalizada (p. ej. "llavero peluche labubu pop")
            $table->string('key')->index();

            // Producto aprendido (nullable por si en el futuro se guardan notas sin producto)
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->onDelete('cascade');

            // Cómo se aprendió: 'auto' (IA lo infirió) o 'user_confirmed' (usuario lo eligió/vendió)
            $table->string('source', 32)->default('auto');

            // Cuántas veces se usó este aprendizaje
            $table->unsignedInteger('hits')->default(0);

            // Datos adicionales opcionales (descripción completa original, etc.)
            $table->json('meta')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Una sola entrada por tipo + clave
            $table->unique(['type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_knowledge');
    }
};
