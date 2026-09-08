# Readiness Fase 1 (informal) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar el hueco de permisos del canal Telegram (least-privilege por rol) y estabilizar la suite de tests, para lanzar a emprendedores informales.

**Architecture:** El enforcement de permisos del agente vive solo en `ToolRegistry::forUser`. El canal web ya lo aplica; Telegram no. Se cierra el gap (a) declarando `requiredPermission()` en las 4 tools de escritura y (b) filtrando el registry por-usuario en `BotAgentHandler`, espejo del web. Aparte, un `Cache::flush()` en el `TestCase` base elimina el bleed de cache de `Setting` entre tests. Motor del agente sin cambios.

**Tech Stack:** Laravel 11, Spatie Permission, PHPUnit, Telegram bot (webhook).

---

### Task 1: Declarar `requiredPermission()` en las tools de escritura + test del filtrado

**Files:**
- Modify: `app/Services/Agent/Tools/StartSaleTool.php`
- Modify: `app/Services/Agent/Tools/SellProductTool.php`
- Modify: `app/Services/Agent/Tools/CancelLastSaleTool.php`
- Modify: `app/Services/Agent/Tools/StartProductCreationTool.php`
- Test: `tests/Feature/Agent/ToolRegistryPermissionTest.php`

Contexto: `BaseTool::requiredPermission()` default `null` (pública). `ToolRegistry::forUser($u)` incluye la tool si `requiredPermission()===null` **o** `$u->can($perm)`. Las 4 tools de escritura hoy no lo overridean → son públicas → `forUser` no las gatea. Las de lectura/finanzas ya lo declaran.

- [ ] **Step 1: Escribir el test que falla** — crear `tests/Feature/Agent/ToolRegistryPermissionTest.php`:

```php
<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use App\Services\Agent\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ToolRegistry
    {
        // El singleton poblado en AppServiceProvider (todas las tools registradas).
        return app(ToolRegistry::class);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    public function test_write_tools_gated_by_permission(): void
    {
        $conPermiso = $this->userWith(['sales.create', 'sales.cancel', 'products.manage']);
        $tools = $this->registry()->forUser($conPermiso)->all();
        $this->assertArrayHasKey('start_sale', $tools);
        $this->assertArrayHasKey('sell_product', $tools);
        $this->assertArrayHasKey('cancel_last_sale', $tools);
        $this->assertArrayHasKey('start_product_creation', $tools);
    }

    public function test_write_tools_excluded_without_permission(): void
    {
        $sinPermiso = $this->userWith([]); // usuario pelado, cero permisos
        $tools = $this->registry()->forUser($sinPermiso)->all();
        $this->assertArrayNotHasKey('start_sale', $tools);
        $this->assertArrayNotHasKey('sell_product', $tools);
        $this->assertArrayNotHasKey('cancel_last_sale', $tools);
        $this->assertArrayNotHasKey('start_product_creation', $tools);
    }

    public function test_finance_tools_gated(): void
    {
        $conFinanzas = $this->userWith(['finance.view', 'finance.accounting']);
        $sinFinanzas = $this->userWith([]);

        $conKeys = $this->registry()->forUser($conFinanzas)->all();
        $sinKeys = $this->registry()->forUser($sinFinanzas)->all();

        $this->assertArrayHasKey('get_balance_sheet', $conKeys);
        $this->assertArrayNotHasKey('get_balance_sheet', $sinKeys);
    }

    public function test_developer_gets_all_via_gate_before(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $dev = User::factory()->create();
        $dev->assignRole('developer');

        $tools = $this->registry()->forUser($dev)->all();
        // Gate::before hace que developer pase todo → registry completo.
        $this->assertArrayHasKey('get_balance_sheet', $tools);
        $this->assertArrayHasKey('start_sale', $tools);
        $this->assertArrayHasKey('cancel_last_sale', $tools);
    }
}
```

- [ ] **Step 2: Correr el test — debe FALLAR**

