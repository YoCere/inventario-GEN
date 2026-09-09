# Off-Site Backups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enviar una copia cifrada de los backups a Backblaze B2 (off-site) en cada corrida diaria, con alerta por Telegram si falla, cerrando las vías de falla silenciosa del setup actual.

**Architecture:** spatie-nativo multi-disk destination. Se agrega un disco S3-compatible `backups_offsite` (B2), se activa cifrado + `continue_on_failure`, se cablea un canal de notificación Telegram real (`dispatchSync`, no hay `queue:work` en prod) para las 3 notificaciones de fallo, y se agenda `backup:monitor` (hoy falta) cubriendo ambos discos. El disco local `backups` no cambia de comportamiento — solo se extiende.

**Tech Stack:** Laravel 11, spatie/laravel-backup ^10.2, league/flysystem-aws-s3-v3 (nuevo), Backblaze B2 (S3 API), PHPUnit.

**Nota ops (fuera de código, requisito para que funcione en prod):** crear el bucket B2 + Application Key (scoped a 1 bucket + prefijo `inventory-backups/`, con `deleteFiles`), activar versioning + Lifecycle Rule (ocultar→purgar 90d), y setear en Coolify: `B2_KEY_ID`, `B2_APPLICATION_KEY`, `B2_REGION`, `B2_BUCKET`, `B2_ENDPOINT`, `BACKUP_ARCHIVE_PASSWORD` (guardar este último también en el password manager del equipo, ≥2 personas). Ver el spec.

---

### Task 1: Dependencia S3 + disco off-site + destino multi-disk

**Files:**
- Modify: `composer.json` / `composer.lock` (via `composer require`)
- Modify: `config/filesystems.php`
- Modify: `config/backup.php`
- Test: `tests/Feature/Backup/OffsiteBackupConfigTest.php`

- [ ] **Step 1: Instalar el driver S3**

Run: `composer require league/flysystem-aws-s3-v3`
Expected: agrega `league/flysystem-aws-s3-v3` (+ `aws/aws-sdk-php`) a `composer.json` require y actualiza `composer.lock`. Verificar: `composer show league/flysystem-aws-s3-v3` imprime la versión.

- [ ] **Step 2: Escribir el test que falla** — crear `tests/Feature/Backup/OffsiteBackupConfigTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use Tests\TestCase;

class OffsiteBackupConfigTest extends TestCase
{
    public function test_offsite_disk_is_a_backup_destination(): void
    {
        $this->assertContains('backups_offsite', config('backup.backup.destination.disks'));
        $this->assertContains('backups', config('backup.backup.destination.disks'));
    }

    public function test_continue_on_failure_is_true(): void
    {
        // Sin esto, un corte off-site aborta tambien el backup local (default vendor = false).
        $this->assertTrue(config('backup.backup.destination.continue_on_failure'));
    }

    public function test_offsite_disk_throws_on_write_failure(): void
    {
        // throw=>true es critico: con false, un PutObject fallido devuelve false y spatie
        // nunca ve el fallo -> falla silenciosa.
        $this->assertTrue(config('filesystems.disks.backups_offsite.throw'));
        $this->assertSame('s3', config('filesystems.disks.backups_offsite.driver'));
    }

    public function test_monitor_covers_offsite_disk(): void
    {
        $this->assertContains('backups_offsite', config('backup.monitor_backups.0.disks'));
    }
}
```

- [ ] **Step 3: Correr el test — debe FALLAR**

Run: `php artisan test --filter OffsiteBackupConfigTest`
Expected: FAIL (el disco `backups_offsite` no existe, `continue_on_failure` ausente, monitor sin offsite).

- [ ] **Step 4: Agregar el disco off-site en `config/filesystems.php`**

Insertar después del bloque `backups` (~línea 66), dentro de `'disks' => [ ... ]`:

```php
        'backups_offsite' => [
            'driver' => 's3',
            'key' => env('B2_KEY_ID'),
            'secret' => env('B2_APPLICATION_KEY'),
            'region' => env('B2_REGION', 'us-west-004'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'), // ej. https://s3.us-west-004.backblazeb2.com
            'use_path_style_endpoint' => true,
            'throw' => true,   // critico: que un PutObject fallido LANCE (no devuelva false y se trague)
            'report' => false,
        ],
```

- [ ] **Step 5: Multi-disk + continue_on_failure en `config/backup.php`**

Reemplazar el bloque `destination` (líneas ~86-99):

