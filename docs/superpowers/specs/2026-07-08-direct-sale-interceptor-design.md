# Interceptor de venta directo — Diseño (SP1)

**Fecha:** 2026-07-08
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Problema

El dueño vende por **voz**. Una orden hablada clara ("vende 3 figuras de Mario a 10") pasa por el
agente IA, que a veces elige el tool equivocado, pide confirmación y cae en el flujo de 4 pasos —
aunque la orden ya estaba completa. La elección de herramienta del LLM es probabilística y frágil.

## Objetivo

Ejecutar una orden de venta clara **al instante**, de forma **determinista** (sin LLM), tanto en
texto como en transcript de voz. Sin confirmación (red de seguridad = `/deshacer`, ya existente).
Si la frase no calza el patrón, cae al agente IA actual (nunca empeora).

## Contexto existente (reutilizado)

- `app/Services/Telegram/BotHandler.php` — `dispatch()` con "Free text routing" (~línea 220) y
  `handleVoiceMessage()` que transcribe y rutea. El interceptor entra en ambos, solo cuando NO hay
  flujo estructurado activo (`nuevo:`, `venta_rapida:`, `devolver:`, `recordar:`).
- `app/Support/NumberParser.php` — `extractInt()`/`extractFloat()` entienden dígitos y palabras
  español ("tres", "diez", "cuarenta"; compuestos como "ciento cincuenta" NO soportados → toma el
  primero).
- `app/Services/Messaging/ProductSearchService::searchProducts($q, publicOnly:false)` — buscador
  fuzzy robusto (exacto → fuzzy → IA).
- `app/Services/QuickSaleService::sell(Product, qty, ?unitPriceCents, PaymentMethod, discountCents,
  actorId)` — venta instantánea; devuelve `['sale', 'below_cost', 'price_capped']`. Ya normaliza
  errores a `RuntimeException`.
- `app/Models/TelegramConversation` — máquina de pasos (para el estado pendiente de "elegir cuál").
- `app/Services/Telegram/BotAuthHandler::getAuthenticatedUser($chatId)` — usuario actor.

## Decisiones (tomadas con el usuario)

- **Venta sin confirmación** cuando la orden calza (producto + cantidad claros). Red = `/deshacer`.
- **Fallback al agente IA** cuando la frase NO calza el patrón (transcripción rara, etc.). Nunca peor.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Detectar intención de venta al inicio del texto/transcript: verbos imperativos/infinitivo `vende`, `vender`, `véndeme`, `vendeme`, `véndele`, `vendele`, `véndeme`, `vendan`. EXCLUIR pasado (`vendí`, `vendió`, `vendimos`, `vendiste`) → esos son reportes. |
| R2 | Parseo determinista: **precio** por cláusula `a/por/cada (uno)? <n> (bs\|bolivianos)?` = precio POR UNIDAD; `en total <n>` = total. **cantidad** = número restante (default 1), ignorando "unidad(es)/pieza(s)/uni/pza". **producto** = texto restante. Números vía `NumberParser`. |
| R3 | Método = contado por defecto; transferencia si la frase dice "transferencia"/"transfer". |
| R4 | 1 producto → `QuickSaleService::sell` al instante, sin confirmación; respuesta con desglose (cant × precio = total, método) + aviso ⚠️ si bajo costo o precio topado + "responde /deshacer para anular". |
| R5 | Varios productos → lista corta numerada + estado pendiente `venta_directa:elegir` (guarda cant/precio/método/candidatos). El siguiente mensaje numérico completa la venta. |
| R6 | 0 productos → "no encontré '<query>'" (manejado; NO cae al LLM: la intención de venta fue clara). |
| R7 | Frase que NO es orden de venta (parser devuelve null) → cae al ruteo actual (agente/búsqueda), sin cambios. |
| R8 | Corre en texto Y en transcript de voz, solo cuando no hay flujo estructurado activo. |
| R9 | **Venta posicional** (cuando el vendedor no sabe el nombre): si hay una **lista numerada pendiente** de candidatos — de la **búsqueda por foto/visión** o de la desambiguación por nombre — reconocer órdenes tipo "vende [cant] del [ordinal] [a precio]" donde ordinal ∈ {primero/primera/segundo/tercero/… o un número} → vender ese candidato al instante. Ej: foto → "1. Figura Mario, 2. Figura Luigi…" → "vende 3 del segundo a 30" vende 3× candidato #2. |

## Arquitectura

### `SaleCommandParser` (servicio puro, testeable)

`app/Services/Sales/SaleCommandParser.php`:
- `parse(string $text): ?ParsedSaleCommand` — devuelve null si no hay verbo de venta imperativo, o
  si no queda ni texto de producto ni referencia posicional. Si sí, extrae:
  - `quantity` (int ≥1), `unitPriceCents` (nullable), `totalPriceCents` (nullable, excluyente),
    `method` (PaymentMethod).
  - **por nombre:** `productQuery` (string) — texto de producto restante.
  - **posicional (R9):** `position` (int, 1-based, nullable) — cuando la frase usa "del [ordinal]"
    o "el/número N" en vez de nombre. `productQuery` queda null en ese caso.
- Ordinales reconocidos: primero/primera=1, segundo/segunda=2, tercero/tercera=3, cuarto=4, quinto=5,
  o un número ("del 2", "el número 3"). Prefijos "del/el/la/número/#".