Run: `php artisan test --filter ToolRegistryPermissionTest`
Expected: FAIL en `test_write_tools_excluded_without_permission` (las tools de escritura hoy son públicas → aparecen aunque el usuario no tenga permiso).

- [ ] **Step 3: Declarar el permiso en cada tool de escritura**

En `app/Services/Agent/Tools/StartSaleTool.php`, agregar el método (junto a `webExposed()`):

```php
    public function requiredPermission(): ?string
    {
        return 'sales.create';
    }
```

En `app/Services/Agent/Tools/SellProductTool.php`:

```php
    public function requiredPermission(): ?string
    {
        return 'sales.create';
    }
```

En `app/Services/Agent/Tools/CancelLastSaleTool.php`:

```php
    public function requiredPermission(): ?string
    {
        return 'sales.cancel';
    }
```

En `app/Services/Agent/Tools/StartProductCreationTool.php`:

```php
    public function requiredPermission(): ?string
    {
        return 'products.manage';
    }
```

- [ ] **Step 4: Correr el test — debe PASAR**

Run: `php artisan test --filter ToolRegistryPermissionTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Agent/Tools/StartSaleTool.php app/Services/Agent/Tools/SellProductTool.php app/Services/Agent/Tools/CancelLastSaleTool.php app/Services/Agent/Tools/StartProductCreationTool.php tests/Feature/Agent/ToolRegistryPermissionTest.php
git commit -m "fix(agent): tools de escritura declaran requiredPermission (gating por rol)"
```

---

### Task 2: `BotAgentHandler` filtra el registry por-usuario

**Files:**
- Modify: `app/Services/Telegram/BotAgentHandler.php`
- Test: `tests/Feature/Telegram/BotAgentHandlerPermissionTest.php`

Contexto: hoy `BotAgentHandler` recibe `AgentService $agent` (el singleton con el registry completo) y llama `$this->agent->run(...)` sin filtrar. El web handler (`AssistantWebHandler`) en cambio hace `forWeb($user)` + `makeWith`. Telegram debe hacer lo mismo pero con `forUser` (el bot escribe legítimamente, no es read-only).

- [ ] **Step 1: Escribir el test que falla** — crear `tests/Feature/Telegram/BotAgentHandlerPermissionTest.php`:

```php
<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\Telegram\BotAgentHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotAgentHandlerPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_passed_to_agent_is_filtered_by_user_permissions(): void
    {
        Http::fake(); // el bot manda mensajes salientes vía Http; no queremos red real.
        $this->seed(RolesAndPermissionsSeeder::class);

        // Usuario de bajo privilegio: puede vender pero NO ver finanzas.
        $user = User::factory()->create();
        $user->givePermissionTo('sales.create');

        $chatId = '999000';
        TelegramUser::create([
            'chat_id' => $chatId,
            'user_id' => $user->id,
            'identifier' => $user->email,
        ]);

        // Capturar el ToolRegistry que BotAgentHandler pasa al AgentService.
        $capturedTools = null;
        $this->app->bind(AgentService::class, function ($app, $params) use (&$capturedTools) {
            $capturedTools = $params['tools'];
            return new class($params['tools']) extends AgentService {
                public function __construct(public ToolRegistry $reg) {} // saltar parent ctor
                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    return ['text' => 'ok', 'messages' => []];
                }
            };
        });

        app(BotAgentHandler::class)->handle($chatId, 'hola');

        $this->assertNotNull($capturedTools, 'BotAgentHandler debe construir un AgentService con un registry.');
        $keys = $capturedTools->all();
        // Gateado: sin finance.view NO ve finanzas.
        $this->assertArrayNotHasKey('get_balance_sheet', $keys);
        // Permitido: con sales.create conserva la venta.
        $this->assertArrayHasKey('start_sale', $keys);
    }
}
```

- [ ] **Step 2: Correr el test — debe FALLAR**

