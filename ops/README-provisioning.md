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
