# Datos fiscales base (F0) — Diseño

**Fecha:** 2026-07-20
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Facturación electrónica SIAT + cobro QR + bot WhatsApp (macro-proyecto decompuesto)

## Contexto del macro-proyecto

El objetivo final es facturación electrónica SIAT y, encima, cobro QR por WhatsApp — todo escalable
para que cada capa se enchufe sin cambios bruscos. Se decompuso en:

| # | Sub-proyecto | Depende de |
|---|---|---|
| **F0** | **Datos fiscales base (este spec)** | nada |
| F1 | Núcleo SIAT (emisión en línea, entidad `Invoice`, adaptador + simulador) | F0 |
| F2 | Consolidado diario "ventas menores del día" (99003) + contingencia | F1 |
| F3 | Firma digital XMLDSig (solo si modalidad Electrónica en Línea) | F1 |
| Q | Cobro QR (adaptador bancario, estados de pedido, reconciliación) | F1 |
| W | Canal WhatsApp (Evolution/YCloud) + bot sobre el AgentService existente | — / Q+F1 |

**F0 es la fundación:** captura los datos que hoy no existen y que el SIAT exige, sin integrarse con
ningún proveedor externo (ni SIAT, ni banco, ni WhatsApp). Ships valor solo (mejores fichas de cliente),
es 100% aditivo y desriesga todo lo demás.

## Problema

La venta actual es un registro POS interno correcto pero ciego a lo fiscal. Le falta:
- Identidad tributaria del cliente (NIT/CI): `Customer` solo tiene `name`, `email`, `phone`, `address`.
- Código SIN homologado de producto y de unidad: no existen en `Product`/`Unit`.
- Desglose de impuestos por venta: `Sale` no tiene campos de base imponible ni IVA/IT.
- Método de pago QR: el enum `PaymentMethod` solo tiene `cash` y `transfer`.

Sin estos datos, F1 no puede emitir una factura. F0 los agrega y los deja probados.

## Decisiones (tomadas con el usuario)

- **Desglose de impuestos: se incluye en F0.** Columnas de base imponible + IVA (débito fiscal) + IT en
  la venta, calculadas al vender. Precio sigue con IVA incluido.
- **Código SIN de producto: columna + campo manual.** La asignación masiva contra el catálogo oficial
  del SIN queda para F1 (requiere el servicio de sincronización de catálogos, que es externo).
- **Identidad de facturación reutilizable.** Pieza compartida cableada en el POS ahora, lista para que la
  tienda web y el bot la reusen sin refactor.
- La modalidad SIAT (Computarizada vs Electrónica, y por ende si hay firma digital) es una decisión de
  **F1**, no de F0 — F0 es válido para cualquiera de las dos.

## Contexto existente (reutilizado)

- `App\Models\Sale` (`invoice_number` correlativo INTERNO, no fiscal; `subtotal`/`total`/`global_discount`
  en centavos; `payment_method` casteado a enum; softdeletes).
- `App\Models\SaleItem` (`quantity`, `unit_price`, `discount`, `subtotal`, todo en centavos).
- `App\Models\Customer` (`name`, `email`, `phone`, `address`, `notes`; softdeletes).
- `App\Models\Unit` (`name`, `symbol`).
- `App\Models\Product` (`unit_id`, `sku`, `name`).
- `App\Enums\PaymentMethod` (`cash`, `transfer`).
- `App\DTOs\SaleData` (readonly) + `App\DTOs\SaleItemData` — construyen la venta.
- `App\Services\SaleService::createSale(SaleData): Sale` — corre en `DB::transaction`, ya integra
  `SaleAccountingService` (asientos por venta). **Es el punto donde se calcula el desglose fiscal.**
- Settings `tax_iva_rate`, `tax_it_rate` (ya existen), `store_nit`, `business_timezone`.
- Patrón de captura/edición Livewire del POS (pantalla de venta) y de settings.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Migración aditiva: `customers` gana `doc_type` (string, código tipo doc SIN 1–5), `doc_number` (string), `doc_complement` (string, nullable), `business_name` (string, nullable). Todas nullable. |
| R2 | Migración aditiva: `products.sin_code` (string, nullable) y `units.sin_code` (string, nullable). |
| R3 | Migración aditiva: `sales` gana `taxable_base` (int, centavos), `iva_amount` (int, débito fiscal), `it_amount` (int), `wants_invoice` (bool, default false). Nullable los montos / default 0. |
| R4 | `App\Enums\PaymentMethod` incorpora `QR` y `CARD`, y un método `siatCode(): int` que mapea cada caso al código de método de pago del SIN (efectivo=1, tarjeta=2, QR=7…). Mapa central, única fuente. |
| R5 | Objeto de valor `App\Fiscal\BillingIdentity` (docType, docNumber, complement, businessName) con validación de forma (no vacío cuando corresponde) y helpers `isComplete()`. NO verifica el NIT contra el SIN (eso es F1). |
| R6 | `Customer` expone la identidad: `billingIdentity(): ?BillingIdentity` y `hasBillingIdentity(): bool`, leídos de los campos de R1. |
| R7 | Componente/partial Livewire reutilizable de captura de identidad, usado por el POS. Selecciona un cliente existente (prellena si ya tiene identidad) o captura uno nuevo y lo persiste en su ficha. Reutilizable por tienda/bot en el futuro (sin dependencias del POS). |
| R8 | `SaleService::createSale` calcula y persiste `taxable_base`, `iva_amount`, `it_amount` desde las tasas de settings, y `wants_invoice` desde el flujo. El cálculo vive en un servicio propio `App\Fiscal\SaleTaxCalculator` (testeable aislado, sobrescribible). |
| R9 | `SaleData` (+`fromArray`/`toArray`) incorpora `wants_invoice` (bool, default false). La identidad viaja vía `customer_id` (el cliente ya trae su identidad). |
| R10 | **Venta rápida intacta:** sin identidad, sin `wants_invoice`, la venta se crea igual que hoy. Cero regresión al flujo rápido. |
| R11 | Formulario de producto y de unidad (admin) ganan el campo `sin_code` editable. |
| R12 | El cálculo de impuestos degrada con gracia: tasas sin setear → montos 0, sin excepción. |

