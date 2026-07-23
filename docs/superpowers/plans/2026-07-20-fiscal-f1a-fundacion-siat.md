# Fundación de conexión al SIN (F1a) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir la fundación que habla con el SIN a diario (interfaz + simulador + ciclo Token/CUIS/CUFD + catálogos + comunicación + trazabilidad), sin emitir ninguna factura, de modo que F1b se desarrolle contra el simulador sin depender del ambiente real.

**Architecture:** Una interfaz `FiscalProvider` con dos implementaciones — `SimulatorFiscalProvider` (default, determinista) y `SiatFiscalProvider` (SOAP real, esqueleto marcado no-verificado). Un service provider bindea por setting `fiscal_provider`. Almacenamiento de códigos (CUIS/CUFD), catálogos espejo y logs en tablas propias. `FiscalAuthority` orquesta el ciclo; un job diario lo dispara y alerta por Telegram si falla. Todo testeado contra el simulador.

**Tech Stack:** Laravel 11, cola `database`, PHPUnit (class-style), MySQL. Sin dependencias nuevas (SOAP via `ext-soap`/`SoapClient` de PHP, ya disponible; el adaptador real no se ejercita hasta el piloto).

**Spec:** `docs/superpowers/specs/2026-07-20-fiscal-f1a-fundacion-siat-design.md`

---

## Convenciones del repo (leer antes de empezar)

- **NUNCA `migrate:fresh`/`migrate:refresh`** (MySQL dev compartido). Migraciones aditivas.
- Tests: clase PHPUnit, `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.
- Providers se registran en `bootstrap/providers.php`. Scheduling (Laravel 11) va en `routes/console.php` con `Schedule::`.
- `App\Models\Setting::get/set` cachea; cifra las claves de `ENCRYPTED_KEYS` (const privada en `app/Models/Setting.php`).
- Alertas: patrón `app/Jobs/SendTelegramMessage.php` / `TelegramService` (leer antes de usar).
- `App\Support\BusinessTime` para timezone. Dinero (no aplica acá) en centavos.
- Namespaces fiscales: `App\Fiscal\Siat\*` (código nuevo), tablas `fiscal_*`.

## Estado existente

- De F0 (main): `products.sin_code`, `units.sin_code`, `PaymentMethod::siatCode()` (con `@todo` provisional),
  `Setting store_nit`. F1a agrega los catálogos que hacen reales esos códigos (el mapeo fino de sin_code
  contra el catálogo es F1b/uso posterior; F1a trae el catálogo espejo).
- Cola `database`, scheduler vía `routes/console.php`, providers en `bootstrap/providers.php`.
- **Cero código fiscal previo.**

---

## File Structure

- Create `app/Fiscal/Siat/FiscalProvider.php` — interfaz (contrato).
- Create `app/Fiscal/Siat/Dtos/{Cuis,Cufd}.php` — value objects de códigos.
- Create `app/Fiscal/Siat/SimulatorFiscalProvider.php` — implementación fake.
- Create `app/Fiscal/Siat/SiatFiscalProvider.php` — esqueleto SOAP real (no verificado).
- Create `app/Providers/FiscalServiceProvider.php` + registrar en `bootstrap/providers.php`.
- Create migraciones + modelos: `FiscalCuis`, `FiscalCufd`, `FiscalCatalogEntry`, `FiscalLog`.
- Create `app/Fiscal/Siat/FiscalAuthority.php` — ciclo CUIS/CUFD.
- Create `app/Fiscal/Siat/CatalogSync.php` — sincroniza catálogos → tabla espejo.
- Create `app/Fiscal/Siat/FiscalLogger.php` (o decorator) — trazabilidad + flag offline.
- Create `app/Console/Commands/FiscalDailyCycle.php` + schedule en `routes/console.php`.
- Modify `app/Models/Setting.php` — sumar claves fiscales cifradas a `ENCRYPTED_KEYS`.
- Tests bajo `tests/Feature/Fiscal/Siat/`.

---

## Task 1: Interfaz `FiscalProvider` + DTOs + Simulador (auth) + binding

**Files:** Create `app/Fiscal/Siat/FiscalProvider.php`, `app/Fiscal/Siat/Dtos/Cuis.php`, `Dtos/Cufd.php`, `app/Fiscal/Siat/SimulatorFiscalProvider.php`, `app/Providers/FiscalServiceProvider.php` · Modify `bootstrap/providers.php` · Test `tests/Feature/Fiscal/Siat/FiscalProviderBindingTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_simulator(): void
    {
        $this->assertInstanceOf(SimulatorFiscalProvider::class, app(FiscalProvider::class));
    }

    public function test_simulator_returns_valid_shaped_cuis_and_cufd(): void
    {
        $sim = new SimulatorFiscalProvider();

        $cuis = $sim->obtenerCuis();
        $this->assertNotEmpty($cuis->value);
        $this->assertTrue($cuis->expiresAt->isFuture());

        $cufd = $sim->obtenerCufd(0, 0);
        $this->assertNotEmpty($cufd->value);
        $this->assertTrue($cufd->expiresAt->isFuture());
    }

    public function test_binding_switches_to_siat_when_setting_says_so(): void
    {
        Setting::set('fiscal_provider', 'siat');
        $this->assertInstanceOf(\App\Fiscal\Siat\SiatFiscalProvider::class, app(FiscalProvider::class));
    }
}
```

- [ ] **Step 2: Correr — falla** (`php artisan test --filter FiscalProviderBindingTest`)

- [ ] **Step 3: DTOs**

`app/Fiscal/Siat/Dtos/Cuis.php`:
```php
<?php

