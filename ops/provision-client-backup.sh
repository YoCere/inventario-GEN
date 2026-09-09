#!/usr/bin/env bash
#
# provision-client-backup.sh <client-slug>
#
# Provisiona el backup OFF-SITE de UN cliente (SaaS multi-tenant, una instancia por cliente):
# crea en Backblaze B2 un bucket privado + una Application Key scoped a ese bucket + una
# Lifecycle Rule de retencion, y genera el password de cifrado del archivo. Deja todo en un
# archivo .env listo para pegar en Coolify.
#
# Modelo: UNA cuenta B2 del vendedor; bucket + llave + password POR cliente (aislamiento:
# una llave filtrada no toca otros clientes).
#
# Requisitos (setup una-sola-vez del vendedor):
#   - b2 CLI instalado y autorizado con una MASTER key:  b2 authorize-account <masterKeyId> <masterKey>
#     (pip install b2  |  brew install b2-tools). Comandos aqui = estilo b2 CLI v3.
#     Si tu b2 es v4: los subcomandos son "b2 bucket create/update", "b2 key create", "b2 account get".
#   - openssl (para el password aleatorio).
#
# Uso:   ./ops/provision-client-backup.sh acme
# Salida: ops/provisioned/<slug>.env   (SECRETO — gitignored; pegar en Coolify + guardar en el gestor)
#
set -euo pipefail

SLUG="${1:?uso: $0 <client-slug>   (ej: acme, solo [a-z0-9-])}"
if ! [[ "$SLUG" =~ ^[a-z0-9-]+$ ]]; then
  echo "ERROR: slug invalido '$SLUG' — usar solo minusculas, numeros y guiones." >&2
  exit 1
fi

BUCKET="inv-backups-${SLUG}"           # los nombres de bucket B2 son globales — el prefijo evita colisiones
KEYNAME="inv-backup-${SLUG}"
RETENTION_DAYS="${RETENTION_DAYS:-90}" # dias que se conserva una version oculta/borrada antes de purgar
OUTDIR="$(cd "$(dirname "$0")" && pwd)/provisioned"
OUT="${OUTDIR}/${SLUG}.env"
mkdir -p "$OUTDIR"

command -v b2 >/dev/null      || { echo "ERROR: falta el b2 CLI (pip install b2)." >&2; exit 1; }
command -v openssl >/dev/null || { echo "ERROR: falta openssl." >&2; exit 1; }

echo "==> [$SLUG] bucket privado: $BUCKET"
b2 create-bucket "$BUCKET" allPrivate >/dev/null 2>&1 \
  || echo "    (el bucket ya existia, continuo)"

echo "==> [$SLUG] lifecycle: purgar versiones ocultas/borradas a los ${RETENTION_DAYS}d"
# daysFromUploadingToHiding=null -> nunca ocultamos la version vigente automaticamente.
# daysFromHidingToDeleting=N     -> cuando spatie 'borra' (oculta en B2 versionado), se purga N dias despues.
# => un DeleteObject (de spatie, de un bug, o de una llave comprometida) solo OCULTA; recuperable N dias.
b2 update-bucket --lifecycleRules \
  "[{\"daysFromUploadingToHiding\":null,\"daysFromHidingToDeleting\":${RETENTION_DAYS},\"fileNamePrefix\":\"\"}]" \
  "$BUCKET" allPrivate >/dev/null

echo "==> [$SLUG] application key scoped al bucket (con deleteFiles, para que el cleanup nativo corra)"
# b2 create-key imprime:  "<keyID> <applicationKey>"
KEY_LINE="$(b2 create-key --bucket "$BUCKET" "$KEYNAME" listBuckets,listFiles,readFiles,writeFiles,deleteFiles)"
KEY_ID="$(echo "$KEY_LINE" | awk '{print $1}')"
APP_KEY="$(echo "$KEY_LINE" | awk '{print $2}')"
[ -n "$KEY_ID" ] && [ -n "$APP_KEY" ] || { echo "ERROR: no pude leer la key creada." >&2; exit 1; }

echo "==> [$SLUG] endpoint/region S3 de la cuenta B2"
ACCT="$(b2 get-account-info)"
S3_URL="$(echo "$ACCT" | sed -n 's/.*"s3ApiUrl": *"\([^"]*\)".*/\1/p' | head -1)"
REGION="$(echo "$S3_URL" | sed -n 's#https://s3\.\([^.]*\)\.backblazeb2\.com.*#\1#p')"
[ -n "$S3_URL" ] || { echo "ERROR: no pude leer s3ApiUrl de la cuenta." >&2; exit 1; }

echo "==> [$SLUG] password de cifrado del archivo"
ARCHIVE_PW="$(openssl rand -base64 32)"

umask 077
cat > "$OUT" <<EOF
# Cliente: ${SLUG}  — generado $(date -u +%FT%TZ)
# SECRETO. Pegar estas vars en Coolify (env del app de ESTE cliente), redeploy, y
# guardar BACKUP_ARCHIVE_PASSWORD en el password manager del equipo (>=2 personas):
# si el VPS del cliente muere, es lo UNICO que descifra su backup off-site.
B2_BUCKET=${BUCKET}
B2_KEY_ID=${KEY_ID}
B2_APPLICATION_KEY=${APP_KEY}
B2_REGION=${REGION}
B2_ENDPOINT=${S3_URL}
BACKUP_ARCHIVE_PASSWORD=${ARCHIVE_PW}
EOF

echo ""
echo "LISTO -> ${OUT}"
echo "Siguiente: pegar esas 6 vars en Coolify (app del cliente '${SLUG}') -> redeploy -> verificar (ver README)."
