# Fundación de conexión al SIN (F1a) — Diseño

**Fecha:** 2026-07-20
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Macro facturación SIAT + QR + WhatsApp (ver `siat-fiscal` en memoria / F0 en main `d7a3203`).

## Decisiones tempranas (fijadas con el usuario)

| Decisión | Elegido | Consecuencia |
|---|---|---|
| Modalidad | **Computarizada en Línea** | SIN firma digital; el hash SHA-256 cumple integridad. **F3 (firma) se descarta.** |
| Tipo de sistema | **Propio** | Autorización a nombre del NIT del negocio; no hay asociación proveedor-cliente. |
| Alcance F1 | **Dividido en F1a + F1b** | F1a = fundación (este spec). F1b = emisión de facturas. |
| Ambiente | **Sin piloto todavía → simulador-first** | El adaptador SIAT real se escribe desde los XSD pero su verificación en vivo espera al piloto. |
| Puntos de venta | **Uno** | Un CUFD por día; el diseño guarda CUFD por sucursal+PV para escalar sin rehacer. |

## Objetivo

Construir todo lo que habla con el SIN de forma diaria, más la costura y el simulador, de modo que la
emisión de facturas (F1b) se desarrolle y pruebe **sin depender del ambiente real del SIN**. F1a **no
emite ninguna factura**. Corresponde a los "pasos 1-4 diarios" del proceso SIAT (independientes de la
venta).

## Contexto existente (reutilizado)

- Cola `QUEUE_CONNECTION=database` (hay Jobs, ej. `app/Jobs/SendTelegramMessage.php`). Scheduler en
  `bootstrap/app.php` (Laravel 11).
- `App\Support\BusinessTime` — centraliza timezone vía setting `business_timezone`. La `fechaEmision`
  del SIN exige reloj sincronizado (causa top de rechazo); el reloj del VPS se sincroniza por NTP a
  nivel sistema, y F1a agrega la sincronización horaria con el SIN al job diario.
- `App\Models\Setting::get/set` (cache-forever; cifra claves sensibles — ver `ENCRYPTED_KEYS`).
- Alertas: patrón `SendTelegramMessage` / `TelegramService` para avisar fallos.
- **De F0 (en main):** `products.sin_code`, `units.sin_code` (hoy campo manual), `PaymentMethod::siatCode()`
  (con `@todo`: provisional hasta el catálogo real), `Setting store_nit`. F1a los conecta a los catálogos reales.
- **NO existe** ningún código fiscal (grep confirmado): F0 dejó solo columnas de datos.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Interfaz `App\Fiscal\Siat\FiscalProvider` — contrato único con el SIN: `obtenerCuis()`, `obtenerCufd(int $sucursal, int $puntoVenta)`, `verificarComunicacion(string $recurso): bool`, `sincronizarCatalogo(string $tipo): array`. Declara también `enviarFactura(...)` y `anularFactura(...)` (implementadas en F1b). |
| R2 | `App\Fiscal\Siat\SimulatorFiscalProvider` — implementación completa y determinista: CUIS/CUFD con formato válido, códigos de recepción OK, muestras de catálogos. Configurable para simular caída de comunicación. **Todo F1 se testea contra esto.** |
| R3 | `App\Fiscal\Siat\SiatFiscalProvider` — implementación SOAP real de autorización/catálogos/comunicación desde los XSD conocidos. `enviarFactura`/`anularFactura` se completan en F1b. **Marcada como no verificada en vivo hasta el piloto** (docblock explícito). |
| R4 | Selección por setting `fiscal_provider` (`simulator`\|`siat`, default `simulator`), resuelta en un service provider que bindea la interfaz a la implementación elegida. |
| R5 | Almacenamiento de códigos: tabla/modelo `FiscalCuis` (value, expires_at ~365d) y `FiscalCufd` (value, codigo_control?, sucursal, punto_venta, direccion, expires_at 24h). Token en `Setting` cifrado. |
| R6 | Servicio `App\Fiscal\Siat\FiscalAuthority` — orquesta el ciclo: devuelve el CUFD vigente para (sucursal, PV), lo re-pide on-demand si falta o venció (24h, no fin de día comercial), y renueva el CUIS si está por vencer. Es lo que F1b consumirá. |
| R7 | Job diario programado en `bootstrap/app.php`: asegura CUFD del día, renueva CUIS si corresponde (avisa desde 5 días antes), sincroniza catálogos y hora. Idempotente. Ante fallo o CUIS por vencer → **alerta por Telegram**. Corre antes de la primera venta del día. |
| R8 | Espejo de catálogos del SIN en **una tabla genérica** `fiscal_catalog_entries` (`catalog_type`, `code`, `description`, `synced_at`, + campos opcionales por tipo en JSON), sincronizada a diario. Una sola tabla escala a catálogos nuevos sin migración. Tipos: actividades económicas, productos/servicios, unidades, tipos de documento, métodos de pago, leyendas, mensajes. |
| R9 | Verificación de comunicación **por recurso** (no global) + flag `fiscal_offline` que se prende automáticamente cuando la comunicación falla. El manejo de contingencia es F2; F1a deja el flag y el verificador. |
| R10 | Trazabilidad: tabla `fiscal_logs` que registra cada llamada al SIN (servicio, request, response, códigos/mensajes de error, timestamp). |
| R11 | Ajustes fiscales (settings): NIT/razón social/municipio/teléfono/dirección emisor, código sucursal, código punto de venta, código actividad económica, ambiente (piloto/producción), modalidad (computarizada fija por ahora), `fiscal_provider`, credenciales del portal (cifradas). Reusa `store_nit` de F0. |

