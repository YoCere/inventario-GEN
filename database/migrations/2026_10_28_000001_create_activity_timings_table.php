<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto tarda la gente en cargar un producto y en cobrar una venta.
 *
 * Tabla aparte y sin ninguna relación obligatoria hacia el producto o la venta:
 * medir no puede condicionar al negocio. Si esta tabla no existe, está bloqueada
 * o tiene un dato raro, la venta se guarda igual y el producto se crea igual
 * (ver App\Services\Metrics\TimingRecorder, que se come cualquier error).
 *
 * `user_id` queda en null si el usuario se borra: el tiempo medido sigue siendo
 * un dato válido del negocio aunque la persona ya no esté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_timings', function (Blueprint $table) {
            $table->id();

            // Qué se midió y por dónde entró. Los valores válidos los define
            // App\Support\Metrics\TimedActivity, que es la única lista.
            $table->string('activity', 32);
            $table->string('channel', 32);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Duración total en segundos. Segundos y no milisegundos porque lo
            // que se mide es tiempo de persona, no de servidor.
            $table->unsignedInteger('seconds');

            // Tiempo por paso del alta por el bot: {'nuevo:nombre': 12, ...}.
            // Null en los canales que no tienen pasos (la pantalla de cobro).
            $table->json('steps')->nullable();

            // Instantes técnicos (UTC), para auditar una medición puntual.
            $table->dateTime('started_at');
            $table->dateTime('finished_at');

            // Día del negocio (App\Support\BusinessTime): con la zona horaria
            // configurable, una venta de las 21:00 caería en el día siguiente si
            // se agrupara por created_at.
            $table->date('business_date');

            // Se midió pero no cuenta para los promedios: alguien dejó el
            // formulario abierto, o el reloj trajo un dato imposible. Se guarda
            // igual para poder decir cuántos abandonos hubo.
            $table->boolean('discarded')->default(false);

            $table->timestamps();

            // La pantalla de métricas siempre filtra por actividad + canal, y
            // casi siempre por rango de días del negocio.
            $table->index(['activity', 'channel', 'discarded']);
            $table->index('business_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_timings');
    }
};
