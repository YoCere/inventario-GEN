<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Siembra business_timezone en cada despliegue. Sin este valor, el agente IA y
 * los recordatorios caen a la zona de la app (UTC) y resuelven/muestran las
 * fechas desfasadas respecto a la hora local del negocio.
 *
 * Idempotente: solo escribe si no existe; respeta el valor que el usuario haya
 * configurado en Ajustes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migración de datos (no esquema): se salta en testing para que la suite
        // sea determinista (los tests fijan su propia zona explícitamente).
        if (app()->environment('testing')) {
            return;
        }

        $exists = Setting::query()->where('key', 'business_timezone')->exists();

        if (! $exists) {
            Setting::set('business_timezone', 'America/La_Paz');
        }
    }

    public function down(): void
    {
        // No-op: no borrar configuración del negocio en un rollback.
    }
};
