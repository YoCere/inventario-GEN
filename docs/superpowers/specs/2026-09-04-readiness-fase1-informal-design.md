# Readiness Fase 1 (lanzamiento informal) — Diseño

**Fecha:** 2026-09-04
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Objetivo

Dejar el sistema **listo para que lo use gente real** en el primer lanzamiento, cuya audiencia son
**emprendedores informales** (el rol `emprendedor` ya en main: lives, ropa/zapatillas, sin NIT). Cerrar
lo **bloqueante** de ese perfil: un hueco de seguridad en el canal Telegram y un flake de tests que
esconde regresiones. Lo fiscal (compra con factura, SIAT) queda **fuera** — no aplica al informal.

## Alcance (fijado con el usuario)

Audiencia = **emprendedores informales**. Bloqueante = **seguridad + estabilidad**. Fiscal fuera.

| Item del audit | Decisión |
|---|---|
| **1. Telegram agent sin filtro de permisos** | ✅ En esta spec (R1) — hueco real de seguridad. |
| **2. "Fuga" config contable en Ajustes** | ❌ **No es vuln.** Settings es admin-only por **rol** (`middleware('admin')` + `abort_if(!isAdmin())`), no por permiso. Emprendedor (no admin) → 403. Permisos `settings.*` del rol quedan muertos → limpieza opcional diferida. |
| **3. Backups + restore probado** | ➡️ Ops en el server (checklist aparte, no código). |
| **4. Flake de tests (`ReceiptImportTest`)** | ✅ En esta spec (R2). |
| **5–6. Fiscal (compra factura, SIAT)** | ❌ Fuera (audiencia informal). |
| **10. Deploy Coolify** | ➡️ Ops (checklist aparte). |

## Estado actual (con file:line)

- **Enforcement de permisos del agente vive SOLO en `ToolRegistry`** (`app/Services/Agent/ToolRegistry.php`):
  - `forUser(User)` (`:26`) filtra por `requiredPermission()` de cada tool (null = pública). Respeta `Gate::before`.
  - `forWeb(User)` (`:42`) = `forUser` + excluye tools con `webExposed()===false` (el asistente web es read-only).
  - **`AgentService::run` NO chequea permisos** — no hay `->can()` ni chequeo de `requiredPermission` en ejecución.
    El único choke point de permisos es el filtrado del registry ANTES de correr el agente.
- **Canal web** (`app/Services/Assistant/AssistantWebHandler.php:36-37`): construye registry por-usuario con
  `$this->tools->forWeb($user)` + `app()->makeWith(AgentService::class, ['tools' => $registry])`. **Seguro.**
- **Canal Telegram** (`app/Services/Telegram/BotAgentHandler.php:20-24,50`): recibe `AgentService $agent`
  **inyectado** (el singleton de `AppServiceProvider:37` con el registry **completo**) y llama `$this->agent->run(...)`.
  **Nunca filtra por usuario.** → cualquier usuario vinculado ejecuta TODAS las tools sin importar su rol.
- **Auth del bot** (`BotHandler::dispatch` `:65`): exige `isAuthenticated($chatId)` (TelegramUser vinculado a un
  User). Post-auth `$telegramUser?->user` es no-null. Pero el usuario vinculado puede ser de **cualquier rol**.
- **Tools de escritura** (`StartSaleTool`, `SellProductTool`, `StartProductCreationTool`, `CancelLastSaleTool`):
  overridean `webExposed()=false` pero **NO** overridean `requiredPermission()` → heredan `BaseTool` default
  `null` = **públicas**. → aunque se aplique `forUser`, estas tools NO se gatean.
- **Tools de lectura/finanzas** (`GetBalanceSheetTool`, `GetFinancialStatusTool`, `GetIncomeAndExpensesTool`,
  `SearchProductsTool`, etc.): SÍ declaran `requiredPermission()` → `forUser` las gatea bien.
- **Flake de tests:** `Tests\Feature\Products\ReceiptImportTest` pasa aislado, falla en suite completa. Causa:
  `Setting::get` cachea (`Cache`), y el cache **no se limpia entre tests** → un `Setting::set` de un test filtra
  al siguiente. Ver `app/Models/Setting.php` (get/set con `Cache::remember`/`Cache::forget`).

## Requisitos

