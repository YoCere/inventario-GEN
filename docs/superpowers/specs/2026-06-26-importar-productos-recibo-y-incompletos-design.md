# Importar Productos desde Recibo + Visibilidad de Incompletos — Diseño

**Fecha:** 2026-06-26
**Estado:** Aprobado (diseño) — pendiente review del spec

## Objetivo

Dos capacidades en la sección Productos:

**Parte A — Importar productos desde recibo:** subir foto de recibo, la IA extrae las líneas (nombre, cantidad, precio de compra) y se crean productos nuevos en lote, con categoría y unidad por defecto (editables por fila) y stock inicial. El precio de venta queda vacío para llenar después.

**Parte B — Visibilidad de incompletos:** en la lista de productos, resaltar en amarillo los que no tienen precio de venta, y ofrecer toggles para filtrar "sin precio de venta" y "sin foto", para que el jefe vea qué falta completar.

Las partes son independientes y se entregan por separado. Parte B sirve para cualquier producto incompleto, no solo los importados.

## Contexto del código (verificado)

- Lista de productos: [resources/views/products/index.blade.php](../../../resources/views/products/index.blade.php), tabla = PowerGrid `App\Livewire\Products\ProductTable` (v6.7.3, `filter = outside`). Ya tiene búsqueda, export, filtros multiSelect de categoría/unidad/estado.
- Form de productos: Livewire `App\Livewire\Products\ProductForm`; crea vía `App\Services\ProductService::createProduct(ProductData): Product`.
- `ProductService::createProduct` autogenera SKU y slug, crea el producto y un `ProductStock` en `location_id` o `Location::default()`. Lanza `ProductException` si no hay ubicación default.
- `App\DTOs\ProductData::fromArray([...])` requiere `category_id`, `unit_id`, `name`, `purchase_price`, `selling_price`, `quantity`, `min_stock`, `is_active`, `description`, `notes`; opcionales `sku`, `location_id`, `is_public`, `featured`. Precios en céntimos (int).
- Reuso IA (ya existe, módulo recibos de compras):
  - `App\Services\Receipt\ReceiptParser::parse(UploadedFile): ReceiptData` — `ReceiptData->lines[]` = `ReceiptLine{ rawName, quantity, unitPrice(céntimos) }`, más `purchaseDate`, `supplierName`. Lanza `ReceiptParseException`.
  - `App\Services\Receipt\ProductMatcher::match(ReceiptData): ['matched'=>[...], 'unmatched'=>[...]]` — para marcar qué líneas ya existen en catálogo.
- Productos son admin-gated (`abort_if(!auth()->user()->isAdmin(), 403)` en `ProductForm::save`). La importación también será admin.
- Conversor HEIC cliente: `input[data-heic-aware]` auto-convierte iPhone HEIC→JPG (resources/js/heic-converter.js), compatible con uploads Livewire (lo usa la galería de productos).
- Producto tiene relación `images()` (galería) y `primaryImage`.

## Parte A — Importar productos desde recibo

### Componente Livewire `App\Livewire\Products\ReceiptImport`

Modal lanzado desde un botón en [products/index.blade.php](../../../resources/views/products/index.blade.php). Se elige Livewire (no endpoint JSON) por consistencia con el módulo de productos.

Estado:
- `public $receipt` — archivo subido (wire:model, input con `data-heic-aware`).
- `public bool $analyzing = false`.
- `public ?int $defaultCategoryId`, `public ?int $defaultUnitId` — defaults globales (selects).
- `public array $rows = []` — cada fila: `['name','purchase_price'(decimal en input, se convierte a céntimos), 'quantity','category_id','unit_id','include'(bool),'exists'(bool)]`.

Acciones:
1. `analyze(ReceiptParser $parser, ProductMatcher $matcher)`:
   - `abort_if(!isAdmin(),403)`.
   - Valida que haya `receipt` (image, mimes jpeg/jpg/png/webp, max 15360).
   - `$data = $parser->parse($this->receipt->...)` (UploadedFile temporal de Livewire).
   - `$match = $matcher->match($data)` → set `exists=true` y `include=false` por defecto para los que casan; los nuevos `include=true`.
   - Llena `$rows` con name, purchase_price (de céntimos a decimal para el input), quantity, category_id=defaultCategoryId, unit_id=defaultUnitId.
   - Captura `ReceiptParseException` → toast error (mismo patrón que recibos de compra: HEIC, sin key, modelo sin visión, etc.).