namespace App\Fiscal\Siat\Dtos;

use Carbon\CarbonImmutable;

/** Código Único de Inicio de Sistemas (vigencia ~365 días). */
readonly class Cuis
{
    public function __construct(
        public string $value,
        public CarbonImmutable $expiresAt,
    ) {}
}
```
`app/Fiscal/Siat/Dtos/Cufd.php`:
```php
<?php

namespace App\Fiscal\Siat\Dtos;

use Carbon\CarbonImmutable;

/** Código Único de Facturación Diaria (vigencia 24 h), por sucursal + punto de venta. */
readonly class Cufd
{
    public function __construct(
        public string $value,
        public int $sucursal,
        public int $puntoVenta,
        public CarbonImmutable $expiresAt,
        public ?string $codigoControl = null,
        public ?string $direccion = null,
    ) {}
}
```

- [ ] **Step 4: Interfaz**

`app/Fiscal/Siat/FiscalProvider.php`:
```php
<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;

/**
 * Contrato único con los servicios del SIN. Dos implementaciones: SimulatorFiscalProvider
 * (default, para desarrollar/testear sin el SIN) y SiatFiscalProvider (SOAP real).
 * enviarFactura/anularFactura las implementa F1b; se declaran acá para fijar el contrato.
 */
interface FiscalProvider
{
    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis;

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd;

    public function verificarComunicacion(string $recurso): bool;

    /** @return array<int,array{code:string,description:string}> */
    public function sincronizarCatalogo(string $tipo): array;

    // --- F1b (declaradas para el contrato; el simulador puede responder OK) ---
    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array;

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array;
}
```

- [ ] **Step 5: Simulador**

`app/Fiscal/Siat/SimulatorFiscalProvider.php`:
```php
<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Implementación determinista para desarrollo y tests. No toca la red. Puede simular
 * caída de comunicación con `$this->online = false`.
 */
class SimulatorFiscalProvider implements FiscalProvider
{
    public bool $online = true;

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        return new Cuis(
            value: 'SIM-CUIS-' . strtoupper(Str::random(10)),
            expiresAt: CarbonImmutable::now()->addDays(365),
        );
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        return new Cufd(
            value: 'SIM-CUFD-' . strtoupper(Str::random(16)),
            sucursal: $sucursal,
            puntoVenta: $puntoVenta,
            expiresAt: CarbonImmutable::now()->addDay(),
            codigoControl: strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(2)),
            direccion: 'Dirección de prueba',
        );
    }

    public function verificarComunicacion(string $recurso): bool
    {
        return $this->online;
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        // Muestras mínimas por tipo — suficientes para poblar el espejo en tests.
        return match ($tipo) {
            'metodo_pago' => [
                ['code' => '1', 'description' => 'Efectivo'],
                ['code' => '2', 'description' => 'Tarjeta'],
                ['code' => '7', 'description' => 'Transferencia bancaria'],
            ],
            'unidad' => [
                ['code' => '58', 'description' => 'Unidad (servicios)'],
                ['code' => '1', 'description' => 'Bolsa'],
            ],
            'tipo_documento' => [
                ['code' => '1', 'description' => 'CI'],
                ['code' => '5', 'description' => 'NIT'],
            ],
            'actividad' => [['code' => '620000', 'description' => 'Actividad de prueba']],
            'leyenda' => [['code' => '1', 'description' => 'Ley N° 453: leyenda de prueba']],
            default => [],
        };
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        return ['codigoRecepcion' => 'SIM-REC-' . strtoupper(Str::random(8)), 'estado' => 'RECIBIDA'];
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        return ['estado' => 'ANULADA'];
    }
}
```

- [ ] **Step 6: Service provider + binding**

`app/Providers/FiscalServiceProvider.php`:
```php
<?php