| # | Requisito |
|---|-----------|
| **R1a** | Las 4 tools de escritura declaran `requiredPermission()`: `StartSaleTool`→`sales.create`, `SellProductTool`→`sales.create`, `CancelLastSaleTool`→`sales.cancel`, `StartProductCreationTool`→`products.manage`. (Nombres exactos verificados contra `RolesAndPermissionsSeeder`; el emprendedor tiene los tres.) |
| **R1b** | `BotAgentHandler` construye el registry **por-usuario** con `forUser($user)` (NO `forWeb`: el bot escribe legítimamente). Espejo del web handler: inyectar `ToolRegistry` en el constructor, y en `handle()` construir `app()->makeWith(AgentService::class, ['tools' => $this->tools->forUser($user)])` en vez de usar el `AgentService` inyectado. Si `$user === null` → registry vacío (`new ToolRegistry()`) para no exponer nada (defensivo; post-auth no ocurre). |
| **R2** | Eliminar el bleed de cache de `Setting` entre tests: en `tests/TestCase.php::setUp()` (después de `parent::setUp()`) hacer `Cache::flush()` para que cada test arranque con cache limpio. Verificar que `ReceiptImportTest` pasa en suite completa (2 corridas seguidas verdes). |

## Arquitectura

Dos cambios, ambos aditivos y de bajo riesgo:

1. **Least-privilege en Telegram (R1).** El motor del agente no cambia. Se cierra el gap moviendo Telegram al
   mismo patrón que web: filtrar el registry por-usuario antes de correr. Para que el filtro sea efectivo sobre
   las acciones de escritura, esas tools deben declarar su permiso (R1a). Resultado: la matriz de acceso del bot
   pasa a depender del rol del usuario vinculado.

   | Tool | Permiso | Emprendedor | Cajero (solo sales.create) |
   |---|---|---|---|
   | StartSale / SellProduct | sales.create | ✅ | ✅ |
   | CancelLastSale | sales.cancel | ✅ | ❌ |
   | StartProductCreation | products.manage | ✅ | ❌ |
   | GetBalanceSheet / GetFinancialStatus / GetIncomeAndExpenses | finance.view | ❌ | ❌ |
   | SearchProducts / GetStock / GetSalesToday (lectura básica) | (según su `requiredPermission`) | según rol | según rol |

2. **Aislamiento de tests (R2).** El flake no es de lógica de negocio — es contaminación de cache entre tests.
   Un `Cache::flush()` en el `setUp` base garantiza que ningún `Setting` (ni otro valor cacheado) sobreviva de
   un test al siguiente. Barato, global, sin tocar tests individuales.

## Manejo de errores / seguridad

- **Choke point único:** el enforcement sigue centralizado en `ToolRegistry::forUser`. No se duplica lógica de
  permisos en `AgentService` ni en cada tool (las tools solo *declaran* su permiso; el registry *decide*).
- **Fail-closed:** si el usuario vinculado es null o no tiene el permiso, la tool no entra al registry → el
  modelo ni siquiera la ve como opción. No hay ejecución parcial.
- **`Gate::before` respetado:** `forUser` usa `$user->can()`, así que developer (que pasa todo por `Gate::before`)
  conserva acceso completo — sin regresión para admin/developer.
- **Invariante web intacto:** `forWeb` (read-only) no se toca; el asistente de la burbuja sigue igual.

## Testing

- **R1a/R1b (unit/feature):** con un `ToolRegistry` poblado igual que en `AppServiceProvider`:
  - Usuario con `finance.view` → `forUser($u)->all()` incluye `get_balance_sheet`.
  - Usuario sin `finance.view` (ej. emprendedor) → NO incluye `get_balance_sheet`.
  - Usuario con `sales.create` → incluye `start_sale`; sin él → no.
  - Usuario con `products.manage` → incluye `start_product_creation`; sin él → no.
  - `$user === null` → `forUser`/registry vacío → `all()` sin tools de escritura (o test directo de la rama null en `BotAgentHandler`).
- **No-regresión:** developer ve TODAS las tools (`Gate::before`). El web handler sigue usando `forWeb` (sin cambios).
- **R2:** `php artisan test` completo 2 corridas seguidas → verde estable, `ReceiptImportTest` incluido.

## Fuera de alcance

- **Limpieza de permisos muertos del emprendedor** (`settings.view`/`settings.edit-business`): inertes porque
  Settings es admin-only por rol. Requeriría una migración nueva para revocarlos sin ganancia funcional → diferido.
- **Enforcement de permisos dentro de `AgentService::run`** (defensa en profundidad además del registry): mejora
  válida pero no necesaria — el registry ya es choke point suficiente. Follow-up opcional.
- **Backups + restore probado** e item de **deploy Coolify**: verificación de ops en el server (checklist aparte).
- **Fiscal** (compra con factura B1 lado compra, SIAT F1+): audiencia formal, otro proyecto.
