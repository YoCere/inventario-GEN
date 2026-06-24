# Lector de Recibos con IA para Crear Compras — Diseño (v1)

**Fecha:** 2026-06-24
**Estado:** Aprobado para implementación
**Alcance v1:** Solo casar productos existentes en catálogo. Crear productos nuevos = fase 2 (fuera de alcance).

## Objetivo

En el formulario de crear compra, permitir subir una imagen del recibo/factura del proveedor y usar IA (visión) para extraer automáticamente fecha, proveedor y líneas de producto (nombre, cantidad, precio de compra). El sistema casa cada producto contra el catálogo existente y prellena el formulario para que el usuario revise y corrija antes de guardar. **Nunca auto-guarda.**

## Contexto del código existente

- Formulario de compra: [resources/views/purchases/form.blade.php](../../../resources/views/purchases/form.blade.php). Basado en **Alpine.js** (`Alpine.data('purchaseForm', ...)`), no Livewire.
  - Ya existe el campo `proof_image` (`<input type="file" accept="image/*">`).
  - Líneas de producto en array Alpine `items[]`. Cada item: `{ key, product_id, product_name, product_code, quantity, unit_price, selling_price, subtotal }`.
  - **Precios en céntimos** (entero ×100). El input muestra decimal con separadores de `window.thousandSeparator`/`decimalSeparator`.
  - Productos se agregan vía TomSelect "master search" → callback `addProduct(product)`. Solo productos existentes (cada item exige `product_id`).
- Controller: [app/Http/Controllers/PurchaseController.php](../../../app/Http/Controllers/PurchaseController.php). `store()` exige items con `product_id` existente; no soporta crear productos nuevos.
- Infra IA: [app/Services/Agent/AgentService.php](../../../app/Services/Agent/AgentService.php). Soporta `anthropic` y `openai_compatible`. Configuración en `Setting`: `ai_provider`, `anthropic_api_key`, `openai_api_key`, `ai_model`, `ai_base_url`, `ai_max_tokens_response`. Hay `CostTracker` con límite diario.

## Constraint: modelo con visión

Leer un recibo requiere un modelo multimodal:
- **Anthropic Claude** → visión nativa (formato `image` con `source.base64`). OK.
- `openai_compatible` con **deepseek-chat** → sin visión. Fallaría.

Manejo: `ReceiptParser` intenta la llamada con el proveedor/modelo configurado. Si el proveedor no soporta imágenes o el modelo rechaza, devuelve error claro: *"El modelo de IA configurado no soporta imágenes. Configura un modelo con visión (ej. Claude) en Ajustes IA."* El formulario queda intacto para llenado manual.

## Flujo

1. Usuario abre crear compra, selecciona imagen en `proof_image`.
2. Click en nuevo botón **"📷 Analizar recibo con IA"** (junto al campo `proof_image`).
3. Alpine sube la imagen vía `fetch` a `POST /purchases/parse-receipt` (multipart). No crea la compra.
4. Backend `parseReceipt`:
   a. Valida archivo (imagen, tamaño máx).
   b. `ReceiptParser::parse($file)` → llamada one-shot de visión → JSON estructurado.
   c. `ProductMatcher::match($items)` → adjunta `matched_product_id`, `matched_name`, `matched_code`, `confidence` por línea (o `null` si no casa).
   d. Devuelve JSON al front.
5. Alpine procesa respuesta:
   - Prellena `purchase_date` si vino.
   - Sugiere proveedor (solo si casó uno existente; si no, ignora).
   - Por cada línea **casada**: hace push a `items[]` con `product_id`, `quantity`, `unit_price` (en céntimos). `selling_price` queda en 0 (el usuario lo pone).
   - Líneas **no reconocidas**: se muestran en una sección aparte "Revisar manualmente" con el texto crudo, cantidad y precio leídos; el usuario las busca con el search existente o las ignora.
6. Usuario revisa, corrige, completa precio de venta, guarda con el flujo normal de `store()`.

## Componentes nuevos

### `app/Services/Receipt/ReceiptParser.php`
- Responsabilidad: una sola llamada de visión que devuelve datos estructurados del recibo.
- **No** usa el tool-loop de `AgentService` (sin tools, más simple). Reusa Settings y `CostTracker`.
- Entrada: archivo de imagen (UploadedFile).
- Salida (DTO o array):
  ```
  {
    purchase_date: "YYYY-MM-DD" | null,
    supplier_name: string | null,
    items: [ { raw_name: string, quantity: number, unit_price: number /* céntimos */ } ]
  }
  ```