## Arquitectura

### El cálculo de impuestos (`SaleTaxCalculator`)
Servicio propio, no lógica suelta en `SaleService`. Entrada: total de la venta, descuentos, (futuro:
exentos/giftcard). Salida: `taxable_base`, `iva_amount` (débito fiscal 13%), `it_amount` (3%). Fórmula
del caso general (sin ICE/IEHD/exentos), siguiendo el libro de ventas del SIN:
`base = subtotal − descuentos − giftcard`; `débito = base × 13%`; `it = total × 3%`. En Bolivia el IVA
va incluido en el precio, así que los montos no cambian el total — solo lo desglosan.

**Flag tributario:** la fórmula exacta (qué es exento, IVA por dentro vs por fuera, redondeos) la
confirma el contador del negocio. F0 implementa el caso general y lo deja sobrescribible; la validez
fiscal es responsabilidad del contribuyente, no del código.

### La identidad de facturación (`BillingIdentity`)
Objeto de valor inmutable + un componente de captura reutilizable. El POS lo usa ya; la tienda web y el
bot lo reusan después leyendo/escribiendo los mismos campos del `Customer`. Es la costura que evita
refactor cuando esos canales lleguen. No sabe nada del POS.

### Por qué esto no acopla nada
Todo F0 es aditivo y no toca ningún proveedor externo. Deja tres cosas listas para las capas de arriba:
`BillingIdentity` reutilizable, `PaymentMethod::siatCode()`, desglose persistido (habilita el libro de
ventas y la emisión F1), y `wants_invoice` (F1 emite individual, F2 consolida las que no lo pidieron).
La entidad `Invoice` y toda integración SIAT quedan para F1, sin que F0 prejuzgue la modalidad.

## Manejo de errores / compatibilidad

- Migraciones **aditivas y nullable** → ventas, clientes, productos y unidades existentes intactos.
  Nunca `migrate:fresh` (MySQL dev compartido).
- Flujo de venta rápida sin cambios observables (el flujo rápido es crítico del negocio).
- Tasas sin configurar → desglose 0, sin romper la venta.
- `doc_number` con validación de **forma** solamente (requerido cuando se pide factura, longitud/tipo).
  La verificación del NIT contra el servicio del SIN es F1.
- Identidad incompleta cuando `wants_invoice=true` → el POS lo bloquea con mensaje claro; una venta sin
  factura nunca exige identidad.

## Testing

- **Migraciones:** aditivas; una venta/cliente/producto previo sigue válido tras migrar.
- **`SaleTaxCalculator`:** base/IVA/IT correctos con tasas seteadas; 0 cuando no hay tasas; con descuento
  global; caso borde total=0.
- **`SaleService::createSale`:** persiste el desglose y `wants_invoice`; **venta rápida (sin identidad,
  sin wants_invoice) se crea igual que hoy** (test de no-regresión).
- **`PaymentMethod::siatCode()`:** mapea cada caso, incluye QR y CARD.
- **`BillingIdentity` / `Customer`:** `isComplete()`, `hasBillingIdentity()`, ida y vuelta de los campos.
- **Captura Livewire:** guarda identidad en el cliente; cliente existente con identidad → prellena;
  `wants_invoice` sin identidad completa → error de validación.
- **`sin_code`:** editable y persistido en producto y unidad.

## Fuera de alcance (otras fases)

- Entidad `Invoice`, generación de XML, CUF/CUFD/CUIS, envío al SIN, XSD, cola, estado fiscal → **F1**.
- Sincronización del catálogo de productos/unidades del SIN + asignación masiva de `sin_code` → **F1**.
- Verificación del NIT contra el SIN → **F1**.
- Factura consolidada diaria (99003) + contingencia → **F2**. Firma digital → **F3**.
- Cobro QR (generación, webhook, reconciliación) → **Q**. Canal WhatsApp → **W**.