- DTO `ParsedSaleCommand` (readonly): `quantity, unitPriceCents, totalPriceCents, method, productQuery, position`.
- Pieza pura sin DB → se prueba con muchas frases (voz sucia incluida).

### `BotSaleHandler::tryQuickSell(string $chatId, string $text): bool`

Orquesta (devuelve true si manejó el mensaje, false si no era orden de venta → el caller sigue):
1. `parse($text)`; si null → return false.
2. Resolver actor (auth).
3. **Si `position` != null (posicional, R9):** buscar la **lista de candidatos pendiente** en la
   conversación (de foto/visión `busqueda:multiple` o de `venta_directa:elegir`). Si no hay lista o la
   posición está fuera de rango → mensaje "no hay una lista para elegir el N" y return true. Si hay →
   tomar `candidatos[position-1]` y vender.
4. **Si `productQuery` != null (por nombre):** `searchProducts(productQuery)`. 0 → "no encontré";
   >1 → lista numerada + estado `venta_directa:elegir` (guarda cant/precio/método/candidatos);
   1 → vender.
5. Vender = calcular unitPriceCents (unit directo, o total/qty), `QuickSaleService::sell`, responder
   desglose + avisos (bajo costo / precio topado) + pista `/deshacer`. Limpiar estado pendiente.
6. Errores (`RuntimeException` de stock/etc.) → mensaje amable; return true.

### Lista de candidatos pendiente (foto + desambiguación)

- La **búsqueda por foto/visión** ya muestra resultados numerados y setea `busqueda:multiple` con
  `data.results` (product ids + nombres). El interceptor lee esa lista para las órdenes posicionales
  (R9), habilitando: foto → candidatos → "vende 3 del segundo a 30" → venta instantánea. (Hoy esa
  lista solo permite "responder el número" para el flujo viejo de 4 pasos; SP1 le agrega la orden
  posicional con cantidad+precio.)
- La desambiguación por nombre (paso 4, >1) guarda su propia lista en `venta_directa:elegir`.

### Completar elección pendiente (respuesta con solo el número)

`BotSaleHandler::handleDirectPick($chatId, TelegramConversation, string $text)` — cuando el step es
`venta_directa:elegir` y el usuario responde solo un número: vender `candidatos[n-1]` con la
cant/precio/método guardados, limpiar estado. (La orden posicional completa con cantidad/precio va por
`tryQuickSell`; este método cubre el "responde el número" simple tras una desambiguación por nombre.)

### Cableado en `BotHandler`

- En `dispatch()` "Free text routing": antes de `isConversationalQuery()/handleSearch`, si
  `$this->saleHandler->tryQuickSell($chatId, $text)` → return.
- En `handleVoiceMessage()`: tras transcribir y ANTES del ruteo a agente/búsqueda, mismo intento con
  el transcript.
- Rama de step `venta_directa:elegir` en el bloque de conversación activa → `handleDirectPick`.
- Ambos gates ya solo se alcanzan sin flujo estructurado activo.

## Manejo de errores

- Producto no encontrado / stock insuficiente → mensaje claro, sin venta.
- Parseo dudoso (sin número de producto claro) → parser devuelve null → agente IA (fallback).
- Precio compuesto no soportado por NumberParser ("ciento cincuenta") → toma el primero; si el
  resultado es evidentemente incompleto, el usuario ve el desglose y usa `/deshacer`. (Limitación
  documentada; el desglose en la respuesta la hace visible.)

## Seguridad

- Solo usuario autenticado vende (actor = `getAuthenticatedUser`). El interceptor no expone nada a la
  web; vive en el canal Telegram.

## Testing

- `SaleCommandParser` (unit):
  - Por nombre: "vende 3 figuras de mario a 10" → qty3, unit 1000, query "figuras de mario";
    "véndeme dos fundas" → qty2, sin precio; "vende 5 cables en total 40" → total 4000, query "cables";
    palabras número ("vende tres … a diez").
  - Posicional (R9): "vende 3 del segundo a 30" → qty3, position 2, unit 3000, query null;
    "vende el primero" → qty1, position 1; "vende 2 del número 3 a 15" → qty2, position 3, unit 1500.
  - Null: "vendí 3 hoy" (pasado), "cuánto vendí" (reporte), "hola".
- `BotSaleHandler::tryQuickSell` (feature, con seeders contables + stock):
  - single match por nombre vende + descuenta stock; ambiguo por nombre → estado pendiente + número
    completa; no encontrado → mensaje, sin venta; no-orden → return false.
  - **posicional:** con `busqueda:multiple` pendiente (2+ candidatos), "vende 3 del segundo a 30"
    vende el candidato #2 3×; posición fuera de rango → mensaje sin venta; posicional sin lista
    pendiente → mensaje sin venta.

## Fuera de alcance (Sub-proyecto 2 — spec aparte)

Botones inline (`callback_query`): hoy el webhook los permite pero NO hay handler, y `TelegramService`
no manda teclados. SP2 cableará `callback_query` + teclados y los aplicará a: **elegir producto
ambiguo** (un botón por candidato en vez de número) y **deshacer** (botón ↩️ bajo cada venta).
Reusable para recordatorios (Hecho/Posponer). No acelera el input por voz → por eso va después de SP1.
