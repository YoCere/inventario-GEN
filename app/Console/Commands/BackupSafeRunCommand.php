<?php

namespace App\Console\Commands;

use App\Notifications\BackupBlockedNotification;
use App\Notifications\Channels\BackupTelegramChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envoltorio de `backup:run` que se niega a correr si el zip no va a salir cifrado.
 *
 * El cifrado del zip depende de BACKUP_ARCHIVE_PASSWORD (config/backup.php
 * 'password'). Si falta, spatie genera el zip SIN cifrar y lo sube igual al bucket
 * del tercero, sin avisar nada. Mejor no tener respaldo que tener uno en claro en
 * manos ajenas: aca se corta antes de dumpear la base.
 *
 * POR QUE un comando envoltorio y no el ->when() de routes/console.php:
 *  - `when()` solo decide si el evento se despacha, y lo hace callado: el respaldo
 *    desapareceria sin que nadie se enterara, que es exactamente el problema que
 *    estamos arreglando.
 *  - `when()` se evalua en CADA tick del planificador; avisar desde ahi seria un
 *    Telegram por minuto.
 *  - El comando corre una vez por intento, devuelve codigo != 0 (queda en el log del
 *    planificador) y es ademas lo que escribe una persona a mano en el contenedor
 *    (ver docs/seguridad/recuperacion-respaldos.md).
 *
 * Limite conocido: un `php artisan backup:run` tecleado a mano sigue salteando la
 * guardia. La documentacion de operacion apunta a este comando; cerrar ese camino
 * del todo exigiria meterse en el ciclo de vida de spatie.
 */
class BackupSafeRunCommand extends Command
{
    protected $signature = 'backup:safe-run {--only-db : Solo la base de datos} {--only-files : Solo los archivos}';

    protected $description = 'Corre el respaldo solo si va a salir cifrado (exige BACKUP_ARCHIVE_PASSWORD con off-site activo)';

    /**
     * Motivo por el que NO se debe respaldar, o null si se puede.
     *
     * Solo exigimos cifrado cuando el zip sale del servidor: con el disco local a
     * secas no hay un tercero en el medio y pedirlo dejaria sin respaldo a las
     * instalaciones que todavia no provisionaron B2.
     */
    public static function motivoDeBloqueo(): ?string
    {
        if (! self::offsiteConfigurado()) {
            return null;
        }

        if (trim((string) config('backup.backup.password')) !== '') {
            return null;
        }

        return 'El respaldo off-site esta activo pero BACKUP_ARCHIVE_PASSWORD esta vacia: '
            . 'el zip viajaria SIN cifrar al bucket, con el volcado de la base adentro.';
    }

    /**
     * Hay off-site de verdad cuando el disco esta entre los destinos Y tiene bucket.
     * El disco esta declarado en todas las instalaciones; lo que lo activa por
     * cliente son las variables de B2 (ver ops/README-provisioning.md).
     */
    private static function offsiteConfigurado(): bool
    {
        $discos = (array) config('backup.backup.destination.disks', []);

        if (! in_array('backups_offsite', $discos, true)) {
            return false;
        }

        return trim((string) config('filesystems.disks.backups_offsite.bucket')) !== '';
    }

    public function handle(): int
    {
        $motivo = self::motivoDeBloqueo();

        if ($motivo !== null) {
            $this->error($motivo);

            // Log siempre, aunque Telegram este apagado: tiene que quedar rastro.
            Log::error('Respaldo cancelado por falta de cifrado', ['motivo' => $motivo]);

            (new BackupTelegramChannel())->send(null, new BackupBlockedNotification($motivo));

            return self::FAILURE;
        }

        $opciones = [];

        if ($this->option('only-db')) {
            $opciones['--only-db'] = true;
        }

        if ($this->option('only-files')) {
            $opciones['--only-files'] = true;
        }

        return $this->call('backup:run', $opciones);
    }
}