```php
        'destination' => [

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => '',

            /*
             * The disk names on which the backups will be stored.
             */
            'disks' => [
                'backups',
                'backups_offsite',
            ],

            /*
             * Si un disco falla, seguir con los demas en vez de abortar toda la corrida.
             * REQUERIDO: sin esto, un corte off-site rompe tambien el backup local, y el
             * evento per-disk BackupHasFailed (con diskName) no se emite. Default vendor: false.
             */
            'continue_on_failure' => true,
        ],
```

- [ ] **Step 6: Monitor cubre el disco off-site en `config/backup.php`**

En `monitor_backups` (línea ~195), cambiar `'disks' => ['backups']` por:

```php
            'disks' => ['backups', 'backups_offsite'],
```

- [ ] **Step 7: Correr el test — debe PASAR**

Run: `php artisan test --filter OffsiteBackupConfigTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/filesystems.php config/backup.php tests/Feature/Backup/OffsiteBackupConfigTest.php
git commit -m "feat(backup): disco off-site B2 como destino multi-disk (throw+continue_on_failure)"
```

---

### Task 2: Canal de alerta Telegram + cablear las notificaciones de fallo

**Files:**
- Create: `app/Notifications/Channels/BackupTelegramChannel.php`
- Modify: `config/backup.php` (bloque `notifications.notifications`)
- Test: `tests/Feature/Backup/BackupTelegramChannelTest.php`

Contexto: hoy las 6 notificaciones de spatie están mapeadas a `[]` → `BaseNotification::via()` devuelve ese array → **cero canales = nunca se despacha** (falla silenciosa). Mail no sirve (`MAIL_MAILER=log`). Telegram es el canal vivo. NO hay `queue:work` en prod → usar `dispatchSync`. Laravel acepta un class-string como canal en `via()`, así que se referencia la clase directo en el config (sin `Notification::extend`).

- [ ] **Step 1: Escribir el test que falla** — crear `tests/Feature/Backup/BackupTelegramChannelTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use App\Notifications\Channels\BackupTelegramChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BackupTelegramChannelTest extends TestCase
{
    private function fakeNotification(): Notification
    {
        return new class extends Notification {
            public function toMail(): MailMessage
            {
                return (new MailMessage)->error()->subject('Backup failed')->line('boom');
            }
        };
    }

    public function test_dispatches_sync_telegram_when_enabled(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '12345');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertDispatchedSync(
            SendTelegramMessage::class,
            fn (SendTelegramMessage $j) => $j->chatId === '12345' && str_contains($j->message, 'Backup failed')
        );
    }

    public function test_does_nothing_when_telegram_disabled(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '0');
        Setting::set('telegram_admin_chat_id', '12345');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertNotDispatched(SendTelegramMessage::class);
    }

    public function test_does_nothing_without_chat_id(): void
    {
        Bus::fake();
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '');

        (new BackupTelegramChannel())->send(null, $this->fakeNotification());

        Bus::assertNotDispatched(SendTelegramMessage::class);
    }
}
```

- [ ] **Step 2: Correr el test — debe FALLAR**

Run: `php artisan test --filter BackupTelegramChannelTest`
Expected: FAIL (`App\Notifications\Channels\BackupTelegramChannel` no existe).

- [ ] **Step 3: Crear el canal** `app/Notifications/Channels/BackupTelegramChannel.php`:

```php
<?php

namespace App\Notifications\Channels;

use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de notificacion de Laravel para las alertas de spatie/laravel-backup.
 * Referenciado por FQCN en config/backup.php notifications (Laravel acepta un
 * class-string como canal en via(); no requiere Notification::extend).
 *
 * Usa dispatchSync a proposito: NO hay queue:work en prod (docker/supervisord.conf),
 * asi que un job encolado nunca se entregaria — la alerta debe salir sincrona.
 */
class BackupTelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMail')) {
            return;
        }

        if (Setting::get('telegram_enabled') !== '1') {
            return;
        }

        $chatId = Setting::get('telegram_admin_chat_id');
        if (! $chatId) {
            Log::warning('Alerta de backup sin telegram_admin_chat_id configurado');
            return;
        }

        $mail = $notification->toMail();
        $lines = array_merge($mail->introLines ?? [], $mail->outroLines ?? []);
        $text = '🛑 <b>' . e($mail->subject ?? 'Backup') . "</b>\n\n" . e(implode("\n", $lines));

        // Telegram corta a 4096; dejamos margen (el trace de la excepcion puede ser largo).
        if (mb_strlen($text) > 3500) {
            $text = mb_substr($text, 0, 3500) . '…';
        }

        SendTelegramMessage::dispatchSync($chatId, $text);
    }
}
```

