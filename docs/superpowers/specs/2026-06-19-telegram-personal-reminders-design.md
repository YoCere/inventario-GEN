# Recordatorios personales por Telegram — Diseño

**Fecha:** 2026-06-19
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Objetivo

Permitir que cada usuario del bot de Telegram cree **recordatorios personales** (reuniones,
compras, recados) que el bot le envía como DM a la hora indicada. Estrictamente per-usuario:
nadie ve ni recibe los recordatorios de otro.

## Contexto existente (reutilizado)

- `app/Models/TelegramUser.php` — mapea `chat_id → user_id`. Habilita scope personal.
- `app/Services/Telegram/BotHandler.php` — ruteo de texto/voz/foto; delega lenguaje natural
  al agente IA (`agent:active`) con tools tipo `BaseTool`.
- `app/Services/Agent/Tools/*` — patrón de tool para el agente (ej. `GetLowStockTool`).
- `app/Jobs/SendTelegramMessage.php` — envío asíncrono a un `chat_id`.
- `app/Console/Commands/SendDailySummaryCommand.php` + `routes/console.php` — patrón de
  comando programado por scheduler.
- `app/Http/Controllers/TelegramWebhookController.php` — hoy solo procesa `update['message']`.

`User` **no** tiene `company_id` (sistema multi-empresa pero sin tenencia a nivel usuario) →
el aislamiento se hace solo por `user_id`. No se agrega `company_id`.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Recordatorio personal por usuario (no global) |
| R2 | Crear por lenguaje natural (texto/voz) vía agente IA |
| R3 | Crear por comando guiado `/recordar` (pasos, sin IA) |
| R4 | Tipos: una-vez + recurrentes (diario / semanal / mensual / custom) |
| R5 | Texto libre + enlace opcional a entidad de negocio (Product/Sale/Customer) |
| R6 | Entrega = DM Telegram al usuario dueño |
| R7 | Listar / cancelar / posponer los propios |
| R8 | Aislamiento estricto por `user_id` (anti-IDOR, espejo del fix `updateLine`) |
| R9 | Zona horaria correcta para relativos ("mañana 3pm") y recurrencia (DST) |

## Riesgos y mitigaciones

- **Fecha mal parseada por IA → recordatorio inútil.** El tool IA **confirma la fecha
  interpretada** antes de guardar ("📅 Te recuerdo *X* el *jue 20/06 15:00*. ¿Ok?").
- **Zona horaria / DST en recurrentes.** Guardar `remind_at` en UTC; calcular recurrencia
  en la tz del negocio.
- **Botones inline (Hecho/Posponer) = `callback_query`.** El webhook hoy solo maneja
  `message`. Trabajo extra aislado en WP6.

## Enfoque elegido

**Módulo standalone** dentro del sistema: tabla `reminders` + modelo + tools de agente +
comando `reminders:dispatch` por cron cada minuto. Descartados: jobs diferidos con `delay()`
(malos para recurrencia, no listables/cancelables) y Google Calendar/MCP (dependencia
externa, sobreingeniería).

## Modelo de datos — tabla `reminders`

```
id
user_id          FK users          -- dueño; scope personal (R1, R8)
chat_id          string  nullable  -- chat que lo creó; fallback resuelve por user_id al enviar
title            string
body             text    nullable
remind_at        datetime (UTC)    -- próxima ejecución
timezone         string            -- tz para mostrar y calcular recurrencia
recurrence       enum(none,daily,weekly,monthly,custom) default none
recurrence_rule  json    nullable  -- días de semana, intervalo, día del mes, etc.
remindable_type  string  nullable  -- morph: Product/Sale/Customer (R5)
remindable_id    bigint  nullable
status           enum(pending,sent,done,cancelled,snoozed) default pending
last_sent_at     datetime nullable
sent_count       int default 0
created_via      enum(nl,command)
timestamps
índice (status, remind_at)         -- escalabilidad del despacho
índice (user_id, status)           -- listados del usuario
```

Modelo `Reminder` con scope `forUser($userId)` aplicado en TODA lectura/cancelación.

## Flujo de despacho

`reminders:dispatch` (scheduler, cada minuto, `withoutOverlapping`, `onOneServer`):