## Arquitectura

### La costura (interfaz + dos implementaciones)
`FiscalProvider` es el único punto de contacto con el SIN. `SimulatorFiscalProvider` (default) permite
construir y probar F1a **y** F1b sin el ambiente real. `SiatFiscalProvider` encapsula el cliente SOAP.
Un service provider bindea la interfaz según `fiscal_provider`. Cambiar de simulador a real es un
setting — ese es el pago de la abstracción, y la razón de arrancar sin el piloto.

### Diario vs por-factura
F1a implementa SOLO el ciclo diario (Token/CUIS/CUFD, catálogos, hora, verificación de comunicación) y
su almacenamiento/trazabilidad. La emisión por-factura (CUF, XML, envío, estados) es F1b, que consume
`FiscalAuthority` para el CUFD vigente. Separarlos espeja el propio doc del SIAT y mantiene cada pieza
testeable por separado.

### Reloj y trazabilidad
La `fechaEmision` correcta es crítica (rechazo silencioso por desfase). F1a sincroniza hora con el SIN
en el job diario y usa `BusinessTime`. Cada llamada al SIN se registra en `fiscal_logs` — necesario
para soporte y para el plan de pruebas de la autorización.

## Seguridad

- Credenciales del portal y token: cifrados en `Setting` (patrón `ENCRYPTED_KEYS` existente). Nunca en
  el repo ni en `.env` plano.
- Sin firma digital (Computarizada) → no hay clave privada que custodiar en F1a (eso habría sido F3).
- El ambiente (piloto/producción) es un setting explícito; cruzar credenciales piloto/prod genera
  documentos inválidos, así que el adaptador y los logs registran contra qué ambiente se llamó.

## Manejo de errores

- Job diario falla / CUIS por vencer → alerta por Telegram, no silencioso.
- CUFD vencido a media jornada (24h) → `FiscalAuthority` lo re-pide on-demand.
- Comunicación caída → `verificarComunicacion` por recurso prende `fiscal_offline` (contingencia real = F2).
- Toda llamada fallida queda en `fiscal_logs` con códigos del SIN.

## Testing

Todo contra `SimulatorFiscalProvider`:
- Ciclo CUIS/CUFD: obtener, cachear, detectar vencimiento, renovar; CUFD por sucursal+PV.
- `FiscalAuthority`: devuelve CUFD vigente; re-pide si vencido; renueva CUIS cerca del vencimiento.
- Job diario: idempotente (correrlo dos veces no duplica ni rompe); dispara alerta ante fallo simulado.
- Sync de catálogos: puebla las tablas espejo; re-sync actualiza sin duplicar.
- `verificarComunicacion`: caída simulada prende `fiscal_offline`.
- Trazabilidad: cada llamada deja un `fiscal_log`.
- `SiatFiscalProvider`: tests de forma (arma envelopes SOAP válidos contra los XSD), **no en vivo**.

## Fuera de alcance

- Entidad `Invoice`, XML doc sector 1, algoritmo CUF, validación XSD del documento, hash, envío/anulación
  real de facturas, máquina de estados fiscal → **F1b**.
- Empaquetado de contingencia + factura consolidada diaria (99003) → **F2**.
- Firma digital → **descartada** (Computarizada en Línea).
- Verificación en vivo del adaptador real → cuando haya ambiente piloto (credenciales + WSDL).
