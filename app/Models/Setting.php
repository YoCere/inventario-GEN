<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Keys cuyo valor se almacena cifrado en DB.
     *
     * Su valor descifrado NUNCA va al cache compartido (ver get()).
     */
    private const ENCRYPTED_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
        'siat_api_token',
        'siat_credential',
        'falmus_api_key',
    ];

    /**
     * Store de memoria del proceso, el unico lugar donde se guarda un secreto ya
     * descifrado: muere con la peticion y no se escribe en ningun disco ni tabla.
     *
     * POR QUE no `Cache::rememberForever()` para estas claves: CACHE_STORE es
     * `database`, asi que el valor descifrado quedaba en la tabla `cache`, en texto
     * plano y para siempre. Cifrar `settings.value` no servia contra nadie que
     * pudiera leer la base —y el volcado de la base viaja dentro del zip del
     * respaldo—. El coste de sacarlas del cache compartido es una consulta por
     * peticion y por clave: son seis claves y se leen pocas veces.
     */
    private static function memoria(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('array');
    }

    /**
     * Get a setting value by key.
     * Sensitive keys are decrypted transparently.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $esSecreto = in_array($key, self::ENCRYPTED_KEYS, true);

        // Misma lectura de siempre; lo unico que cambia es DONDE se memoriza.
        // Los ajustes normales son muchos y se leen todo el tiempo: siguen en el
        // cache compartido. Los secretos, solo en memoria del proceso.
        $store = $esSecreto ? self::memoria() : Cache::store();

        return $store->rememberForever("settings.{$key}", function () use ($key, $default, $esSecreto) {
            $setting = self::find($key);
            if (! $setting) {
                return $default;
            }

            $value = $setting->value;

            if ($esSecreto && $value !== null) {
                $value = self::safeDecrypt($value);
            }

            return $value ?? $default;
        });
    }

    /**
     * Set a setting value by key.
     * Sensitive keys are encrypted before saving.
     */
    public static function set(string $key, ?string $value): void
    {
        $toStore = $value;

        if ($value !== null && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $toStore = Crypt::encryptString($value);
        }

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $toStore]
        );

        self::forget($key);
    }

    /**
     * Olvida un ajuste en los DOS lados: el cache compartido y la memoria del proceso.
     *
     * Hace falta que sea publico por los procesos largos: el bot de Telegram escucha
     * durante horas y relee su token para tomarlo sin redeploy
     * (TelegramPollingCommand::botConfigurado). Como los secretos ya no viven en el
     * cache compartido, un `Cache::forget()` solo no los suelta.
     *
     * Se olvida en ambos lados a proposito, no solo en el que corresponde: en una
     * instancia ya desplegada puede haber quedado el valor descifrado en la tabla
     * `cache`, escrito por el codigo anterior, y asi se limpia al primer guardado.
     */
    public static function forget(string $key): void
    {
        Cache::forget("settings.{$key}");
        self::memoria()->forget("settings.{$key}");
    }

    /**
     * Intenta descifrar. Si el valor no está cifrado (legacy plaintext),
     * retorna el valor original en lugar de lanzar excepción.
     */
    private static function safeDecrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}
