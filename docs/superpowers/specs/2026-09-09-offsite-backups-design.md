# Off-Site Backups — Diseño

**Fecha:** 2026-09-09
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Objetivo

Sacar una copia **cifrada** de los backups **fuera del VPS** en cada corrida diaria, para sobrevivir la
pérdida total del disco/servidor (hoy los backups viven **solo local** en el mismo VPS — único punto de
falla; ya hubo un incidente de DB borrada). Cumplir 3-2-1. Y que un fallo del upload off-site **avise**
(hoy las notificaciones de spatie están efectivamente apagadas → falla en silencio).

Síntesis del mejor de 3 planes evaluados (ganó el plan C, security-first, 97/100; se adopta de B la
estrategia de retención más simple). Provider: **Backblaze B2** (S3-compatible; llaves de aplicación con
capabilities finas y scoping por bucket; ~$0/mes a este volumen).

## Estado actual (con file:line — verificado contra vendor)

- `config/backup.php:96-98` — `destination.disks => ['backups']`, **solo local**.
- `config/backup.php:110` — `password => env('BACKUP_ARCHIVE_PASSWORD')`, **sin setear** → zips sin cifrar.
- `config/backup.php:139-144` — las 6 clases de notificación mapeadas a `[]`. **`BaseNotification::via()`
  (`vendor/spatie/laravel-backup/src/Notifications/BaseNotification.php:14-19`) devuelve exactamente ese
  array** → `[]` = **cero canales = la notificación nunca se despacha**. Falla silenciosa actual.
- `config/backup.php:192-201` — `monitor_backups` solo vigila el disco `backups`.
- **No existe `destination.continue_on_failure`** en el config → default vendor `false`
  (`src/Config/DestinationConfig.php:35`). En `src/Tasks/Backup/BackupJob.php:339-381`, con `false` un
  fallo de escritura en **cualquier** disco `throw`ea y aborta la corrida (salta los discos restantes), y
  el evento per-disk `BackupHasFailed` (que lleva `diskName`) **solo se emite si es `true`**. → agregar un
  2º disco sin este flag hace que un corte off-site rompa también el backup local.
- `config/filesystems.php:33-61` — todos los discos ponen `'throw' => false`. Con `throw => false`, un
  `PutObject` fallido **devuelve false en vez de lanzar** → spatie no ve el fallo → segunda vía de falla
  silenciosa. El disco offsite **debe** setear `throw => true`.
- **`backup:monitor` no está scheduleado** en `routes/console.php` (solo `backup:run` 2am + `backup:clean`
  3am, gateados por `Setting::get('backup_schedule_enabled')`). → los health checks son config muerta hasta
  agendarlo.
- **`league/flysystem-aws-s3-v3` NO instalado** (ni `aws/aws-sdk-php` como dep real) → cualquier disco
  `driver: s3` necesita `composer require league/flysystem-aws-s3-v3`.
- `.env.example:50` — `MAIL_MAILER=log` → el mail no está cableado a un provider real. **Telegram** es el
  canal operativo vivo (low-stock, reminders, ciclo fiscal). → alertas por Telegram, no mail.
- **No hay `queue:work` en prod** (`docker/supervisord.conf`: solo php-fpm, nginx, scheduler). Confirmado
  por `DispatchRemindersCommand` que usa `dispatchSync` a propósito. → la alerta **debe** usar
  `SendTelegramMessage::dispatchSync`, NO `dispatch` (un job encolado nunca se entrega).
- Patrón de alerta ya existente: `app/Jobs/SendTelegramMessage` + `app/Services/Messaging/TelegramService`
  + `Setting::get('telegram_admin_chat_id')` / `telegram_enabled` (ver `app/Listeners/NotifyLowStock.php`).
- `app/Livewire/Settings/BackupManager.php` — UI (dev-only) lista/descarga solo del disco `backups` local.
  No se toca; ver copias off-site queda como follow-up.
- Dump DB ~409K, zip ~9MB (incluye 1368 archivos de la app). Deploy Docker/Coolify, **sin `.env`** (env por
  UI de Coolify), build corre `composer install`.

## Requisitos