2. `applyDefaultsToAll()` — re-asigna defaultCategoryId/UnitId a todas las filas (botón "aplicar a todas").
3. `import(ProductService $service)`:
   - `abort_if(!isAdmin(),403)`.
   - Valida: cada fila incluida requiere `name`, `category_id`, `unit_id`, `purchase_price>=0`, `quantity>=0`.
   - Por cada fila con `include=true`: construir `ProductData::fromArray([... 'selling_price'=>0, 'quantity'=>stock, 'min_stock'=>0, 'is_active'=>true, 'description'=>null, 'notes'=>null ...])` y `$service->createProduct($data)`.
   - Cuenta creados, captura `ProductException` por fila (continúa con las demás, reporta fallidas).
   - `dispatch('pg:eventRefresh-product-table')`, cierra modal, toast resumen ("N productos creados, M ya existían/omitidos").

### Vista del modal

Tabla editable: columnas Nombre, Precio compra (input moneda), Stock inicial (input number), Categoría (select), Unidad (select), Incluir (checkbox). Filas con `exists=true` se muestran atenuadas con etiqueta "ya existe" y checkbox desmarcado. Arriba: selects de categoría/unidad default + botón "Aplicar a todas". Abajo: botón "Crear productos" (spinner en `analyzing`/importing).

### Datos importados
- name (del recibo), purchase_price (del recibo, céntimos), quantity = stock inicial (del recibo).
- selling_price = 0 (vacío → producto incompleto, resaltado por Parte B).
- category_id / unit_id = default o override por fila.
- SKU autogenerado por `ProductService`.

## Parte B — Visibilidad de incompletos (ProductTable)

### Resalte amarillo (sin precio de venta)
En `ProductTable`, añadir `actionRules()` (o ampliarlo) con:
```php
Rule::rows()->when(fn($product) => (int) $product->selling_price <= 0)
    ->setAttribute('class', '!bg-yellow-50');
```
(API verificada en PowerGrid v6.7.3: `Rule::rows()->when()->setAttribute('class', ...)`.)

### Toggles de filtro
- Propiedades públicas: `public bool $onlyMissingPrice = false;` y `public bool $onlyMissingPhoto = false;`.
- En `datasource()`:
  ```php
  $q = Product::query()->with(['category','unit']);
  if ($this->onlyMissingPrice) $q->where('selling_price', '<=', 0);
  if ($this->onlyMissingPhoto) $q->whereDoesntHave('images');
  return $q;
  ```
- Botones toggle: vista parcial añadida con `PowerGrid::header()->includeViewOnTop('livewire.products.product-table-toggles')`. La vista corre en el scope del componente, así que usa `wire:click="$toggle('onlyMissingPrice')"` y refleja estado activo con clase.
- Indicador "sin foto" en celda: en la columna existente, badge ámbar cuando el producto no tiene imágenes (opcional; el toggle ya cubre el filtrado).

## Manejo de errores

| Caso | Comportamiento |
|------|----------------|
| Recibo ilegible / IA sin JSON | Toast error (ReceiptParseException). Modal queda abierto. |
| HEIC iPhone | Convertido en cliente antes de subir (data-heic-aware). |
| Sin categoría/unidad default y fila sin override | Validación bloquea import; toast pide elegir categoría/unidad. |
| Sin ubicación default para stock | `ProductService` lanza `ProductException`; se reporta la fila fallida, las demás continúan. |
| Producto ya existe (match) | Fila desmarcada por defecto; el usuario decide. |

## Testing

- `ReceiptImport` (Livewire test): subir imagen fake + `Http::fake()` del proveedor IA → `analyze()` llena `rows` con exists marcado correctamente; `import()` crea N productos vía ProductService con selling_price=0 y stock=quantity; filas excluidas no se crean; categoría/unidad default aplican.
- `ProductTable` (Livewire/feature test): con `onlyMissingPrice=true` el datasource excluye productos con selling_price>0; con `onlyMissingPhoto=true` excluye los que tienen imágenes; row-rule marca clase amarilla en productos sin precio de venta.
- Verificación manual: importar un recibo real en móvil; revisar resalte y toggles en la lista.

## Fuera de alcance
- Asignar foto durante la importación (se hace luego editando el producto; el toggle "sin foto" ayuda a ubicarlos).
- Margen automático / precio de venta en la importación (queda vacío a propósito).
- Casar/crear proveedor desde este flujo (es de productos, no de compras).

## Decisiones (brainstorming)
1. Categoría/unidad → default global editable por fila.
2. Importar → nombre + precio compra + stock inicial; precio venta vacío.
3. Incompletos → resalte amarillo (sin precio venta) + toggles "sin precio venta" y "sin foto".
4. Parte A como componente Livewire (no endpoint), reusando ReceiptParser/ProductMatcher/ProductService existentes.
