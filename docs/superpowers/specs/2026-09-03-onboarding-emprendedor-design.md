# Onboarding por Perfil — Rol Emprendedor (Nivel 1) — Diseño

**Fecha:** 2026-09-03
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Objetivo

Que un vendedor informal (lives, ropa/zapatillas, sin NIT ni facturación) entre y vea un sistema
**limpio y sin miedo**: solo productos, ventas y su tienda online — sin contabilidad, sin el toggle
"¿con factura?". Y una guía que lo **anima** a arrancar. El camino de crecimiento a negocio formal
debe seguir suave (los libros se arman solos por detrás; formalizar es prender un switch + el rol).

## Decisiones (fijadas con el usuario)

| Decisión | Elegido |
|---|---|
| Guía | **Checklist de bienvenida en el dashboard** (auto-marcado por datos, se oculta solo). |
| Resumen financiero | El Emprendedor **NO** lo ve (sin `finance.view`); ve el dashboard general con sus ventas. |
| Gate de facturación | Setting de negocio `facturacion_activada` (no del rol), default apagado. |

## Estado actual (con file:line)

- Roles + permisos (Spatie) en `database/seeders/RolesAndPermissionsSeeder.php`: `ROLE_PERMISSIONS`
  (`:86-109`) define admin/staff; roles creados en `run()` (`:122-132`) con `syncPermissions` idempotente.
  `staff` = dashboard.view + products.view + customers.manage + sales.view/create (muy limitado, ni
  crea productos). Permisos granulares existentes (finance.view, finance.accounting, products.manage,
  purchases.manage, shop.admin, etc.).
- El menú Finanzas se gatea por permiso (`@canany(['finance.view','finance.accounting',…])` en
  `resources/views/layouts/navigation.blade.php`). Sin esos permisos, no se ve.
- POS: el toggle **"¿quiere factura?"** vive en `resources/views/sales/create.blade.php` (agregado en F0);
  el guard server-side de `wants_invoice` está en `app/Http/Requests/StoreSaleRequest.php` (exige cliente
  con identidad de facturación si `wants_invoice=true`). `SaleService` persiste `wants_invoice` y postea
  el asiento igual (con/sin impuestos).
- Ajustes: `app/Livewire/Settings/SettingGroups.php` (grupos + defaults + labels + tipo de campo; grupo
  `impuestos` ya existe). Toggles booleanos se renderizan como select en `setting-form.blade.php`.
- Dashboard: `resources/views/dashboard.blade.php` (o el controller/vista del `dashboard.view`).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | **Rol `emprendedor`** en `RolesAndPermissionsSeeder`: agregar a `ROLE_PERMISSIONS` la clave `emprendedor` con: `dashboard.view`, `products.view`, `products.manage`, `categories.manage`, `units.manage`, `customers.manage`, `suppliers.manage`, `purchases.view`, `purchases.manage`, `sales.view`, `sales.create`, `sales.complete`, `sales.cancel`, `shop.admin`, `shop.landing.manage`, `settings.view`, `settings.edit-business`. Crear el rol en `run()` con `syncPermissions` (idempotente). **NO** incluye finance.*, users.payroll, products.kardex, assets/loans/budgets/production.manage, audit.view, users.manage, settings.edit-technical, roles.manage. |
| R2 | **Setting `facturacion_activada`** default `'0'`: migración aditiva insert-if-missing + exponerlo en `SettingGroups` (grupo `impuestos`, como toggle boolean con label "Facturación activada") para que se pueda prender. |
| R3 | **POS oculta el toggle** "¿con factura?" cuando `facturacion_activada !== '1'`: envolver el control en `resources/views/sales/create.blade.php` con la condición. |
| R4 | **Guard server-side en `SaleService`** (choke point de TODAS las ventas: POS, bot, web): al crear la venta, `wants_invoice` efectivo = `$data->wants_invoice && Setting::get('facturacion_activada','0') === '1'`. Si la facturación está apagada, la venta se persiste con `wants_invoice=false` aunque llegue `true`. (Poner el guard en `SaleService`, no solo en `StoreSaleRequest`, para cubrir bot/web además del form.) |
| R5 | **Checklist de bienvenida** en el dashboard, visible **solo** para usuarios con rol `emprendedor`: tarjeta con 3 pasos: (a) "Cargá tus productos" → link a crear producto, marcado ✅ si `Product::count() > 0`; (b) "Registrá tu primera venta" → link al POS, marcado ✅ si `Sale::count() > 0`; (c) "Mirá tus ventas" → link (siempre disponible). Cuando (a) y (b) están ambos completos, la tarjeta **no se muestra** (se oculta sola por datos; sin flag de descarte). |

## Arquitectura

Tres piezas ortogonales, todas aditivas:
1. **Rol** — solo datos (seeder). El gating de menús/rutas ya existe por permiso; un rol sin
   `finance.accounting` no ve el hub de Contabilidad ni sus rutas (403).
2. **Facturación** — un setting de negocio que gatea el toggle del POS (vista) + un guard server-side.
   Es la palanca de crecimiento: apagado para el informal, encendido al formalizarse.
3. **Checklist** — un partial/componente Blade condicionado al rol, con estado derivado de datos
   (conteos), sin persistencia. Se auto-oculta.

**Invariante clave:** la venta **igual postea su asiento** (informal, sin IVA) por detrás — los libros
existen para el día que el emprendedor crezca a formal (upgrade = prender facturación + cambiar rol,
sin migración).

## Manejo de errores / seguridad

- El guard de facturación es **server-side** (no confiar en que el toggle esté oculto): `wants_invoice`
  se ignora/fuerza a false si la facturación está apagada.
- El rol Emprendedor sin `finance.accounting` → intentar acceder a una ruta contable da **403** (el
  middleware/permiso ya lo cubre).
- Seeder idempotente (`syncPermissions`); no toca admin/staff/developer existentes.

## Testing

- **Rol:** un usuario `emprendedor` tiene los permisos esperados y **carece** de `finance.accounting`;
  un GET a una ruta contable (ej. `finance.statements.index` o `accounting.opening.index`) → 403.
- **Facturación apagada:** `facturacion_activada=0` → el POS no renderiza el toggle; un `StoreSaleRequest`
  con `wants_invoice=true` resulta en una venta con `wants_invoice=false` (guard server-side).
- **Facturación encendida:** `facturacion_activada=1` → el toggle aparece; `wants_invoice=true` se respeta.
- **Checklist:** usuario emprendedor con 0 productos → la vista del dashboard muestra "Cargá tus productos";
  con ≥1 producto y ≥1 venta → la tarjeta no aparece.
- No-regresión: admin/staff siguen con sus permisos; el flujo de venta con factura (negocio formal) intacto.

## Fuera de alcance (Nivel 2)

- Selector de perfil al primer login ("¿Vendo nomás? / Formal con contador?") que setee el rol + adapte todo.
- Dashboard "Mis ventas" dedicado (simplificado) para el emprendedor.
- Facturación electrónica real (SIAT F1b) — un proyecto aparte.
