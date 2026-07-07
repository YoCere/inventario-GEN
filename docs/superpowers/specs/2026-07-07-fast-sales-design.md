# Venta rápida instantánea — Diseño

**Fecha:** 2026-07-07
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Problema

Registrar una venta tarda demasiado. En Telegram, vender un solo producto exige 4 pasos
(cantidad → descuento → método de pago → confirmar). En el POS web hay que abrir la hoja de
pago y teclear el monto recibido incluso para un contado simple. El 80% de las ventas son
**1 producto, contado, cantidad 1, sin descuento** y deberían cerrarse en un toque/una frase.

## Objetivo

Cerrar la venta común al instante, sin preguntas obligatorias. Red de seguridad = **deshacer**
(no una confirmación previa). Telegram interpreta órdenes en lenguaje natural incluyendo precio
negociado. El flujo antiguo de 4 pasos se conserva como opción avanzada.

## Contexto existente (reutilizado)

- `app/Services/Telegram/BotSaleHandler.php` — flujo `venta_rapida:*` de 4 pasos + creación de venta.
- `app/Services/Agent/Tools/StartSaleTool.php` — tool IA que hoy solo muestra la ficha; `webExposed()=false`.
- `app/Services/Agent/AgentService.php` / `ToolRegistry` — el agente ya ejecuta tools. Regla de
  seguridad: los tools de venta NO se exponen al asistente web (`webExposed()=false`; ver
  [[web-assistant-bubble]]).
- `app/Services/Messaging/ProductSearchService.php` — resolución de productos (exacto → fuzzy → IA).
- `app/Services/Telegram/BotRefundHandler.php` + servicio de ventas — lógica existente de
  devolución que revierte stock y asientos contables (se reutiliza para deshacer).
- `resources/views/sales/create.blade.php` — POS web Alpine (`x-data="pos()"`) con carrito + hoja
  de pago (`submitSale()`, `payment.method`, `cash_received`).
- `app/Http/Controllers/SalesController.php` — `resource('sales')` (store crea la venta).

## Decisiones (tomadas con el usuario)

- **Precio bajo costo:** se vende igual, pero la respuesta incluye ⚠️ ("vendes bajo costo") y se
  registra quién vendió. No bloquea.
- **Deshacer:** el vendedor puede anular **su propia** última venta dentro de **15 minutos**; el
  admin puede anular cualquiera. No se puede si la venta ya fue devuelta.
- **Flujo de 4 pasos:** se conserva como opción avanzada (palabra clave / comando) para
  descuento/transferencia; no es el camino por defecto.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Tool IA `sell_product` (Telegram) que extrae producto, cantidad (def. 1), precio unitario (override opcional), método (def. contado), descuento (opcional) y ejecuta la venta al instante. `webExposed()=false`. |
| R2 | Interpretación de precio: *"a X bs"* = precio por unidad; *"en total X"* = total; sin precio → precio de lista. |
| R3 | Producto ambiguo (varios matches) → el agente muestra opciones y pregunta; nunca vende a ciegas. |
| R4 | Venta bajo costo → se cierra + ⚠️ en la respuesta + log del actor. |
| R5 | Respuesta muestra desglose (cant × precio = total, método) + número de venta + pista `/deshacer`. |
| R6 | Deshacer (`/deshacer` y NL "anula la última venta"): anula la última venta; vendedor su propia dentro de 15 min, admin cualquiera; restaura stock + revierte asientos; rechaza si ya devuelta. |
| R7 | POS web: botón **"Cobrar rápido"** (efectivo, monto exacto, sin vuelto) que cierra sin abrir la hoja de pago; toast "Deshacer (10s)". Incluye un **campo opcional de descuento** (ej. total 150 − descuento 10 = 140); vacío = sin descuento (un toque). |
| R8 | Telegram: tocar "Vender" en la ficha vende 1 al contado al instante (con deshacer), sin los 4 pasos. |
| R9 | Flujo de 4 pasos accesible como avanzado (palabra clave/comando). |
| R10 | Se guarda el precio real vendido y el actor (para reportes de margen con precio negociado). |
| R11 | Método **transferencia** permite adjuntar **opcionalmente** una foto del comprobante, guardada con la venta. No aplica al camino ultra-rápido de contado; vive en la hoja de pago web y en el flujo avanzado. |

## Arquitectura

### Motor de venta instantánea (núcleo compartido)

`QuickSaleService` (o método nuevo en el servicio de ventas existente) con dos operaciones puras
de dominio, usadas por Telegram y web por igual:

- `sell(Product $product, int $qty, ?int $unitPriceCents, PaymentMethod $method, ?int $discountCents, User $actor): Sale`
  - Valida stock (`qty <= disponible`), crea la venta + líneas, descuenta stock, postea asientos
    contables (mismo camino que el flujo actual). Registra `unit_price` real y `created_by`.
  - Devuelve la venta creada (con flag `below_cost` si `unitPrice < purchase_price`).
