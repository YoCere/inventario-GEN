# Menú Finanzas por permiso (no por rol) — Diseño

**Fecha:** 2026-07-08
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Problema

El acceso al menú Finanzas se gatea por **rol hardcodeado** (`isAdmin()` en blades, `middleware('admin')`
en rutas), no por permiso. Consecuencias:
- Quitar `finance.view`/`finance.accounting` a un rol desde la pantalla de Roles **no surte efecto**
  (las rutas/menú no miran el permiso) → parece "no deja quitar el permiso".
- El primer grupo de rutas `/finance/*` **no tiene middleware** → hoy hasta el vendedor (staff) puede
  entrar por URL directa (hueco de seguridad).
- Ítems no-contables del menú (activos, préstamos, presupuestos, BOMs, producción) tampoco tienen
  permiso propio; solo `admin` por rol.

## Objetivo

Gatear TODO el menú Finanzas por **permisos granulares** (Spatie), de modo que la pantalla dev-only
de Roles controle el acceso por rol, ajustable en vivo. Default sin cambios de comportamiento:
**admin + developer ven finanzas; vendedor (staff) no.**

## Contexto existente (reutilizado)

- `database/seeders/RolesAndPermissionsSeeder.php` — catálogo `PERMISSIONS` + `ROLE_PERMISSIONS`.
  Ya existen `finance.view`, `finance.accounting`, `users.payroll`, `products.kardex`.
- `app/Livewire/Roles/RolesIndex.php` + `resources/views/livewire/roles/roles-index.blade.php` —
  CRUD de roles/permisos, **dev-only** (`abort_if(!isDeveloper())`), ya hace `syncPermissions` sobre
  cualquier rol (sin lock). Es el control que hoy no tiene efecto por falta de enforcement.
- `routes/web.php` — grupos `finance` (líneas ~99 sin middleware y ~112 con `middleware('admin')`).
- `resources/views/layouts/navigation.blade.php` — dropdown "Finanzas" (escritorio ~96 y móvil ~401),
  con sub-links gateados por `@if(auth()->user()->isAdmin())`.
- `app/Services/Telegram/BotHandler.php::cmdReports` — `/reportes`, hoy gateado por `isAdmin()`.
- Middleware `can:` de Laravel + Spatie (integra con Gate). `developer` pasa por `Gate::before`
  (super-usuario) → siempre autorizado sin importar el permiso.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Nuevos permisos: `assets.manage`, `loans.manage`, `budgets.manage`, `production.manage` (con label), agregados al catálogo `PERMISSIONS` y al set de `admin` en `ROLE_PERMISSIONS`. |
| R2 | Migración de datos **aditiva** (nunca `migrate:fresh`): crea los 4 permisos y los asigna a `developer` (todos) + `admin` (los 4), SIN re-sincronizar el resto de permisos de ningún rol (respeta personalizaciones existentes en prod). |
| R3 | Rutas `/finance/*` gateadas por `middleware('can:<permiso>')` según el mapeo (elimina el grupo sin-middleware y el `middleware('admin')`). Cierra el hueco de acceso por URL para staff. |
| R4 | Menú (escritorio + móvil): cada sub-link con `@can('<permiso>')`; el dropdown "Finanzas" visible con `@canany([...])`. Reemplaza los `@if(isAdmin())` de los ítems finanzas. |
| R5 | Bot: `/reportes` gateado por `can('finance.view')` en vez de `isAdmin()`. |
| R6 | Default de comportamiento sin cambios: admin+dev con acceso, staff sin acceso (seeded + migración). Ajustable por rol desde la UI de Roles (ahora sí con efecto). |

## Mapeo ruta/ítem → permiso

| Permiso | Rutas (nombres) / ítems de menú |
|---------|--------------------------------|
| `finance.view` | `finance.index`, `finance.transactions.index`, `finance.transactions.print`, `finance.categories.index` |
| `finance.accounting` | `finance.chart-of-accounts.index`, `finance.journal-entries.index`, `finance.journal-entries.book` (+`.print`), `finance.journal-entries.create`, `finance.statements.index`, `finance.accounting-periods.index`, `finance.trial-balance`, `finance.worksheet` |
| `assets.manage` | `finance.asset-categories.index`, `finance.fixed-assets.index` (+`.schedule`) |
| `loans.manage` | `finance.loans.index` (+`.schedule`) |
| `budgets.manage` | `finance.budgets.index` (+`.show`) |
| `production.manage` | `finance.boms.index`, `finance.production.index` |
| `users.payroll` (existe) | `finance.payroll.legacy-redirect` |
| `products.kardex` (existe) | `finance.kardex.legacy-redirect` |

Labels de los nuevos permisos:
- `assets.manage` → "Activos fijos y depreciación"
- `loans.manage` → "Préstamos y amortización"
- `budgets.manage` → "Presupuestos"
- `production.manage` → "Producción y recetas (BOM)"

## Arquitectura

### Permisos (seeder + migración)
- Seeder = fuente canónica para instalaciones nuevas: agrega los 4 permisos y los suma al array de
  `admin`. `developer` ya recibe `Permission::all()`. `staff` sin cambios.
- Migración aditiva para entornos existentes: `firstOrCreate` de los 4 permisos + `givePermissionTo`
  a `developer` y `admin` (idempotente, no destructivo). No usa `syncPermissions` (que borraría lo
  demás). `down()` = no-op (no revoca, para no romper configs).

### Rutas
Reorganizar el prefijo `finance` en sub-grupos por permiso, cada uno con `->middleware('can:<permiso>')`.
Se conservan EXACTAMENTE los nombres de ruta actuales (muchos blades usan `route('finance.*')`).

### Menú
En `navigation.blade.php` (escritorio y móvil), envolver el dropdown "Finanzas" con
`@canany(['finance.view','finance.accounting','assets.manage','loans.manage','budgets.manage','production.manage','users.payroll'])`
y cada sub-link con su `@can('<permiso>')`. Quitar los `@if(isAdmin())` de esos ítems.

### Bot
`cmdReports` en `BotHandler`: cambiar `if (!$user || !$user->isAdmin())` por
`if (!$user || !$user->can('finance.view'))`.

## Seguridad

- Enforcement real en el servidor (middleware `can:`), no solo esconder el menú → staff no entra por
  URL. `developer` siempre pasa (Gate::before). Cambios de permiso vía UI de Roles surten efecto
  (Spatie invalida su cache en `syncPermissions`).

## Testing

- Feature (rutas): `staff` → 403 en cada ruta finance; `admin` → 200; tras revocar `finance.view` al
  admin (en el test) → 403 en las rutas `finance.view` (prueba que es permission-driven, no rol).
  `developer` → 200 siempre.
- Migración: tras correrla, existen los 4 permisos y `admin`/`developer` los tienen; los permisos
  previos de admin quedan intactos (no re-sincronizado).
- Bot: `cmdReports` responde el menú a un user con `finance.view`, y lo rechaza a uno sin él.
- Menú (opcional/ligero): un render con staff no muestra el dropdown Finanzas.

## Fuera de alcance (follow-up separado)

Gating por-permiso de las **tools IA de finanzas** del agente (`GetFinancialStatusTool`,
`GetBalanceSheetTool`, `GetIncomeAndExpensesTool`) — hoy el agente no chequea permisos por-tool;
requiere un mecanismo nuevo (p.ej. `BaseTool::requiredPermission()` + filtro en `AgentService`/
`ToolRegistry`). Se diseña aparte. Mientras tanto, el bot solo expone finanzas vía `/reportes`
(ya gateado por R5) y esas tools siguen accesibles al chat del agente para quien lo tenga habilitado.