namespace App\Providers;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Fiscal\Siat\SiatFiscalProvider;
use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalProvider::class, function () {
            return Setting::get('fiscal_provider', 'simulator') === 'siat'
                ? $this->app->make(SiatFiscalProvider::class)
                : $this->app->make(SimulatorFiscalProvider::class);
        });
    }
}
```
Registrar en `bootstrap/providers.php` (agregar `App\Providers\FiscalServiceProvider::class,` a la lista).

NOTA: `SiatFiscalProvider` todavía no existe → el test `test_binding_switches_to_siat...` fallará hasta
crear al menos un esqueleto. Crear en este task un **esqueleto mínimo** de `SiatFiscalProvider` que
implemente la interfaz lanzando `\RuntimeException('SIAT real pendiente de ambiente piloto')` en cada
método (Task 7 lo completa como esqueleto SOAP documentado). Así el binding resuelve la clase.

- [ ] **Step 7: Correr — pasa** (`php artisan test --filter FiscalProviderBindingTest`)

- [ ] **Step 8: Commit**

```bash
git add app/Fiscal/Siat app/Providers/FiscalServiceProvider.php bootstrap/providers.php tests/Feature/Fiscal/Siat/FiscalProviderBindingTest.php
git commit -m "feat(fiscal): interfaz FiscalProvider + simulador + binding por setting"
```

---

## Task 2: Almacenamiento (migraciones + modelos + settings cifrados)

**Files:** 4 migraciones · Modelos `FiscalCuis`, `FiscalCufd`, `FiscalCatalogEntry`, `FiscalLog` · Modify `app/Models/Setting.php` · Test `tests/Feature/Fiscal/Siat/FiscalStorageTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Models\Fiscal\FiscalCatalogEntry;
use App\Models\Fiscal\FiscalCufd;
use App\Models\Fiscal\FiscalCuis;
use App\Models\Fiscal\FiscalLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_persist(): void
    {
        FiscalCuis::create(['value' => 'C1', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addYear()]);
        FiscalCufd::create(['value' => 'D1', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addDay()]);
        FiscalCatalogEntry::create(['catalog_type' => 'metodo_pago', 'code' => '1', 'description' => 'Efectivo', 'synced_at' => now()]);
        FiscalLog::create(['service' => 'obtenerCufd', 'environment' => 'piloto', 'request' => '{}', 'response' => '{}', 'success' => true]);

        $this->assertDatabaseCount('fiscal_cuis', 1);
        $this->assertDatabaseCount('fiscal_cufd', 1);
        $this->assertDatabaseCount('fiscal_catalog_entries', 1);
        $this->assertDatabaseCount('fiscal_logs', 1);
    }

    public function test_cufd_scope_valid_for(): void
    {
        FiscalCufd::create(['value' => 'OLD', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->subHour()]);
        $current = FiscalCufd::create(['value' => 'NEW', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addHour()]);

        $found = FiscalCufd::validFor(0, 0)->first();
        $this->assertSame($current->id, $found?->id);
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Migraciones** (aditivas)

`database/migrations/2026_07_20_180000_create_fiscal_cuis_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_cuis', function (Blueprint $table) {
            $table->id();
            $table->string('value');
            $table->unsignedInteger('sucursal')->default(0);
            $table->unsignedInteger('punto_venta')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['sucursal', 'punto_venta', 'expires_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_cuis'); }
};
```
`2026_07_20_180100_create_fiscal_cufd_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_cufd', function (Blueprint $table) {
            $table->id();
            $table->string('value');
            $table->unsignedInteger('sucursal')->default(0);
            $table->unsignedInteger('punto_venta')->default(0);
            $table->string('codigo_control')->nullable();
            $table->string('direccion')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['sucursal', 'punto_venta', 'expires_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_cufd'); }
};
```
`2026_07_20_180200_create_fiscal_catalog_entries_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_type')->index();
            $table->string('code');
            $table->string('description');
            $table->json('extra')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['catalog_type', 'code']);
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_catalog_entries'); }
};
```
`2026_07_20_180300_create_fiscal_logs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service')->index();
            $table->string('environment')->nullable();      // piloto|produccion
            $table->longText('request')->nullable();
            $table->longText('response')->nullable();
            $table->boolean('success')->default(false);
            $table->string('error_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_logs'); }
};
```

- [ ] **Step 4: Modelos** (en `app/Models/Fiscal/`)

`FiscalCuis` (`protected $table='fiscal_cuis';` — plural irregular, fijarlo explícito), fillable
`['value','sucursal','punto_venta','expires_at']`, cast `expires_at`=>'datetime'.
`FiscalCufd` (`$table='fiscal_cufd';`), fillable `['value','sucursal','punto_venta','codigo_control','direccion','expires_at']`,
cast `expires_at`=>'datetime', y scope:
```php
    public function scopeValidFor($q, int $sucursal, int $puntoVenta)
    {
        return $q->where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now())
            ->latest('expires_at');
    }
```
`FiscalCatalogEntry` fillable `['catalog_type','code','description','extra','synced_at']`, casts `extra`=>'array', `synced_at`=>'datetime'.
`FiscalLog` fillable `['service','environment','request','response','success','error_code']`, cast `success`=>'boolean'.

- [ ] **Step 5: Settings cifrados**

En `app/Models/Setting.php`, agregar a `ENCRYPTED_KEYS` las claves de credenciales del portal SIAT:
`'siat_api_token'`, `'siat_credential'` (y las que el adaptador real necesite). Los ajustes no sensibles
(NIT emisor, sucursal, PV, ambiente, `fiscal_provider`) van como settings normales, sin cifrar.

- [ ] **Step 6: Correr — pasa** (`php artisan test --filter FiscalStorageTest`)

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/Fiscal app/Models/Setting.php tests/Feature/Fiscal/Siat/FiscalStorageTest.php
git commit -m "feat(fiscal): almacenamiento CUIS/CUFD, catalogos espejo y logs"
```

---

## Task 3: `FiscalAuthority` (ciclo CUIS/CUFD)

**Files:** Create `app/Fiscal/Siat/FiscalAuthority.php` · Test `tests/Feature/Fiscal/Siat/FiscalAuthorityTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalAuthority;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Fiscal\FiscalCufd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_current_cufd_fetches_and_caches(): void
    {
        $authority = app(FiscalAuthority::class);

        $cufd = $authority->currentCufd(0, 0);
        $this->assertNotEmpty($cufd->value);
        $this->assertDatabaseCount('fiscal_cufd', 1);

        // Segunda llamada dentro de las 24h reusa, no crea otro.
        $authority->currentCufd(0, 0);
        $this->assertDatabaseCount('fiscal_cufd', 1);
    }

    public function test_expired_cufd_is_refetched(): void
    {
        FiscalCufd::create(['value' => 'OLD', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->subHour()]);

        $cufd = app(FiscalAuthority::class)->currentCufd(0, 0);

        $this->assertNotSame('OLD', $cufd->value);
        $this->assertDatabaseCount('fiscal_cufd', 2);
    }

    public function test_ensure_cuis_creates_when_missing(): void
    {
        app(FiscalAuthority::class)->ensureCuis(0, 0);
        $this->assertDatabaseCount('fiscal_cuis', 1);
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar**

`app/Fiscal/Siat/FiscalAuthority.php`:
```php
<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Models\Fiscal\FiscalCufd;
use App\Models\Fiscal\FiscalCuis;

/**
 * Orquesta el ciclo de códigos de autorización. F1b consume `currentCufd()` para emitir.
 * El CUFD vence a las 24h (no al cierre comercial) → se re-pide on-demand si venció.
 */
class FiscalAuthority
{
    public function __construct(private FiscalProvider $provider) {}

    public function currentCufd(int $sucursal, int $puntoVenta): Cufd
    {
        $stored = FiscalCufd::validFor($sucursal, $puntoVenta)->first();
        if ($stored) {
            return new Cufd(
                $stored->value, $stored->sucursal, $stored->punto_venta,
                \Carbon\CarbonImmutable::instance($stored->expires_at),
                $stored->codigo_control, $stored->direccion,
            );
        }

        $cufd = $this->provider->obtenerCufd($sucursal, $puntoVenta);
        FiscalCufd::create([
            'value' => $cufd->value,
            'sucursal' => $cufd->sucursal,
            'punto_venta' => $cufd->puntoVenta,
            'codigo_control' => $cufd->codigoControl,
            'direccion' => $cufd->direccion,
            'expires_at' => $cufd->expiresAt,
        ]);

        return $cufd;
    }

    /** Asegura un CUIS vigente (renueva si falta o está por vencer, umbral 5 días). */
    public function ensureCuis(int $sucursal = 0, int $puntoVenta = 0): void
    {
        $valid = FiscalCuis::where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now()->addDays(5))
            ->exists();

        if ($valid) {
            return;
        }

        $cuis = $this->provider->obtenerCuis($sucursal, $puntoVenta);
        FiscalCuis::create([
            'value' => $cuis->value,
            'sucursal' => $sucursal,
            'punto_venta' => $puntoVenta,
            'expires_at' => $cuis->expiresAt,
        ]);
    }

    /** True si el CUIS vence dentro de la ventana de aviso (para alertar). */
    public function cuisExpiringSoon(int $sucursal = 0, int $puntoVenta = 0): bool
    {
        return ! FiscalCuis::where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now()->addDays(5))
            ->exists();
    }
}
```

- [ ] **Step 4: Correr — pasa** (`php artisan test --filter FiscalAuthorityTest`)

- [ ] **Step 5: Commit**

```bash
git add app/Fiscal/Siat/FiscalAuthority.php tests/Feature/Fiscal/Siat/FiscalAuthorityTest.php
git commit -m "feat(fiscal): FiscalAuthority orquesta ciclo CUIS/CUFD"
```

---

## Task 4: Sincronización de catálogos

**Files:** Create `app/Fiscal/Siat/CatalogSync.php` · Test `tests/Feature/Fiscal/Siat/CatalogSyncTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\CatalogSync;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Fiscal\FiscalCatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_sync_populates_mirror(): void
    {
        app(CatalogSync::class)->sync('metodo_pago');

        $this->assertDatabaseHas('fiscal_catalog_entries', ['catalog_type' => 'metodo_pago', 'code' => '7']);
    }

    public function test_resync_updates_without_duplicating(): void
    {
        $sync = app(CatalogSync::class);
        $sync->sync('metodo_pago');
        $sync->sync('metodo_pago');

        $this->assertSame(3, FiscalCatalogEntry::where('catalog_type', 'metodo_pago')->count());
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar**

`app/Fiscal/Siat/CatalogSync.php`:
```php
<?php

namespace App\Fiscal\Siat;

use App\Models\Fiscal\FiscalCatalogEntry;

class CatalogSync
{
    /** Tipos que sincroniza el ciclo diario. */
    public const TYPES = ['actividad', 'producto_servicio', 'unidad', 'tipo_documento', 'metodo_pago', 'leyenda', 'mensaje'];

    public function __construct(private FiscalProvider $provider) {}

    public function sync(string $type): int
    {
        $entries = $this->provider->sincronizarCatalogo($type);
        $now = now();

        foreach ($entries as $entry) {
            FiscalCatalogEntry::updateOrCreate(
                ['catalog_type' => $type, 'code' => $entry['code']],
                ['description' => $entry['description'], 'synced_at' => $now],
            );
        }

        return count($entries);
    }

    public function syncAll(): void
    {
        foreach (self::TYPES as $type) {
            $this->sync($type);
        }
    }
}
```

- [ ] **Step 4: Correr — pasa**

- [ ] **Step 5: Commit**

```bash
git add app/Fiscal/Siat/CatalogSync.php tests/Feature/Fiscal/Siat/CatalogSyncTest.php
git commit -m "feat(fiscal): sincronizacion de catalogos del SIN a tabla espejo"
```

---

## Task 5: Verificación de comunicación + flag offline + trazabilidad

**Files:** Create `app/Fiscal/Siat/FiscalConnectivity.php` · Modify `SimulatorFiscalProvider` (registrar logs vía un decorator o dentro de `FiscalConnectivity`) · Test `tests/Feature/Fiscal/Siat/FiscalConnectivityTest.php`

Decisión de diseño: la trazabilidad (log de cada llamada) se implementa como un **decorator**
`LoggingFiscalProvider` que envuelve al provider real/simulador y registra en `fiscal_logs`, así ni el
simulador ni el adaptador real cargan con logging. El binding (Task 1) se ajusta para envolver.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalConnectivity;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalConnectivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_flag_set_when_comms_down(): void
    {
        $sim = new SimulatorFiscalProvider();
        $sim->online = false;
        $this->app->instance(FiscalProvider::class, $sim);

        $ok = app(FiscalConnectivity::class)->check('recepcionFactura');

        $this->assertFalse($ok);
        $this->assertSame('1', Setting::get('fiscal_offline'));
    }

    public function test_offline_flag_cleared_when_comms_ok(): void
    {
        Setting::set('fiscal_offline', '1');
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());

        $ok = app(FiscalConnectivity::class)->check('recepcionFactura');

        $this->assertTrue($ok);
        $this->assertSame('0', Setting::get('fiscal_offline'));
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar**

`app/Fiscal/Siat/FiscalConnectivity.php`:
```php
<?php

namespace App\Fiscal\Siat;

use App\Models\Setting;

/**
 * Verifica la comunicación con el SIN POR RECURSO (el doc insiste: existe por recurso,
 * no una vez global) y mantiene el flag `fiscal_offline`. El manejo de contingencia
 * (empaquetar y reenviar) es F2; acá solo se prende/apaga el flag.
 */
class FiscalConnectivity
{
    public function __construct(private FiscalProvider $provider) {}

    public function check(string $recurso): bool
    {
        $ok = $this->provider->verificarComunicacion($recurso);
        Setting::set('fiscal_offline', $ok ? '0' : '1');

        return $ok;
    }

    public function isOffline(): bool
    {
        return Setting::get('fiscal_offline', '0') === '1';
    }
}
```

`app/Fiscal/Siat/LoggingFiscalProvider.php` (decorator de trazabilidad):
```php
<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use App\Models\Fiscal\FiscalLog;
use App\Models\Setting;

/** Envuelve un FiscalProvider y registra cada llamada en fiscal_logs. */
class LoggingFiscalProvider implements FiscalProvider
{
    public function __construct(private FiscalProvider $inner) {}

    private function log(string $service, array $request, callable $call)
    {
        $env = Setting::get('siat_environment', 'piloto');
        try {
            $result = $call();
            FiscalLog::create([
                'service' => $service, 'environment' => $env,
                'request' => json_encode($request), 'response' => json_encode(['ok' => true]),
                'success' => true,
            ]);
            return $result;
        } catch (\Throwable $e) {
            FiscalLog::create([
                'service' => $service, 'environment' => $env,
                'request' => json_encode($request), 'response' => $e->getMessage(),
                'success' => false, 'error_code' => (string) $e->getCode(),
            ]);
            throw $e;
        }
    }

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        return $this->log('obtenerCuis', compact('sucursal', 'puntoVenta'), fn () => $this->inner->obtenerCuis($sucursal, $puntoVenta));
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        return $this->log('obtenerCufd', compact('sucursal', 'puntoVenta'), fn () => $this->inner->obtenerCufd($sucursal, $puntoVenta));
    }

    public function verificarComunicacion(string $recurso): bool
    {
        return $this->log('verificarComunicacion', compact('recurso'), fn () => $this->inner->verificarComunicacion($recurso));
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        return $this->log('sincronizarCatalogo', compact('tipo'), fn () => $this->inner->sincronizarCatalogo($tipo));
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        return $this->log('enviarFactura', $meta, fn () => $this->inner->enviarFactura($xmlFirmadoOComprimido, $meta));
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        return $this->log('anularFactura', compact('cuf', 'motivo'), fn () => $this->inner->anularFactura($cuf, $motivo, $meta));
    }
}
```

- [ ] **Step 4: Envolver en el binding**

En `FiscalServiceProvider::register`, envolver la implementación elegida con `LoggingFiscalProvider`:
```php
        $this->app->bind(FiscalProvider::class, function () {
            $inner = Setting::get('fiscal_provider', 'simulator') === 'siat'
                ? $this->app->make(SiatFiscalProvider::class)
                : $this->app->make(SimulatorFiscalProvider::class);

            return new LoggingFiscalProvider($inner);
        });
```
OJO tests: los tests que hacen `$this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider())`
inyectan un simulador SIN logging a propósito (para asertar comportamiento puro). Eso es correcto; el
logging se prueba aparte (un test que resuelve `app(FiscalProvider::class)` con el binding real y verifica
que se creó un `fiscal_log`). Agregar ese test.

- [ ] **Step 5: Correr — pasa** (`php artisan test --filter "FiscalConnectivityTest|FiscalProviderBindingTest"`)

- [ ] **Step 6: Commit**

```bash
git add app/Fiscal/Siat/FiscalConnectivity.php app/Fiscal/Siat/LoggingFiscalProvider.php app/Providers/FiscalServiceProvider.php tests/Feature/Fiscal/Siat/FiscalConnectivityTest.php
git commit -m "feat(fiscal): verificacion de comunicacion, flag offline y trazabilidad"
```

---

## Task 6: Job diario + scheduling + alerta

**Files:** Create `app/Console/Commands/FiscalDailyCycle.php` · Modify `routes/console.php` · Test `tests/Feature/Fiscal/Siat/FiscalDailyCycleTest.php`

READ FIRST: `routes/console.php` (cómo se agenda) y `app/Jobs/SendTelegramMessage.php` / `TelegramService` (cómo alertar).

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDailyCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_daily_cycle_ensures_cufd_catalogs_and_is_idempotent(): void
    {
        $this->artisan('fiscal:daily-cycle')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_cufd', 1);
        $this->assertDatabaseHas('fiscal_catalog_entries', ['catalog_type' => 'metodo_pago']);

        // Correrlo de nuevo el mismo día no duplica el CUFD (sigue vigente).
        $this->artisan('fiscal:daily-cycle')->assertSuccessful();
        $this->assertDatabaseCount('fiscal_cufd', 1);
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar el comando**

`app/Console/Commands/FiscalDailyCycle.php`:
```php
<?php