Run: `php artisan test --filter BotAgentHandlerPermissionTest`
Expected: FAIL — hoy `BotAgentHandler` no hace `makeWith(AgentService, ['tools'=>...])`; usa el `$agent` inyectado, así que el binding closure no captura nada (`$capturedTools` queda null) o el registry es el completo (incluye `get_balance_sheet`).

- [ ] **Step 3: Modificar `BotAgentHandler`**

Cambiar el import y el constructor. Reemplazar la dependencia `AgentService $agent` por `ToolRegistry $tools`.

Agregar el import (junto a los otros `use`):

```php
use App\Services\Agent\ToolRegistry;
```

Constructor — reemplazar:

```php
    public function __construct(
        protected TelegramService $telegram,
        protected AgentService $agent,
        protected TtsService $tts,
    ) {}
```

por:

```php
    public function __construct(
        protected TelegramService $telegram,
        protected ToolRegistry $tools,
        protected TtsService $tts,
    ) {}
```

(Mantener `use App\Services\Agent\AgentService;` — se sigue usando en `makeWith`.)

En `handle()`, después de construir `$context` (línea ~36) y antes de cargar el history, construir el agente por-usuario. Reemplazar la línea:

```php
            $result = $this->agent->run($userText, $history, $context);
```

por:

```php
            // Least-privilege: filtrar las tools por permisos del usuario vinculado
            // (espejo de AssistantWebHandler, pero forUser — el bot sí escribe).
            // Sin usuario vinculado → registry vacío (fail-closed).
            $registry = $user ? $this->tools->forUser($user) : new ToolRegistry();
            $agent = app()->makeWith(AgentService::class, ['tools' => $registry]);

            $result = $agent->run($userText, $history, $context);
```

(`$user` ya está en scope: se calcula en `$user = $telegramUser?->user;` cerca de la línea 30.)

- [ ] **Step 4: Correr el test — debe PASAR**

Run: `php artisan test --filter BotAgentHandlerPermissionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Telegram/BotAgentHandler.php tests/Feature/Telegram/BotAgentHandlerPermissionTest.php
git commit -m "fix(telegram): filtrar tools del agente por permisos del usuario (forUser)"
```

---

### Task 3: Eliminar el bleed de cache de `Setting` entre tests

**Files:**
- Modify: `tests/TestCase.php`

Contexto: `Setting::get` cachea vía `Cache`. El cache no se limpia entre tests → un `Setting::set` de un test filtra al siguiente. Síntoma conocido: `ReceiptImportTest` pasa aislado pero falla en suite completa.

- [ ] **Step 1: Modificar `tests/TestCase.php`** para limpiar el cache en cada `setUp`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Evita que valores cacheados (ej. Setting::get) filtren entre tests.
        Cache::flush();
    }
}
```

- [ ] **Step 2: Verificar que el flake desaparece — correr el test antes problemático dentro de un grupo amplio**

Run: `php artisan test --filter "ReceiptImport|Setting|Facturacion"`
Expected: PASS (sin fallos por Setting filtrado).

- [ ] **Step 3: Correr la suite completa dos veces — verde estable**

Run: `php artisan test`
Expected: PASS. Repetir una segunda vez: `php artisan test` → PASS (mismo resultado, sin flake intermitente).

- [ ] **Step 4: Commit**

```bash
git add tests/TestCase.php
git commit -m "test: flush de cache en setUp (elimina bleed de Setting entre tests)"
```

---

## Notas de verificación de ops (fuera del código — checklist para el server)

No son tareas de este plan (no tocan código), pero son parte del readiness. Verificar en el server tras el deploy:

- **Backups:** confirmar que el backup automático (spatie, 2am) está corriendo y **probar un restore** en un entorno aparte. Hubo incidente de restore manual antes — no asumir que anda.
- **Deploy Coolify:** confirmar que el pipeline corre `php artisan migrate --force`; smoke post-deploy (login emprendedor → sin menú Finanzas, POS sin toggle de factura, ruta contable → 403).