| # | Requisito |
|---|-----------|
| **R1** | **Dependencia:** `composer require league/flysystem-aws-s3-v3` (arrastra aws-sdk-php). Commit de `composer.json` + `composer.lock`. Llega a prod por redeploy normal de Coolify. |
| **R2** | **Disco off-site** en `config/filesystems.php` (dedicado, namespace propio `B2_*`, NO reusar el stub `s3` genérico): `driver=s3`, `use_path_style_endpoint=true`, **`throw => true`** (crítico), `report => false`. Claves por env de Coolify. |
| **R3** | **Destino multi-disk** en `config/backup.php`: `destination.disks => ['backups','backups_offsite']` **y** `destination.continue_on_failure => true` (para que un corte off-site no rompa el local, y para que el evento per-disk con `diskName` se emita). |
| **R4** | **Cifrado:** setear `BACKUP_ARCHIVE_PASSWORD` en Coolify (el config ya lo lee). Cifra el zip **una vez antes** de tocar cualquier disco (`vendor/.../Listeners/EncryptBackupArchive.php` corre en `BackupZipWasCreated`, antes de `copyToBackupDestinations`). Custodia del password: **fuera del VPS** — password manager del equipo, compartido con ≥2 personas (bus-factor), NO guardado dentro del bucket que protege. Coolify = copia operativa; password manager = copia durable para DR. |
| **R5** | **Alerta de fallo por Telegram:** nueva clase `app/Notifications/Channels/BackupTelegramChannel` (canal de notificación de Laravel referenciado por FQCN — Laravel acepta un class-string en `via()`, no requiere `Notification::extend`). En `send($notifiable, $notification)`: si `Setting::get('telegram_enabled')!=='1'` o sin `telegram_admin_chat_id` → log y return; construir el texto desde `$notification->toMail()` (reusa el detalle de spatie: disco, excepción, tamaño); enviar con **`SendTelegramMessage::dispatchSync($chatId, $text)`**. En `config/backup.php` mapear a `[BackupTelegramChannel::class]` **solo** las 3 clases de fallo: `BackupHasFailedNotification`, `UnhealthyBackupWasFoundNotification`, `CleanupHasFailedNotification`. Las 3 de éxito quedan en `[]` (sin ruido). |
| **R6** | **Monitor cubre off-site:** `monitor_backups[0].disks => ['backups','backups_offsite']` (spatie evalúa cada disco independiente; una copia off-site vieja/faltante dispara `UnhealthyBackupWasFound` con su `diskName`). **Y** agendar el comando que hoy falta en `routes/console.php`: `backup:monitor` daily ~03:15, con el mismo gate `backup_schedule_enabled` + `withoutOverlapping()->onOneServer()`. |
| **R7** | **Retención off-site (simple, sin split de config):** la llave B2 **incluye `deleteFiles`** (scoped a un bucket + prefijo `inventory-backups/`), así el `backup:clean` normal corre contra ambos discos sin cambios ni falsos-fallos diarios. La protección anti-ransomware la da **B2: versioning ON + Lifecycle Rule** (ocultar versiones previas, purgar a los 90d) — un `DeleteObject` (de spatie, de un bug, o de una llave comprometida) solo **oculta**, recuperable 90d. El cap local de 512MB (`config/backup.php:221,257`) **no se toca**. |

## Arquitectura

Todo aditivo, spatie-nativo (multi-disk destination), sin sidecar (rclone/restic descartado: necesitaría
binario en la imagen Docker + cron aparte, y duplicaría cifrado/retención que spatie ya hace). Piezas:

1. **Dependencia + disco** (R1, R2) — el driver S3 y un disco `backups_offsite` apuntando a B2.
2. **Destino + cifrado** (R3, R4) — spatie escribe el zip cifrado a local **y** off-site cada corrida;
   `continue_on_failure=true` mantiene el local a salvo si el off-site falla.
3. **Observabilidad** (R5, R6) — canal Telegram real (`dispatchSync`) para las 3 notificaciones de fallo +
   `backup:monitor` agendado cubriendo ambos discos. Cierra las vías de falla silenciosa
   (`throw=>true`, notifs≠`[]`, monitor agendado, `dispatchSync`).
4. **Retención** (R7) — cleanup nativo en ambos discos + backstop de versioning/lifecycle en B2.

**Invariante clave:** el disco local `backups` y su schedule/cleanup **no cambian de comportamiento** —
solo se **extiende** con un segundo destino. Rollback = sacar `backups_offsite` de los dos arrays.

## Manejo de errores / seguridad