1. `where status IN (pending, snoozed) AND remind_at <= now()`, en chunks.
2. Resolver `chat_id` (del registro; fallback a `TelegramUser` por `user_id`).
3. `SendTelegramMessage::dispatch($chatId, $mensaje)` (con botones Hecho/Posponer en Fase 2).
4. Si recurrente → calcular próximo `remind_at` vía servicio de recurrencia, mantener `pending`,
   `sent_count++`, `last_sent_at = now()`. Si una-vez → `status = sent`.

## Servicio de recurrencia

Servicio puro `RecurrenceCalculator::nextOccurrence(Reminder $r, Carbon $from): ?Carbon`:

- Sin estado, sin dependencias externas → unitario y exhaustivamente testeable.
- Calcula en la `timezone` del recordatorio, devuelve UTC.
- Casos: diario, semanal (días seleccionados), mensual (día del mes, con overflow de fin de
  mes), custom (intervalo). Maneja DST.

## Creación

**Vía comando (`/recordar`)** — `ReminderHandler` con pasos guiados (espejo de `/nuevo`):
título → fecha/hora → ¿recurrente? → confirmar. Determinista, sin costo IA.
Comando `/recordatorios` para listar; opción de cancelar por número.

**Vía lenguaje natural** — tools del agente:
- `CreateReminderTool` — recibe del LLM: título, datetime ISO, recurrencia. Valida que
  `remind_at` sea futuro. **Confirma la fecha interpretada** antes de persistir.
- `ListRemindersTool` — lista los del usuario autenticado.
- `CancelReminderTool` — cancela uno propio por referencia.
- Se pasa al agente el `now` + tz del negocio para resolver relativos.

## Enlace a entidades (R5, Fase 3)

`remindable` polimórfico → Product / Sale / Customer. Resolver en ambas vías de creación
("recuérdame reponer [Redmi 14c]"). El mensaje de despacho incluye un enlace/resumen de la
entidad.

## Seguridad

- Toda lectura/cancelación filtra por `user_id = usuario autenticado` (scope `forUser`).
  Test explícito: usuario A no ve ni cancela recordatorios de B (espejo anti-IDOR `updateLine`).
- El despacho solo envía al `chat_id` del dueño.

## Escalabilidad

- Índices `(status, remind_at)` y `(user_id, status)`.
- Query de despacho acotada + chunked; envíos ya asíncronos vía cola.
- Cron/minuto suficiente para volumen bajo/medio; a futuro partición o scheduler dedicado.

## División de tareas (paquetes de trabajo / DAG)

Cada WP = unidad aislada, interfaz clara, testeable sola. Todos con TDD.

| WP | Trabajo | Depende de |
|----|---------|-----------|
| **WP1 Datos** | migración + modelo `Reminder` + factory + scope `forUser` + tests | — |
| **WP2 Recurrencia** | `RecurrenceCalculator` puro + unit-tests (DST, fin de mes) | — |
| **WP3 Despacho** | comando `reminders:dispatch` + registro scheduler + entrega; test con `Carbon::setTestNow` | WP1, WP2 |
| **WP4 Comando guiado** | `ReminderHandler` (`/recordar`) + routing BotHandler + `/recordatorios` listar/cancelar | WP1 |
| **WP5 Tools IA** | `CreateReminderTool` + `ListRemindersTool` + `CancelReminderTool` + confirmación fecha + registro en agente | WP1, WP2 |
| **WP6 Botones inline** | `callback_query` en webhook + dispatch + Hecho/Posponer-1h | WP3 |
| **WP7 Entidades** | morph `remindable` + resolver en ambas vías de creación | WP1, WP4, WP5 |

Orden: **WP1+WP2 (paralelo) → WP3 → WP4+WP5 (paralelo) → WP6 → WP7**.

## Control de calidad

- TDD obligatorio por WP.
- WP2: tabla de casos de recurrencia + DST (el más propenso a bugs sutiles).
- WP1/WP4/WP5: test de scope (A no ve/cancela de B).
- WP3: test con tiempo viajado — recurrente re-agenda, una-vez no re-dispara.
- Smoke-test del flujo real del bot antes de cerrar cada fase.
- Code review por WP.

## Fases (YAGNI)

- **Fase 1 (MVP):** WP1+WP2+WP3+WP4+WP5 — una-vez + recurrente, texto libre, crear/listar/cancelar.
- **Fase 2:** WP6 — botones Hecho/Posponer.
- **Fase 3:** WP7 — enlace a entidades de negocio.