namespace App\Console\Commands;

use App\Fiscal\Siat\CatalogSync;
use App\Fiscal\Siat\FiscalAuthority;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo diario del SIN: asegura CUIS, obtiene el CUFD del día, sincroniza catálogos y hora.
 * Debe correr ANTES de la primera venta del día. Idempotente (si el CUFD sigue vigente, no
 * pide otro). Ante fallo o CUIS por vencer, alerta por Telegram.
 */
class FiscalDailyCycle extends Command
{
    protected $signature = 'fiscal:daily-cycle {--sucursal=0} {--pv=0}';
    protected $description = 'Ciclo diario de autorización del SIN (CUIS/CUFD/catálogos)';

    public function handle(FiscalAuthority $authority, CatalogSync $catalogs): int
    {
        $sucursal = (int) $this->option('sucursal');
        $pv = (int) $this->option('pv');

        try {
            $authority->ensureCuis($sucursal, $pv);
            $authority->currentCufd($sucursal, $pv); // obtiene y cachea si falta
            $catalogs->syncAll();

            if ($authority->cuisExpiringSoon($sucursal, $pv)) {
                $this->alert('El CUIS del SIN está por vencer. Renovar pronto.');
            }

            $this->info('Ciclo diario del SIN completado.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Fallo el ciclo diario del SIN', ['error' => $e->getMessage()]);
            $this->alert('Falló el ciclo diario del SIN: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /** Alerta por Telegram (patrón existente). Implementar según SendTelegramMessage/TelegramService. */
    private function alert(string $message): void
    {
        // Seguir el patrón del repo: dispatch(new SendTelegramMessage(chatId, texto)) o
        // app(TelegramService::class)->notifyAdmin($message). Leer el job/servicio antes.
    }
}
```
NOTA implementador: cablear `alert()` al mecanismo real de Telegram del repo (leer
`app/Jobs/SendTelegramMessage.php` y cómo se obtiene el chat admin — probablemente
`Setting::get('telegram_admin_chat_id')`). Si no hay chat configurado, degradar a `Log::warning` sin romper.

- [ ] **Step 4: Agendar en `routes/console.php`**

Agregar (siguiendo el estilo del archivo):
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('fiscal:daily-cycle')->dailyAt('05:00')->timezone(config('app.timezone'));
```

- [ ] **Step 5: Correr — pasa** (`php artisan test --filter FiscalDailyCycleTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/FiscalDailyCycle.php routes/console.php tests/Feature/Fiscal/Siat/FiscalDailyCycleTest.php
git commit -m "feat(fiscal): comando y agenda del ciclo diario del SIN con alerta"
```

---

## Task 7: Esqueleto `SiatFiscalProvider` (SOAP real, no verificado)

**Files:** Modify `app/Fiscal/Siat/SiatFiscalProvider.php` (creado mínimo en Task 1) · Settings de configuración fiscal (documentar en un panel más adelante, no en F1a) · Test `tests/Feature/Fiscal/Siat/SiatFiscalProviderShapeTest.php`

Objetivo: dejar el adaptador real ESCRITO desde los XSD conocidos, con el cliente SOAP y la forma de
cada request, pero **claramente marcado como no verificado en vivo** hasta tener el ambiente piloto.
No se conecta a ninguna red en tests.

- [ ] **Step 1: Test de forma (falla)**

```php
<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\SiatFiscalProvider;
use Tests\TestCase;

class SiatFiscalProviderShapeTest extends TestCase
{
    public function test_implements_interface_and_reports_pending_environment(): void
    {
        $provider = new SiatFiscalProvider();

        $this->assertInstanceOf(\App\Fiscal\Siat\FiscalProvider::class, $provider);

        // Sin ambiente piloto configurado, cada llamada debe fallar EXPLÍCITAMENTE
        // (no silenciosamente devolver datos falsos).
        $this->expectException(\RuntimeException::class);
        $provider->obtenerCufd(0, 0);
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar el esqueleto**

`app/Fiscal/Siat/SiatFiscalProvider.php` — implementa la interfaz. Cada método:
1. Arma el request según el XSD del servicio correspondiente (documentar la forma en comentarios).
2. Chequea que haya WSDL/credenciales configurados (`Setting::get('siat_wsdl_...')`, `siat_api_token`);
   si no, lanza `\RuntimeException('SIAT real requiere ambiente piloto: configurar WSDL y credenciales')`.
3. (Cuando haya piloto) usa `new \SoapClient($wsdl, [...])` para invocar el servicio y mapear la
   respuesta a los DTOs `Cuis`/`Cufd` o al array de catálogo.

Estructura mínima (el cuerpo SOAP real se completa con los WSDL del piloto):
```php
<?php

namespace App\Fiscal\Siat;

use App\Fiscal\Siat\Dtos\Cufd;
use App\Fiscal\Siat\Dtos\Cuis;
use App\Models\Setting;

/**
 * Adaptador real contra los servicios SOAP del SIN (modalidad Computarizada en Línea).
 *
 * ⚠️ NO VERIFICADO EN VIVO: escrito desde los XSD/documentación conocidos, pero sin
 * ejercitar contra el ambiente piloto (credenciales + WSDL pendientes). Hasta configurar
 * el piloto, cada método lanza RuntimeException en vez de devolver datos falsos —
 * el desarrollo/testeo de F1 corre contra SimulatorFiscalProvider. Al habilitar el piloto:
 * completar los cuerpos SOAP y setear `fiscal_provider=siat`.
 */
class SiatFiscalProvider implements FiscalProvider
{
    private function requireEnvironment(): void
    {
        if (! Setting::get('siat_api_token') || ! Setting::get('siat_wsdl_codigos')) {
            throw new \RuntimeException('SIAT real requiere ambiente piloto: configurar WSDL y credenciales.');
        }
    }

    public function obtenerCuis(int $sucursal = 0, int $puntoVenta = 0): Cuis
    {
        $this->requireEnvironment();
        // @todo piloto: SoapClient(wsdl codigos)->cuis(...) → mapear a Cuis
        throw new \RuntimeException('obtenerCuis SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function obtenerCufd(int $sucursal, int $puntoVenta): Cufd
    {
        $this->requireEnvironment();
        throw new \RuntimeException('obtenerCufd SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function verificarComunicacion(string $recurso): bool
    {
        $this->requireEnvironment();
        throw new \RuntimeException('verificarComunicacion SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function sincronizarCatalogo(string $tipo): array
    {
        $this->requireEnvironment();
        throw new \RuntimeException('sincronizarCatalogo SOAP pendiente de implementar con el WSDL del piloto.');
    }

    public function enviarFactura(string $xmlFirmadoOComprimido, array $meta = []): array
    {
        $this->requireEnvironment();
        throw new \RuntimeException('enviarFactura SOAP pendiente (F1b + piloto).');
    }

    public function anularFactura(string $cuf, string $motivo, array $meta = []): array
    {
        $this->requireEnvironment();
        throw new \RuntimeException('anularFactura SOAP pendiente (F1b + piloto).');
    }
}
```
NOTA: el test setea que SIN configuración lanza RuntimeException — por eso `requireEnvironment()` corre
primero. El comentario del `@todo` documenta cada servicio SOAP a completar. Esto mantiene F1a honesto:
la interfaz está completa, el simulador maneja todo, y el adaptador real está listo para completar sin
inventar comportamiento.

- [ ] **Step 4: Correr — pasa** (`php artisan test --filter SiatFiscalProviderShapeTest`)

- [ ] **Step 5: Commit**

```bash
git add app/Fiscal/Siat/SiatFiscalProvider.php tests/Feature/Fiscal/Siat/SiatFiscalProviderShapeTest.php
git commit -m "feat(fiscal): esqueleto SiatFiscalProvider (SOAP real, pendiente de piloto)"
```

---

## Cierre

- [ ] **Suite completa** — `php artisan test`. Sin regresiones (F1a es todo código nuevo aislado; no toca ventas ni nada existente).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva, NUNCA `:fresh`). El scheduler del ciclo diario requiere el cron de Laravel corriendo en el VPS (`* * * * * php artisan schedule:run`). `fiscal_provider` queda en `simulator` hasta tener piloto.
- [ ] **Follow-ups anotados:** completar `SiatFiscalProvider` con los WSDL del piloto; conectar `sin_code`/`siatCode()` de F0 contra los catálogos espejo (validación); F1b (emisión) consume `FiscalAuthority::currentCufd`.

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)

- **Cobertura de spec:** R1 (T1), R2 (T1), R3 (T7), R4 (T1+T5 binding), R5 (T2), R6 (T3), R7 (T6), R8 (T2+T4), R9 (T5), R10 (T2+T5 decorator), R11 (T2 settings + T7). ✔
- **Sin placeholders de código:** todo inline y completo. El `alert()` del comando (T6) y los cuerpos SOAP (T7) son intencionalmente stubs **documentados con instrucción de cableado** — el de Telegram porque depende del patrón real del repo (leer antes), el SOAP porque no hay WSDL hasta el piloto (honesto, no inventado). ✔
- **Consistencia:** `FiscalProvider` (6 métodos) implementado idéntico en Simulator/Siat/Logging. DTOs `Cuis`/`Cufd` usados igual en provider, authority, storage. `FiscalCufd::validFor` usado en authority + test. Tablas `fiscal_*` y modelos `App\Models\Fiscal\*` consistentes. ✔
- **Riesgo anotado:** el simulador default hace que TODO sea testeable sin el SIN; el adaptador real lanza excepción explícita en vez de datos falsos (T7) — no se puede confundir "no configurado" con "funcionando". El decorator de logging se saltea en tests que inyectan el simulador puro (documentado en T5). ✔