- **Sin falla silenciosa:** `throw=>true` (el fallo lanza) + `continue_on_failure=true` (se captura, emite
  `BackupHasFailed` con `diskName`, sigue con los otros discos) + notifs mapeadas a un canal real +
  `backup:monitor` agendado. Las 4 vías silenciosas del estado actual quedan cerradas.
- **Alerta entregable sin worker:** `dispatchSync` (no hay `queue:work` en prod).
- **Least-privilege:** llave B2 scoped a 1 bucket + prefijo, sin caps de admin. El poder de borrar está
  acotado por versioning+lifecycle (borrar = ocultar, recuperable 90d).
- **Dato cifrado al salir del VPS:** `BACKUP_ARCHIVE_PASSWORD`. El zip es AES per-file; para restaurar usar
  `7z` (algunos `unzip` no soportan AES). Verificar en el drill qué cifra realmente `encryption=>'default'`
  (AES-256 vs ZipCrypto legacy según build de libzip).
- **Custodia del password:** fuera del VPS (password manager, ≥2 personas). Si el VPS y Coolify se pierden
  juntos, el off-site cifrado es un ladrillo sin este password → es el único secreto que NO puede vivir solo
  en la máquina respaldada.

## Testing

- **Smoke de credenciales:** `php artisan tinker` → `Storage::disk('backups_offsite')->put('smoke.txt','ok')`
  + `->get('smoke.txt')` → confirma endpoint/bucket/llave antes de confiar en el job.
- **Corrida real:** `php artisan backup:run` (o el botón de `BackupManager`) → exit 0; el zip aparece en
  ambos discos (`Storage::disk('backups')->allFiles()` y `->disk('backups_offsite')->allFiles()`), mismo tamaño.
- **Cifrado:** `7z l` / `7z x -p<password>` sobre la copia off-site → falla sin password, extrae con él;
  el `.sql` adentro no vacío.
- **Alerta (negativo):** setear una `B2_APPLICATION_KEY` errónea, `backup:run` → (a) el local igual queda
  fresco (prueba `continue_on_failure`), (b) llega un Telegram nombrando `backups_offsite` y el error.
  Revertir la llave.
- **Monitor:** `php artisan backup:monitor` → evalúa ambos discos sin error (sano); con una copia off-site
  faltante → dispara `UnhealthyBackupWasFound` a Telegram.
- **Drill de restore off-site (trimestral):** descargar el zip (B2 CLI/`Storage`), `7z x -p`, importar el
  `.sql` a una DB scratch, verificar counts. Es el test que valida la cadena entera independiente del VPS.
- **No-regresión:** el disco local `backups`, su schedule y su cap de 512MB intactos.

## Runbook de restore (pérdida total del VPS)

1. Password + creds B2 desde el **password manager** (NO desde el Coolify muerto).
2. Descargar el zip más nuevo del bucket (B2 CLI o `aws s3 --endpoint-url ...`).
3. `7z x -p'<BACKUP_ARCHIVE_PASSWORD>' backup.zip -o./restore`.
4. `mysql -h <host> -u <user> -p <db> < ./restore/db-dumps/mysql-*.sql` (cliente MariaDB → `--skip-ssl`).
5. La app se redespliega **desde git** (el zip excluye `.git`; los archivos son para referencia/uploads).
   Re-setear env vars (incl. `BACKUP_ARCHIVE_PASSWORD`, `B2_*`, DB) en el nuevo Coolify.

## Rollback

Sacar `'backups_offsite'` de `destination.disks` y de `monitor_backups[0].disks`, redeploy. Cero riesgo de
datos: solo deja de escribir/monitorear off-site; el bucket B2 y su historia quedan intactos y re-adjuntables.
El disco/schedule/cleanup local nunca se modificaron. El disco `backups_offsite`, el canal y el schedule del
monitor pueden quedar dormidos sin daño.

## Fuera de alcance

- Ver/descargar copias off-site desde la UI `BackupManager` (hoy solo local) — follow-up.
- Object Lock (inmutabilidad compliance) en B2 — versioning+lifecycle alcanza para el modelo de amenaza
  actual; upgrade barato después si se quiere.
- Heartbeat de éxito (`BackupWasSuccessfulNotification` a Telegram) — un flip de una línea si se quiere,
  a costa de ruido diario.
- Segundo canal de alerta (SMTP real) — Telegram es SPOF de observabilidad; cerrar cuando haya mail real.
