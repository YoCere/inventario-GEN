<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpia los secretos que el codigo anterior dejo EN CLARO en la tabla `cache`.
 *
 * POR QUE hace falta: hasta este cambio, Setting::get() guardaba el valor ya
 * descifrado con Cache::rememberForever() y CACHE_STORE es `database`. O sea que
 * la API key de Anthropic, la de OpenAI, el token del bot, el secreto del webhook
 * y las credenciales SIAT quedaron escritas en texto plano y sin vencimiento en la
 * tabla `cache`. Arreglar Setting::get() evita que se escriban de nuevo, pero NO
 * borra las que ya estan: siguen ahi, y viajan en el volcado de la base que va
 * dentro del zip del respaldo. Hay que sacarlas a mano, una sola vez.
 *
 * Es ADITIVA en el sentido que importa: no toca ni una fila de datos de negocio.
 * Solo borra entradas de cache, que por definicion se regeneran solas (los
 * secretos se vuelven a leer de `settings`, que es la fuente de verdad y sigue
 * cifrada). No hay down(): volver a meter un secreto en claro en la base no es
 * algo que quisieramos poder deshacer.
 */
return new class extends Migration
{
    /**
     * Las mismas claves que Setting::ENCRYPTED_KEYS (es privada ahi a proposito;
     * duplicarlas aca mantiene la migracion congelada en el tiempo, como debe ser:
     * si mañana se agrega una clave cifrada, esta migracion no tiene que cambiar).
     */
    private const SECRET_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
        'siat_api_token',
        'siat_credential',
        'falmus_api_key',
    ];

    public function up(): void
    {
        foreach (self::SECRET_KEYS as $key) {
            // Camino normal: respeta el prefijo y el store configurados.
            Cache::forget("settings.{$key}");
        }

        // Red de seguridad: si el prefijo del cache cambio alguna vez (CACHE_PREFIX
        // deriva de APP_NAME), quedan filas viejas que Cache::forget ya no alcanza.
        // Se buscan por sufijo y se borran igual. El LIKE toca solo la tabla `cache`.
        if (! Schema::hasTable('cache')) {
            return;
        }

        foreach (self::SECRET_KEYS as $key) {
            DB::table('cache')->where('key', 'like', "%settings.{$key}")->delete();
        }
    }
};