- `void(Sale $sale, User $actor): void`
  - Reglas R6 (ventana + propiedad + admin + no-si-ya-devuelta). Reutiliza la lógica de
    devolución existente para revertir stock + asientos. Marca la venta anulada.

Es la única pieza que toca dinero/stock/contabilidad → se prueba en aislamiento a fondo.

### Telegram — `SellProductTool` (nuevo tool IA)

- `inputSchema`: `product` (texto o id), `quantity` (def 1), `unit_price` (opcional), `total_price`
  (opcional, alternativo), `payment_method` (def cash), `discount` (opcional).
- `execute`: resuelve producto vía `ProductSearchService`. Si 0 matches → error amable; si >1 →
  devuelve la lista para que el agente pregunte (R3). Con 1 match: calcula precio efectivo (R2),
  llama `QuickSaleService::sell`, guarda `last_sale_id` del usuario, responde con desglose + ⚠️ si
  aplica (R4) + pista `/deshacer` (R5).
- `webExposed()=false`.
- La `description` del tool lleva las reglas de interpretación de precio para el modelo.

### Deshacer — comando + tool

- `/deshacer` en `BotHandler::handleCommand` y comprensión NL ("anula la última venta") →
  `QuickSaleService::void(lastSale, actor)`.
- `last_sale_id` + `last_sale_at` se guardan por usuario (columna en `telegram_users` o registro
  liviano). El web usa el id devuelto por el endpoint.

### POS web — "Cobrar rápido"

- Botón en el footer del carrito. Setea `payment.method='cash'`, `cash_received=total`, llama a
  `submitSale()` (o un endpoint `sales.quick`) → cierra sin abrir la hoja.
- Incluye un **input opcional de descuento** junto al botón: vacío → cobra el total (un toque);
  con valor → cobra `total − descuento` (ej. 150 − 10 = 140). Sin cálculo de vuelto.
- Éxito → toast "Venta #123 registrada — Deshacer (10s)" que llama al endpoint de anulación
  (`QuickSaleService::void`) dentro de la ventana.
- La hoja de pago actual queda para vuelto/transferencia (sin cambios salvo R11).

### Comprobante de transferencia (opcional) — R11

- Cuando el método es **transferencia**, la hoja de pago web ofrece **tomar/subir una foto** del
  comprobante (mismo patrón que `purchases.proof_image`: input `capture` en móvil). Es **opcional**
  — se puede cerrar la venta sin foto.
- La imagen se guarda en el disco `public` y se referencia en una columna nueva
  `sales.transfer_proof_path` (nullable). Migración aditiva (nunca `migrate:fresh`).
- El camino ultra-rápido de contado no toca esto.

### Telegram botón "Vender"

- En `BotSaleHandler`, la opción "Vender" de la ficha ejecuta `QuickSaleService::sell(product, 1,
  null, cash, null, actor)` al instante + deshacer, en vez de entrar a `venta_rapida:cantidad`.
- El flujo de 4 pasos se dispara solo por palabra clave/comando avanzado (R9).

## Seguridad / permisos

- Tools de venta y de anulación: `webExposed()=false` — nunca accesibles desde el asistente web.
- `void`: vendedor solo su propia venta y dentro de 15 min; admin cualquiera. Doble verificación
  en el servicio (no confiar solo en la UI).
- Precio override y anulación: siempre registran el actor.

## Manejo de errores

- Stock insuficiente → no vende, mensaje claro con disponible.
- Producto no encontrado / ambiguo → pedir aclaración, no vender.
- Deshacer fuera de ventana / no propietario / ya devuelta → mensaje explicativo, sin cambios.
- Fallo al postear contabilidad → transacción atómica; si algo falla, no queda venta a medias.

## Fases (YAGNI)

- **Fase 1:** `QuickSaleService` (sell + void) + `SellProductTool` (NL) + `/deshacer`. El mayor golpe.
- **Fase 2:** POS web "Cobrar rápido" + toast deshacer.
- **Fase 3:** botón "Vender" instantáneo + flujo de 4 pasos detrás de palabra clave.

## Control de calidad

- TDD por capa. `QuickSaleService` es lo más crítico: casos de stock, precio override, bajo costo,
  atomicidad, y void (ventana, propiedad, admin, ya-devuelta, reversa de stock + asientos).
- Tests del tool: parseo→venta, ambigüedad→pregunta, precio por unidad vs total.
- Smoke-test del bot real y del POS web antes de cerrar cada fase.
- Aislamiento por actor (un vendedor no anula ventas de otro salvo admin).

## Fuera de alcance

Código de barras, atajos de teclado, escáner, botones inline de Telegram (callback_query),
optimización de ventas multi-ítem. Posibles fases futuras.
