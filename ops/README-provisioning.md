# Provisioning de backups off-site por cliente (SaaS)

El sistema se vende **una instancia por cliente** (cada emprendimiento su propio deploy Coolify + DB).
Cada instancia ya trae el código de backup off-site (main `a4cabe4`); falta, **por cliente**, crear su
bucket/llave en Backblaze B2, cargar 6 env vars en su Coolify y guardar su password. Esto lo automatiza
`ops/provision-client-backup.sh` para que activar un cliente tome ~2 minutos.

## Modelo

- **1 cuenta B2 del vendedor** (vos gestionás los backups de todos los clientes).
- **Por cliente:** 1 bucket privado + 1 Application Key scoped a ese bucket + 1 password de cifrado.
  Aislamiento real: una llave filtrada de un cliente **no** puede tocar los backups de otro.
- Retención/anti-borrado: **B2 versioning + Lifecycle Rule** (un borrado solo oculta; se purga a los 90d).

## Setup una-sola-vez (vendedor)

1. Crear cuenta en Backblaze B2.
2. Crear una **Master Application Key** (o una key con permisos de crear buckets/keys).
3. Instalar el CLI: `pip install b2` (o `brew install b2-tools`).
4. Autorizar: `b2 authorize-account <masterKeyId> <masterKey>`.
   - Nota versión: los comandos del script son **b2 CLI v3**. Si tenés v4, los subcomandos cambian a
     `b2 bucket create/update`, `b2 key create`, `b2 account get` — ajustar el script.

## Provisionar un cliente (por cada nuevo emprendimiento)

```bash
./ops/provision-client-backup.sh <slug>     # ej: ./ops/provision-client-backup.sh acme
```

Genera `ops/provisioned/<slug>.env` (gitignored, permisos 600) con:

```
B2_BUCKET=inv-backups-acme
B2_KEY_ID=...
B2_APPLICATION_KEY=...
B2_REGION=us-west-004
B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com
BACKUP_ARCHIVE_PASSWORD=...
```

Retención distinta: `RETENTION_DAYS=180 ./ops/provision-client-backup.sh acme`.

## Cargar en Coolify + activar (por cliente)

1. En Coolify → app del cliente → **Environment Variables** → pegar las 6 vars del `.env`.
2. **Guardar `BACKUP_ARCHIVE_PASSWORD` en el password manager** del equipo (≥2 personas). Es lo único que
   descifra el off-site si el VPS del cliente muere — no puede vivir solo en ese VPS.
3. **Redeploy** del app (para que tome las vars; el código ya está en `main`).
4. Verificar (consola del contenedor del cliente):
   ```bash
   php artisan tinker --execute="\Storage::disk('backups_offsite')->put('smoke.txt','ok'); echo \Storage::disk('backups_offsite')->get('smoke.txt');"
   php artisan backup:run
   ```
   El zip debe aparecer en el bucket B2 del cliente. Alerta: poné una `B2_APPLICATION_KEY` errónea, corré
   `backup:run` → el backup local igual queda + llega un Telegram nombrando `backups_offsite`. Revertir.
5. Borrar el `.env` local una vez cargado: `rm ops/provisioned/<slug>.env` (ya está en el gestor + Coolify).

## Desprovisionar un cliente (baja)

```bash
b2 delete-key <keyID>                       # revoca acceso
# vaciar y borrar el bucket SOLO si ya no se necesitan sus backups:
# b2 delete-bucket inv-backups-<slug>
```

## Escala / próximo paso

Esto cubre la **rebanada de backups** del onboarding de un cliente. El provisioning completo por-tenant
incluye además: crear el app+DB en Coolify, dominio, seed del admin + rol, config de Telegram, etc.
Candidatos a automatizar encima de esto:
- **Push a Coolify por API** (setear las env vars + trigger redeploy sin pegar a mano) — Coolify tiene REST
  API; se agrega un `--push-coolify` al script con `COOLIFY_TOKEN` + UUID del app.
- **Un `provision-client.sh` paraguas** que orqueste todos los pasos por cliente.

Ver `docs/superpowers/plans/2026-09-09-offsite-backups.md` para el detalle del diseño de backups.

---

# Alta de un cliente nuevo (instancia desde cero)

**Una sola rama `main` para todos los clientes.** Nunca una rama por cliente: cada arreglo habría que
aplicarlo en cada rama y con dos o tres clientes se vuelve inmanejable. Lo propio de cada negocio es
**dato o configuración** (Ajustes, productos, roles), nunca código. Si un cliente necesita algo que otros
no, va como interruptor en Ajustes o como permiso de rol.