- Prompt: instruye devolver SOLO JSON con ese shape. Precios convertidos a céntimos (×100) en el parser (la IA devuelve decimal, el parser normaliza), para coincidir con el resto del form.
- Provider:
  - `anthropic`: mensaje con bloque `image` (base64) + bloque `text` (prompt). `anthropic-version` header. Modelo de `ai_model`.
  - `openai_compatible`: formato `image_url` con data URI base64 (estándar OpenAI vision). Si el modelo no lo soporta, capturar error → excepción clara.
- Robustez de parseo: extraer el primer bloque JSON válido de la respuesta (la IA a veces envuelve en texto/markdown). Si no hay JSON válido → excepción `ReceiptParseException`.
- Registra costo vía `CostTracker` igual que AgentService; respeta límite diario.

### `app/Services/Receipt/ProductMatcher.php`
- Responsabilidad: casar `raw_name` → producto del catálogo.
- Estrategia: normalizar (lowercase, quitar acentos/espacios extra), buscar por `name`/`sku` con `LIKE` por tokens; rankear por similitud (`similar_text` o Levenshtein). Umbral de confianza para considerar "casado".
- Devuelve por item: `matched_product_id|null`, `matched_name`, `matched_code`, `confidence` (0–1).
- Considera scoping multi-empresa/activos si aplica en el modelo Product (revisar en implementación).

### Controller + ruta
- `PurchaseController::parseReceipt(Request $request)`:
  - `abort` si no autorizado (mismo gate que crear compra).
  - Valida `image` (mimes, max ~8MB).
  - Llama parser + matcher, devuelve `response()->json(...)`.
  - try/catch → JSON `{ error: mensaje }` con status apropiado para que Alpine muestre toast.
- Ruta `POST /purchases/parse-receipt` → name `purchases.parseReceipt`, mismo middleware/grupo que el resto de purchases.

### Frontend ([form.blade.php](../../../resources/views/purchases/form.blade.php))
- Botón "📷 Analizar recibo con IA" junto a `proof_image`. Deshabilitado si no hay archivo seleccionado.
- Estado Alpine: `analyzing` (spinner), `unmatchedItems[]`.
- Método `analyzeReceipt()`: toma el `File` del input `proof_image`, `FormData`, `fetch` POST con CSRF, maneja respuesta/errores con toasts existentes (`window.dispatchEvent('toast')`).
- Mapear líneas casadas a `items[]` reusando la forma de `addProduct` (cuidando que `unit_price` ya viene en céntimos).
- Render de sección "Revisar manualmente" con `unmatchedItems`.

## Manejo de errores

| Caso | Comportamiento |
|------|----------------|
| Sin archivo seleccionado | Botón deshabilitado / toast "Selecciona una imagen primero". |
| Sin API key configurada | Toast "Configura la API key de IA en Ajustes". Form intacto. |
| Modelo sin visión | Toast claro pidiendo modelo con visión. Form intacto. |
| Límite diario de costo | Toast "Límite diario de IA alcanzado". |
| IA no devuelve JSON válido / imagen ilegible | Toast "No se pudo leer el recibo, intenta con otra foto o llena manual". |
| Match parcial (algunos no reconocidos) | Casados van a la tabla; resto a "Revisar manualmente". No es error. |

## Testing

- `ReceiptParser`: unit con HTTP fake (Anthropic + openai_compatible). Verifica construcción del payload con imagen, parseo de JSON envuelto en texto, normalización de precios a céntimos, excepción en JSON inválido y en proveedor sin visión.
- `ProductMatcher`: unit con productos sembrados. Verifica match exacto, match difuso por token, y `null` bajo umbral.
- `parseReceipt` endpoint: feature test con imagen fake + HTTP fake del proveedor; verifica JSON de salida y manejo de errores (sin key, archivo inválido).
- Front: verificación manual en móvil y PC (subir foto → analizar → revisar prellenado).

## Fuera de alcance (fase 2)

- Crear productos nuevos desde el recibo (requiere tocar form, validación y `store`).
- Casar/crear proveedor nuevo automáticamente (v1 solo sugiere si ya existe).
- Detección de precio de venta (los recibos no lo traen).
- Guardar el JSON crudo del recibo para auditoría.

## Decisiones tomadas (brainstorming)

1. Productos sin match exacto → **buscar parecido + dejar elegir** (no auto-crear).
2. Alcance v1 → **solo casar existentes**; crear nuevos diferido a fase 2.
3. Reusar infra IA existente (Settings + CostTracker), pero llamada one-shot sin tool-loop.