- [ ] **Step 4: Cablear las 3 notificaciones de fallo en `config/backup.php`**

En el bloque `notifications.notifications` (líneas ~139-144), mapear SOLO las 3 de fallo al canal (las 3 de éxito quedan en `[]` para no hacer ruido):

```php
        'notifications' => [
            \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class         => [\App\Notifications\Channels\BackupTelegramChannel::class],
            \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => [\App\Notifications\Channels\BackupTelegramChannel::class],
            \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class        => [\App\Notifications\Channels\BackupTelegramChannel::class],
            \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class     => [],
            \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class   => [],
            \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class    => [],
        ],
```

- [ ] **Step 5: Correr el test — debe PASAR**

Run: `php artisan test --filter BackupTelegramChannelTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Notifications/Channels/BackupTelegramChannel.php config/backup.php tests/Feature/Backup/BackupTelegramChannelTest.php
git commit -m "feat(backup): canal de alerta Telegram (dispatchSync) para fallos de backup"
```

---

### Task 3: Agendar `backup:monitor`

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/Backup/BackupMonitorScheduleTest.php`

Contexto: `backup:monitor` no está agendado en ningún lado → los health checks de `monitor_backups` son config muerta. Agendarlo (con el mismo gate `backup_schedule_enabled`) hace que una copia off-site vieja/faltante dispare `UnhealthyBackupWasFound` → Telegram (Task 2).

- [ ] **Step 1: Escribir el test que falla** — crear `tests/Feature/Backup/BackupMonitorScheduleTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupMonitorScheduleTest extends TestCase
{
    public function test_backup_monitor_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn ($c) => str_contains($c, 'backup:monitor'));

        $this->assertTrue($commands->isNotEmpty(), 'backup:monitor debe estar agendado');
    }
}
```

- [ ] **Step 2: Correr el test — debe FALLAR**

Run: `php artisan test --filter BackupMonitorScheduleTest`
Expected: FAIL (no hay `backup:monitor` agendado).

- [ ] **Step 3: Agendar el comando en `routes/console.php`**

Agregar después del bloque `backup:clean` (línea ~31):

```php
// Chequea la salud de los backups (edad/tamano) en ambos discos y alerta por Telegram
// si alguno esta viejo/faltante. Sin esto los health checks de monitor_backups son config muerta.
Schedule::command('backup:monitor')
    ->dailyAt('03:15')
    ->when(fn () => \App\Models\Setting::get('backup_schedule_enabled', '1') === '1')
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 4: Correr el test — debe PASAR**

Run: `php artisan test --filter BackupMonitorScheduleTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/console.php tests/Feature/Backup/BackupMonitorScheduleTest.php
git commit -m "feat(backup): agendar backup:monitor diario (salud de ambos discos)"
```

---

## Verificación final (tras las 3 tasks)

- [ ] Suite completa verde: `php artisan test`
- [ ] Review final del branch + `superpowers:finishing-a-development-branch` (merge a main + push).

## Checklist de ops en prod (post-merge, requiere acción del usuario — NO es código)

1. **Backblaze B2:** crear cuenta/bucket `inventory-app-backups-offsite`; Application Key scoped a ese bucket + prefijo `inventory-backups/`, capabilities `listFiles,readFiles,writeFiles,deleteFiles`.
2. **B2 versioning + Lifecycle Rule:** ocultar versiones previas y purgar a los 90 días (retención/backstop anti-borrado).
3. **Coolify env vars:** `B2_KEY_ID`, `B2_APPLICATION_KEY`, `B2_REGION`, `B2_BUCKET`, `B2_ENDPOINT`, `BACKUP_ARCHIVE_PASSWORD` (32+ chars random). Guardar `BACKUP_ARCHIVE_PASSWORD` también en el password manager del equipo (≥2 personas) — si el VPS muere, es lo único que descifra el off-site.
4. **Redeploy** (para que entre la dependencia nueva).
5. **Smoke:** `php artisan tinker` → `Storage::disk('backups_offsite')->put('smoke.txt','ok')` + `->get('smoke.txt')`; después `php artisan backup:run` → el zip aparece en ambos discos.
6. **Test de alerta:** poner una `B2_APPLICATION_KEY` errónea, `backup:run` → el local igual queda fresco + llega Telegram nombrando `backups_offsite`. Revertir.
7. **Drill de restore off-site:** bajar el zip, `7z x -p<password>`, importar el `.sql` a DB scratch, verificar counts.