Tiempo estimado: ~20 minutos la primera vez, ~10 después.

## 0. Antes de empezar

- Dominio o subdominio apuntando al VPS (ej. `talabarteria.midominio.com`).
- Acceso a Coolify.
- Cuenta Backblaze B2 con el CLI autorizado (ver la primera parte de este documento).
- Un gestor de contraseñas para guardar: `APP_KEY`, `BACKUP_ARCHIVE_PASSWORD` y la clave de la dueña.

## 1. Crear la aplicación y la base en Coolify

1. **Nuevo recurso → Database → MySQL.** Anotar host (nombre del contenedor), base, usuario y contraseña.
2. **Nuevo recurso → Application → Docker (Dockerfile)**, repo del sistema, rama `main`, puerto `8080`.
3. Asignar el dominio con HTTPS.

## 2. Volumen persistente — SIN ESTO SE PIERDEN LAS IMÁGENES

Las fotos de productos, el logo del negocio y las imágenes de la tienda se guardan en disco, dentro del
contenedor. **Cada redeploy reconstruye el contenedor desde cero**: lo que esté dentro desaparece.

En Coolify → la app → **Storages / Volumes**, montar un volumen persistente en:

```
/var/www/storage/app
```

Eso cubre imágenes (`app/public`), archivos privados (`app/private`) y los respaldos locales
(`app/backups`). `docker/start.sh` recrea esas carpetas y el enlace `public/storage` en cada arranque,
así que el volumen puede empezar vacío.

**No montes `/var/www/storage` entero:** adentro viven `framework/views`, `framework/cache` y `logs`, que
el contenedor necesita creados; montarlos en un volumen vacío rompe el arranque.

**Verificación obligatoria (hacela antes de entregar):** subí una foto a un producto, hacé un redeploy en
Coolify y volvé a mirar el producto. Si la foto sigue, el volumen está bien montado. Si desapareció,
revisá el punto de montaje antes de seguir.

> Revisá también las instancias YA desplegadas: si alguna no tiene este volumen, sus imágenes se están
> perdiendo en cada redeploy aunque la base de datos esté intacta.

## 3. Variables de entorno (Coolify → Environment Variables)

| Variable | Valor | Nota |
|---|---|---|
| `APP_NAME` | Nombre del negocio | Sale en el título del navegador |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | En `true` se muestran datos internos ante un error |
| `APP_URL` | `https://dominio-del-cliente` | Lo usan el enlace de la tienda y el QR |
| `APP_KEY` | generar una vez | **Ver la advertencia de abajo** |
| `APP_LOCALE` | `es` | |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | de la base creada en el paso 1 | |
| `PHP_MEMORY_LIMIT` | `512M` | Solo si tu plantilla de Coolify lo usa: la imagen ya fija 2G en `Dockerfile`. Verificá dentro del contenedor con `php -r 'echo ini_get("memory_limit");'`; con 128M las fotos de celular revientan al procesarse |
| `B2_KEY_ID`, `B2_APPLICATION_KEY`, `B2_REGION`, `B2_BUCKET`, `B2_ENDPOINT`, `BACKUP_ARCHIVE_PASSWORD` | los genera `provision-client-backup.sh` | Respaldos fuera del servidor |

Generar la `APP_KEY`: `php artisan key:generate --show` (en tu máquina) y pegar el valor completo,
incluido el prefijo `base64:`.

> **La `APP_KEY` no se cambia nunca más.** Con ella se cifran en la base las claves que el cliente carga
> desde Ajustes (token de Telegram, API keys de IA). Si la cambiás, esas claves quedan ilegibles y hay que
> volver a cargarlas a mano. Guardala en el gestor de contraseñas junto con `BACKUP_ARCHIVE_PASSWORD`.

## 4. Desplegar

Deploy en Coolify. `docker/start.sh` espera la base, corre `php artisan migrate --force`, arma las cachés,
crea las carpetas de storage y arranca Nginx + PHP-FPM + el programador de tareas.

**Nunca corras `migrate:fresh` sobre una instancia con datos: borra todo.**

## 5. Alta del negocio

En Coolify → la app → Terminal (o `docker exec` al contenedor), parado en `/var/www`:

```bash
php artisan instalar:cliente \
  --negocio="Talabartería Rosita" \
  --duenio="Rosa Mamani" \
  --email=rosa@ejemplo.bo \
  --telefono=70011223 \
  --direccion="Calle Comercio 123, Sucre" \
  --rubro=talabarteria \
  --rol=admin
```

Qué hace: siembra plan de cuentas, roles, período contable abierto, categorías de caja, unidades y la
plantilla de la tienda; guarda los datos del negocio; crea la usuaria dueña. **No** carga productos ni
clientes de ejemplo. Sin opciones pregunta lo esencial. Con `--password` fijás la clave; si se omite,
genera una segura y la imprime **una sola vez**: copiala en ese momento.

Se niega a correr si la base ya tiene usuarios o productos (`--force` para forzar).

Rubros con categorías sugeridas: `talabarteria`, `tienda`, `ninguno`.

Rol de la dueña: `admin` (ve finanzas y contabilidad) o `emprendedor` (sin contabilidad; para negocios
informales). Se puede cambiar después desde Usuarios.

## 6. Claves de servicios externos (Telegram, IA)

**No van en variables de entorno.** Se cargan desde la aplicación, en **Ajustes → Sistema** (solo visible
para el rol desarrollador), y quedan guardadas cifradas en la base:

- Token del bot de Telegram y secreto del webhook.
- API key de Anthropic u OpenAI, si el cliente contrata el asistente.

Ventajas: no quedan en el historial de la consola ni en la configuración de Coolify, y se rotan desde la
pantalla sin redeploy. Al escribirlas, la pantalla muestra solo los últimos 4 caracteres.

Si el cliente no usa bot ni asistente, dejá todo apagado: el sistema funciona igual.

## 6b. El bot de Telegram arranca solo

Desde 2026-10-09 el contenedor levanta el bot en cada despliegue (`docker/supervisord.conf`, programa
`telegram-bot`). No hay que arrancarlo a mano.

Cómo se comporta:
- Si el bot está apagado o sin token en **Ajustes → Sistema**, el proceso sale solo y se reintenta cada
  30 segundos. O sea: cargás el token en la pantalla y el bot empieza a responder en menos de un minuto,
  **sin redeploy**.
- Si lo apagás desde Ajustes, deja de escuchar también en menos de un minuto.
- Los mensajes del bot van a los logs del contenedor (Coolify → Logs) y a `storage/logs/laravel.log`.

Dos cosas para tener en cuenta:
- **Telegram no permite escuchar y tener webhook al mismo tiempo.** Si alguna vez configuraste un webhook
  para ese bot, el log lo va a decir con todas las letras; hay que elegir una de las dos formas.
- **Un solo contenedor puede escuchar.** Si algún día esa instancia corre con varias copias, el bot va en
  una sola o se pasa a webhook.
## 7. Respaldos

```bash
ops/provision-client-backup.sh <slug-del-cliente>
```

Cargar las variables que imprime en Coolify y redesplegar. Después, verificar en el contenedor:

```bash
php artisan backup:run        # debe terminar sin errores
php artisan backup:list       # Healthy en los dos discos (local y off-site)
```

## 8. Verificación antes de entregar

- [ ] Entra con el correo y la clave de la dueña.
- [ ] Ajustes → Mi negocio muestra el nombre correcto (no "Importadora El Cóndor").
- [ ] Subir foto a un producto → redeploy → la foto sigue ahí (paso 2).
- [ ] Hacer una venta de prueba y comprobar que aparece en Inicio; después borrarla.
- [ ] `php artisan backup:list` sano.
- [ ] Si usa tienda: Ajustes → Tienda en línea, abrir el enlace público y probar el QR.

## 9. Qué entregar al cliente

Enlace, correo, contraseña (que la cambie al entrar) y los primeros pasos: subir el logo en
Ajustes → Mi negocio, cargar productos con precio y stock, y si vende por catálogo, activar la tienda.

## Errores que cuestan caro

| Error | Consecuencia |
|---|---|
| No montar el volumen del paso 2 | Las imágenes desaparecen en cada redeploy |
| Montar `/var/www/storage` entero | El contenedor no arranca bien (faltan carpetas internas) |
| Cambiar la `APP_KEY` | Las claves de Telegram e IA guardadas quedan ilegibles |
| `php artisan migrate:fresh` | Borra todos los datos del cliente |
| `php artisan db:seed` | Carga datos de demostración y el usuario `admin@admin.com` / `password` |
| Perder `BACKUP_ARCHIVE_PASSWORD` | Los respaldos fuera del servidor quedan indescifrables |