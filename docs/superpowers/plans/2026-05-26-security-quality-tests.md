# Security, Quality & Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolver vulnerabilidades de seguridad (API keys en texto plano, autorización incompleta), bugs potenciales (FIELD() MySQL-only, invoice overflow, asientos de compra incorrectos, cache stale) y agregar cobertura de tests críticos para un sistema financiero.

**Architecture:** 6 tareas independientes organizadas en 2 grupos de ejecución paralela. Grupo A: seguridad + soft deletes. Grupo B: refactors + tests. Cada tarea produce commits atómicos verificables. TDD donde aplique.

**Tech Stack:** Laravel 12, PHP 8.2, Livewire 3, PHPUnit 11, SQLite (tests), MySQL (producción), Spatie Permissions.

---

## FILE MAP

### Grupo A — Nuevos archivos:
- `database/migrations/2026_05_26_100000_encrypt_sensitive_settings.php` — migra valores plaintext a cifrado
- `database/migrations/2026_05_26_200001_add_soft_deletes_to_products.php`
- `database/migrations/2026_05_26_200002_add_soft_deletes_to_customers.php`
- `database/migrations/2026_05_26_200003_add_soft_deletes_to_suppliers.php`
- `database/migrations/2026_05_26_200004_add_soft_deletes_to_sales.php`
- `database/migrations/2026_05_26_200005_add_soft_deletes_to_purchases.php`
- `tests/Feature/Settings/SettingEncryptionTest.php`
- `tests/Feature/Authorization/SaleAuthorizationTest.php`

### Grupo A — Archivos modificados:
- `app/Models/Setting.php` — agregar cifrado transparente
- `app/Http/Controllers/SalesController.php` — guard en `complete()`
- `app/Livewire/Roles/RolesIndex.php:62` — FIELD() → CASE WHEN
- `app/Models/Product.php`, `Customer.php`, `Supplier.php`, `Sale.php`, `Purchase.php` — `use SoftDeletes`

### Grupo B — Nuevos archivos:
- `app/Services/Accounting/AccountingPeriodAutoCloser.php`
- `database/migrations/2026_05_26_300001_add_payment_method_to_purchases.php`
- `tests/Feature/Sales/SaleServiceTest.php`
- `tests/Feature/Stock/StockServiceTest.php`
- `tests/Feature/Finance/SaleAccountingTest.php`
- `tests/Feature/Products/ProductSearchCacheTest.php`

### Grupo B — Archivos modificados:
- `app/Models/AccountingPeriod.php` — delegar auto-close al service
- `app/Models/Purchase.php` — agregar `payment_method` a fillable/casts
- `app/Services/Accounting/PurchaseAccountingService.php` — rama crédito
- `app/Services/SaleService.php:415` — str_pad 4 → 6
- `app/Http/Controllers/Api/ProductController.php:35` — TTL 60 → 15

---

## TAREA 1 — Cifrado de API Keys en Settings (Agente A1)

**Archivos:**
- Modificar: `app/Models/Setting.php`
- Crear: `database/migrations/2026_05_26_100000_encrypt_sensitive_settings.php`
- Crear: `tests/Feature/Settings/SettingEncryptionTest.php`

---

- [ ] **Paso 1.1: Escribir el test que falla primero**

Crear `tests/Feature/Settings/SettingEncryptionTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_setting_is_stored_encrypted_in_database(): void
    {
        Setting::set('anthropic_api_key', 'sk-ant-real-key-12345');

        $raw = DB::table('settings')->where('key', 'anthropic_api_key')->value('value');

        $this->assertNotEquals('sk-ant-real-key-12345', $raw, 'API key should NOT be stored as plaintext');
        $this->assertNotEmpty($raw);
        // Valor cifrado de Laravel siempre empieza con "eyJ" (base64 JSON)
        $this->assertStringStartsWith('eyJ', $raw);
    }

    public function test_sensitive_setting_is_returned_decrypted_via_get(): void
    {
        Setting::set('anthropic_api_key', 'sk-ant-real-key-12345');
        Cache::flush();

        $value = Setting::get('anthropic_api_key');

        $this->assertEquals('sk-ant-real-key-12345', $value);
    }

    public function test_non_sensitive_setting_is_stored_plaintext(): void
    {
        Setting::set('store_name', 'Mi Tienda');

        $raw = DB::table('settings')->where('key', 'store_name')->value('value');

        $this->assertEquals('Mi Tienda', $raw, 'Non-sensitive settings should remain plaintext');
    }

    public function test_telegram_token_is_encrypted(): void
    {
        Setting::set('telegram_bot_token', '1234567890:ABCDEFGabcdefg_token');
        Cache::flush();

        $raw = DB::table('settings')->where('key', 'telegram_bot_token')->value('value');
        $this->assertNotEquals('1234567890:ABCDEFGabcdefg_token', $raw);

        $decrypted = Setting::get('telegram_bot_token');
        $this->assertEquals('1234567890:ABCDEFGabcdefg_token', $decrypted);
    }

    public function test_openai_key_is_encrypted(): void
    {
        Setting::set('openai_api_key', 'sk-openai-key-abcdef');
        Cache::flush();

        $raw = DB::table('settings')->where('key', 'openai_api_key')->value('value');
        $this->assertNotEquals('sk-openai-key-abcdef', $raw);

        $this->assertEquals('sk-openai-key-abcdef', Setting::get('openai_api_key'));
    }

    public function test_legacy_plaintext_value_is_readable_after_encryption(): void
    {
        // Simular valor legacy: guardar directo en DB sin cifrar
        DB::table('settings')->updateOrInsert(
            ['key' => 'anthropic_api_key'],
            ['value' => 'sk-legacy-plaintext', 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::flush();

        // Setting::get() debe intentar decrypt, fallar silenciosamente y retornar plaintext
        $value = Setting::get('anthropic_api_key');
        $this->assertEquals('sk-legacy-plaintext', $value);
    }

    public function test_null_sensitive_setting_returns_null(): void
    {
        $value = Setting::get('anthropic_api_key');
        $this->assertNull($value);
    }

    public function test_cached_value_is_decrypted(): void
    {
        Setting::set('anthropic_api_key', 'sk-cached-key');
        // Primera llamada llena el cache con el valor descifrado
        $first = Setting::get('anthropic_api_key');
        // Segunda llamada lee del cache
        $second = Setting::get('anthropic_api_key');

        $this->assertEquals('sk-cached-key', $first);
        $this->assertEquals('sk-cached-key', $second);
    }
}
```

- [ ] **Paso 1.2: Ejecutar test para verificar que falla**

```bash
cd d:/PROGRAMAS/laragon/www/inventory-management-system
php artisan test tests/Feature/Settings/SettingEncryptionTest.php --stop-on-first-failure
```

Resultado esperado: FAIL en `test_sensitive_setting_is_stored_encrypted_in_database` — "Failed asserting that 'sk-ant-real-key-12345' does not equal 'sk-ant-real-key-12345'" (porque aún no hay cifrado).

- [ ] **Paso 1.3: Implementar cifrado transparente en Setting model**

Reemplazar el contenido de `app/Models/Setting.php` con:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Keys cuyo valor se almacena cifrado en DB.
     * Cache guarda siempre el valor ya descifrado.
     */
    private const ENCRYPTED_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    /**
     * Get a setting value by key.
     * Sensitive keys are decrypted transparently.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever("settings.{$key}", function () use ($key, $default) {
            $setting = self::find($key);
            if (! $setting) {
                return $default;
            }

            $value = $setting->value;

            if (in_array($key, self::ENCRYPTED_KEYS, true) && $value !== null) {
                $value = self::safeDecrypt($value);
            }

            return $value ?? $default;
        });
    }

    /**
     * Set a setting value by key.
     * Sensitive keys are encrypted before saving.
     */
    public static function set(string $key, ?string $value): void
    {
        $toStore = $value;

        if ($value !== null && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $toStore = Crypt::encryptString($value);
        }

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $toStore]
        );

        Cache::forget("settings.{$key}");
    }

    /**
     * Intenta descifrar. Si el valor no está cifrado (legacy plaintext),
     * retorna el valor original en lugar de lanzar excepción.
     * Esto permite lectura backward-compatible durante migraciones.
     */
    private static function safeDecrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Valor legacy plaintext — retornar tal cual.
            return $value;
        }
    }
}
```

- [ ] **Paso 1.4: Ejecutar tests para verificar que pasan**

```bash
php artisan test tests/Feature/Settings/SettingEncryptionTest.php
```

Resultado esperado: 8 tests, 8 passed.

- [ ] **Paso 1.5: Crear migración para cifrar valores existentes en producción**

Crear `database/migrations/2026_05_26_100000_encrypt_sensitive_settings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    private const SENSITIVE_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    public function up(): void
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null) {
                continue;
            }

            // Verificar si ya está cifrado (no re-cifrar en caso de correr migración dos veces)
            try {
                Crypt::decryptString($setting->value);
                // Si llegamos aquí, ya está cifrado — no hacer nada.
                continue;
            } catch (DecryptException) {
                // No está cifrado — cifrarlo ahora.
            }

            DB::table('settings')
                ->where('key', $key)
                ->update([
                    'value' => Crypt::encryptString($setting->value),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Descifrar de vuelta a plaintext (rollback)
        foreach (self::SENSITIVE_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null) {
                continue;
            }

            try {
                $plain = Crypt::decryptString($setting->value);
                DB::table('settings')
                    ->where('key', $key)
                    ->update(['value' => $plain, 'updated_at' => now()]);
            } catch (DecryptException) {
                // Ya es plaintext, nada que hacer.
            }
        }
    }
};
```

- [ ] **Paso 1.6: Ejecutar migración y verificar**

```bash
php artisan migrate
```

Verificar que los valores en DB están cifrados (solo si ya existen — en entorno fresco no habrá nada):
```bash
php artisan tinker --execute="var_dump(DB::table('settings')->where('key', 'anthropic_api_key')->value('value'));"
```

Debe mostrar una cadena larga base64 (no la API key real) o NULL si no existe.

- [ ] **Paso 1.7: Ejecutar suite completa de tests**

```bash
php artisan test
```

Resultado esperado: todos los tests existentes pasan + 8 nuevos.

- [ ] **Paso 1.8: Commit**

```bash
git add app/Models/Setting.php \
        database/migrations/2026_05_26_100000_encrypt_sensitive_settings.php \
        tests/Feature/Settings/SettingEncryptionTest.php
git commit -m "security: cifrado AES-256 transparente para API keys en Settings

- Setting::set() cifra anthropic_api_key, openai_api_key,
  telegram_bot_token, telegram_webhook_secret antes de guardar en DB
- Setting::get() descifra automáticamente; cache guarda valor descifrado
- safeDecrypt() es backward-compatible con valores legacy plaintext
- Migración re-cifra valores existentes en producción
- 8 tests en SettingEncryptionTest

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## TAREA 2 — Autorización Sales + FIELD() Fix (Agente A2)

**Archivos:**
- Modificar: `app/Http/Controllers/SalesController.php`
- Modificar: `app/Livewire/Roles/RolesIndex.php`
- Crear: `tests/Feature/Authorization/SaleAuthorizationTest.php`

---

- [ ] **Paso 2.1: Escribir tests de autorización que fallan**

Crear `tests/Feature/Authorization/SaleAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\Authorization;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);
    }

    private function makePendingSale(User $creator): Sale
    {
        return Sale::create([
            'invoice_number' => 'INV.260526.0001',
            'created_by' => $creator->id,
            'sale_date' => now(),
            'status' => SaleStatus::PENDING,
            'payment_method' => 'cash',
            'subtotal' => 10000,
            'total_discount' => 0,
            'total' => 10000,
            'cash_received' => 0,
            'change' => 0,
            'global_discount' => 0,
            'source' => 'pos',
        ]);
    }

    public function test_staff_cannot_complete_another_users_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($other)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::PENDING, $sale->fresh()->status);
    }

    public function test_staff_can_complete_own_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($owner)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_admin_can_complete_any_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($admin)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_staff_cannot_cancel_completed_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);
        $sale->update(['status' => SaleStatus::COMPLETED]);

        $response = $this->actingAs($owner)
            ->delete(route('sales.destroy', $sale));

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_staff_can_cancel_own_pending_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($owner)
            ->delete(route('sales.destroy', $sale));

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_staff_cannot_cancel_another_users_pending_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($other)
            ->delete(route('sales.destroy', $sale));

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::PENDING, $sale->fresh()->status);
    }

    public function test_admin_can_cancel_any_completed_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $sale  = $this->makePendingSale($owner);
        $sale->update(['status' => SaleStatus::COMPLETED]);

        $response = $this->actingAs($admin)
            ->delete(route('sales.destroy', $sale));

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
    }
}
```

- [ ] **Paso 2.2: Ejecutar para verificar que fallan**

```bash
php artisan test tests/Feature/Authorization/SaleAuthorizationTest.php --stop-on-first-failure
```

Resultado esperado: FAIL en `test_staff_cannot_complete_another_users_sale` — el staff puede completar sin restricción.

- [ ] **Paso 2.3: Agregar guard en `SalesController::complete`**

En `app/Http/Controllers/SalesController.php`, método `complete` (línea 108), reemplazar:

```php
    public function complete(Request $request, Sale $sale, SaleService $saleService)
    {
        try {
            $paymentData = $request->only(['cash_received', 'change']);

            $saleService->completeSale($sale, $paymentData);

            return redirect()->back()->with('success', 'Venta marcada como completada.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
```

Con:

```php
    public function complete(Request $request, Sale $sale, SaleService $saleService)
    {
        // Solo el creador o un admin puede completar la venta.
        if (auth()->id() !== $sale->created_by && ! auth()->user()->isAdmin()) {
            abort(403, 'Solo el vendedor asignado o un administrador puede completar esta venta.');
        }

        try {
            $paymentData = $request->only(['cash_received', 'change']);

            $saleService->completeSale($sale, $paymentData);

            return redirect()->back()->with('success', 'Venta marcada como completada.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
```

- [ ] **Paso 2.4: Ejecutar tests de autorización**

```bash
php artisan test tests/Feature/Authorization/SaleAuthorizationTest.php
```

Resultado esperado: 7 tests, 7 passed.

- [ ] **Paso 2.5: Auditar guards en acciones Livewire de Settings y AccountingPeriods**

Verificar que las acciones write en los siguientes componentes tienen `abort_if(!auth()->user()?->isAdmin(), 403)`:

```bash
grep -n "abort_if\|isAdmin" d:/PROGRAMAS/laragon/www/inventory-management-system/app/Livewire/AccountingPeriods/AccountingPeriodTable.php
grep -n "abort_if\|isAdmin" d:/PROGRAMAS/laragon/www/inventory-management-system/app/Livewire/Settings/SettingGroups.php | head -10
```

- `AccountingPeriodTable::closePeriod()` → ya tiene `abort_if(!auth()->user()->isAdmin(), 403)` ✓
- `AccountingPeriodTable::reopenPeriod()` → verificar y agregar si falta
- `SettingGroups`: cada método público write (`saveGroup`, `uploadLogo`, etc.) → verificar y agregar si falta

Para cualquier método write sin guard, agregar al inicio del método:
```php
abort_if(! auth()->user()?->isAdmin(), 403);
```

- [ ] **Paso 2.7: Fix FIELD() MySQL-only en RolesIndex**

En `app/Livewire/Roles/RolesIndex.php`, método `roles()`, reemplazar:

```php
        return Role::query()
            ->withCount('permissions', 'users')
            ->orderByRaw("FIELD(name, 'developer', 'admin', 'staff') DESC")
            ->orderBy('name')
            ->get();
```

Con:

```php
        return Role::query()
            ->withCount('permissions', 'users')
            ->orderByRaw("CASE name WHEN 'developer' THEN 1 WHEN 'admin' THEN 2 WHEN 'staff' THEN 3 ELSE 4 END ASC")
            ->orderBy('name')
            ->get();
```

- [ ] **Paso 2.8: Verificar que los tests existentes siguen pasando**

```bash
php artisan test
```

Resultado esperado: todos pasan.

- [ ] **Paso 2.9: Commit**

```bash
git add app/Http/Controllers/SalesController.php \
        app/Livewire/Roles/RolesIndex.php \
        tests/Feature/Authorization/SaleAuthorizationTest.php
git commit -m "security: guard en completeSale + FIELD() portabilidad SQLite/MySQL

- SalesController::complete() ahora solo permite al creador o admin
- Reemplaza FIELD() MySQL-only con CASE WHEN portable en RolesIndex
- 7 tests de autorización de ventas

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## TAREA 3 — Soft Deletes en 5 Modelos (Agente A3)

**Archivos:**
- Modificar: `app/Models/Product.php`, `Customer.php`, `Supplier.php`, `Sale.php`, `Purchase.php`
- Crear: 5 migraciones `add_soft_deletes_to_*.php`

---

- [ ] **Paso 3.1: Crear las 5 migraciones de soft deletes**

`database/migrations/2026_05_26_200001_add_soft_deletes_to_products.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }
    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

`database/migrations/2026_05_26_200002_add_soft_deletes_to_customers.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }
    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

`database/migrations/2026_05_26_200003_add_soft_deletes_to_suppliers.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }
    public function down(): void {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

`database/migrations/2026_05_26_200004_add_soft_deletes_to_sales.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }
    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

`database/migrations/2026_05_26_200005_add_soft_deletes_to_purchases.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchases', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }
    public function down(): void {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

- [ ] **Paso 3.2: Ejecutar migraciones**

```bash
php artisan migrate
```

Resultado esperado: 5 migraciones ejecutadas, sin errores.

- [ ] **Paso 3.3: Agregar SoftDeletes al modelo Product**

En `app/Models/Product.php`, agregar el import y el trait:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;
    // ... resto igual
```

- [ ] **Paso 3.4: Agregar SoftDeletes al modelo Customer**

En `app/Models/Customer.php`, agregar:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;
    // ... resto igual
```

- [ ] **Paso 3.5: Agregar SoftDeletes al modelo Supplier**

En `app/Models/Supplier.php`, agregar:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;
    // ... resto igual
```

- [ ] **Paso 3.6: Agregar SoftDeletes al modelo Sale**

En `app/Models/Sale.php`, agregar:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;
    // ... resto igual
```

- [ ] **Paso 3.7: Agregar SoftDeletes al modelo Purchase**

En `app/Models/Purchase.php`, agregar:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use HasFactory, SoftDeletes;
    // ... resto igual
```

- [ ] **Paso 3.8: Verificar que los tests pasan**

```bash
php artisan test
```

Resultado esperado: todos los tests pasan. Si alguno falla por soft deletes (ej: un test borra un registro y luego lo busca), revisar que usa `withTrashed()` o `forceDelete()`.

- [ ] **Paso 3.9: Verificar manualmente que soft delete funciona**

```bash
php artisan tinker --execute="
\$p = \App\Models\Product::factory()->create();
\$id = \$p->id;
\$p->delete();
\$found = \App\Models\Product::find(\$id);
\$withTrashed = \App\Models\Product::withTrashed()->find(\$id);
echo 'find() retorna: ' . (\$found ? 'registro (ERROR)' : 'null (CORRECTO)') . PHP_EOL;
echo 'withTrashed() retorna: ' . (\$withTrashed ? 'registro (CORRECTO)' : 'null (ERROR)') . PHP_EOL;
echo 'deleted_at: ' . \$withTrashed->deleted_at . PHP_EOL;
"
```

Resultado esperado:
```
find() retorna: null (CORRECTO)
withTrashed() retorna: registro (CORRECTO)
deleted_at: 2026-05-26 ...
```

- [ ] **Paso 3.10: Commit**

```bash
git add app/Models/Product.php app/Models/Customer.php app/Models/Supplier.php \
        app/Models/Sale.php app/Models/Purchase.php \
        database/migrations/2026_05_26_200001_add_soft_deletes_to_products.php \
        database/migrations/2026_05_26_200002_add_soft_deletes_to_customers.php \
        database/migrations/2026_05_26_200003_add_soft_deletes_to_suppliers.php \
        database/migrations/2026_05_26_200004_add_soft_deletes_to_sales.php \
        database/migrations/2026_05_26_200005_add_soft_deletes_to_purchases.php
git commit -m "feat: soft deletes en Product, Customer, Supplier, Sale, Purchase

- Registros eliminados quedan en DB con deleted_at timestamp
- Queries normales los excluyen automáticamente (Eloquent global scope)
- Usar withTrashed() para recuperar en vistas admin si se necesita
- 5 migraciones reversibles

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## TAREA 4 — AccountingPeriodAutoCloser Refactor (Agente B1)

> ⚠️ Ejecutar DESPUÉS de que Grupo A esté mergeado.

**Archivos:**
- Crear: `app/Services/Accounting/AccountingPeriodAutoCloser.php`
- Modificar: `app/Models/AccountingPeriod.php`

---

- [ ] **Paso 4.1: Crear el service AccountingPeriodAutoCloser**

Crear `app/Services/Accounting/AccountingPeriodAutoCloser.php`:

```php
<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Maneja el cierre automático de periodos contables vencidos.
 *
 * Extraído de AccountingPeriod::resolveOpenForDate() para eliminar
 * side-effects dentro de operaciones de lectura del modelo.
 *
 * Responsabilidad única: dado un periodo vencido y una fecha de referencia,
 * cerrar el periodo y crear el siguiente (o extender si auto-create=false).
 */
class AccountingPeriodAutoCloser
{
    /**
     * Dado un periodo vencido, cerrarlo y retornar el periodo que debe usarse
     * para la fecha dada. Si auto_create=true, crea uno nuevo; si no, extiende.
     */
    public function handleExpired(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $autoCreate = Setting::get('auto_create_next_period', '1') === '1';

        if ($autoCreate) {
            return $this->closeAndCreateNext($expiredOpen, $date);
        }

        return $this->extend($expiredOpen, $date);
    }

    private function closeAndCreateNext(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $expiredOpen->update([
            'status'    => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $newPeriod = AccountingPeriod::autoCreateNext($expiredOpen);

        // Si la fecha cae más allá del fin del nuevo periodo, extender.
        if ($newPeriod->end_date->lt($date)) {
            $newPeriod->update(['end_date' => $date]);
            $newPeriod->refresh();
        }

        Log::warning("Auto-cierre de '{$expiredOpen->name}' y auto-creación de '{$newPeriod->name}' para cubrir fecha {$date}.", [
            'closed_period_id' => $expiredOpen->id,
            'new_period_id'    => $newPeriod->id,
            'sale_date'        => $date,
        ]);

        return $newPeriod;
    }

    private function extend(AccountingPeriod $expiredOpen, string $date): AccountingPeriod
    {
        $originalEnd = $expiredOpen->end_date->toDateString();
        $expiredOpen->update(['end_date' => $date]);
        $expiredOpen->refresh();

        Log::warning("Periodo '{$expiredOpen->name}' extendido automáticamente hasta {$date} (auto-creación desactivada).", [
            'period_id'    => $expiredOpen->id,
            'original_end' => $originalEnd,
            'extended_to'  => $date,
        ]);

        return $expiredOpen;
    }
}
```

- [ ] **Paso 4.2: Actualizar AccountingPeriod::resolveOpenForDate para usar el service**

En `app/Models/AccountingPeriod.php`, en el método `resolveOpenForDate`, reemplazar el bloque del fallback (`if ($expiredOpen) { ... }`) con:

```php
        if ($expiredOpen) {
            /** @var AccountingPeriodAutoCloser $autoCloser */
            $autoCloser = app(\App\Services\Accounting\AccountingPeriodAutoCloser::class);
            return $autoCloser->handleExpired($expiredOpen, $date);
        }
```

**El resultado final del método `resolveOpenForDate` debe quedar así:**

```php
    public static function resolveOpenForDate(string $date): static
    {
        // Caso normal: periodo abierto que cubre exactamente la fecha
        /** @var static|null $period */
        $period = static::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where('status', AccountingPeriodStatus::Open->value)
            ->orderBy('start_date')
            ->first();

        if ($period) {
            return $period;
        }

        // Fallback: periodo abierto vencido (end_date < fecha solicitada).
        /** @var static|null $expiredOpen */
        $expiredOpen = static::query()
            ->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('end_date', '<', $date)
            ->orderByDesc('end_date')
            ->first();

        if ($expiredOpen) {
            /** @var \App\Services\Accounting\AccountingPeriodAutoCloser $autoCloser */
            $autoCloser = app(\App\Services\Accounting\AccountingPeriodAutoCloser::class);
            return $autoCloser->handleExpired($expiredOpen, $date);
        }

        throw new RuntimeException("No existe un periodo contable abierto para la fecha {$date}.");
    }
```

- [ ] **Paso 4.3: Verificar que los tests de finance siguen pasando**

```bash
php artisan test tests/Feature/Finance/
```

Resultado esperado: todos los tests de Finance pasan.

- [ ] **Paso 4.4: Ejecutar suite completa**

```bash
php artisan test
```

- [ ] **Paso 4.5: Commit**

```bash
git add app/Services/Accounting/AccountingPeriodAutoCloser.php \
        app/Models/AccountingPeriod.php
git commit -m "refactor: extraer auto-close de AccountingPeriod a AccountingPeriodAutoCloser

- Elimina side-effects en resolveOpenForDate() (modelo solo resuelve)
- AccountingPeriodAutoCloser tiene responsabilidad única
- Lógica de cierre+creación y extensión separadas en métodos privados
- Inyectable via app() para testabilidad

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## TAREA 5 — Invoice 6-digit + Purchase Accounting + Cache TTL (Agente B2)

> ⚠️ Ejecutar DESPUÉS de que Grupo A esté mergeado.

**Archivos:**
- Modificar: `app/Services/SaleService.php` (línea 415)
- Modificar: `app/Services/Accounting/PurchaseAccountingService.php`
- Modificar: `app/Models/Purchase.php`
- Crear: `database/migrations/2026_05_26_300001_add_payment_method_to_purchases.php`
- Modificar: `app/Http/Controllers/Api/ProductController.php` (línea 35)

---

- [ ] **Paso 5.1: Fix invoice number — 4 → 6 dígitos**

En `app/Services/SaleService.php`, línea 415, reemplazar:

```php
            $lastNumber = $latest ? (int) substr($latest->invoice_number, -4) : 0;
            $candidate = $prefix . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
```

Con:

```php
            $lastNumber = $latest ? (int) substr($latest->invoice_number, -6) : 0;
            $candidate = $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
```

- [ ] **Paso 5.2: Verificar que los tests existentes pasan**

```bash
php artisan test
```

- [ ] **Paso 5.3: Crear migración para payment_method en purchases**

Crear `database/migrations/2026_05_26_300001_add_payment_method_to_purchases.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('cash')->after('status')->index();
        });

        // Backfill: compras con status 'paid' → cash, resto → pending
        DB::table('purchases')->where('status', 'paid')->update(['payment_method' => 'cash']);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
```

- [ ] **Paso 5.4: Ejecutar migración**

```bash
php artisan migrate
```

- [ ] **Paso 5.5: Actualizar modelo Purchase para incluir payment_method**

En `app/Models/Purchase.php`, agregar `'payment_method'` a `$fillable`:

```php
    protected $fillable = [
        'invoice_number',
        'supplier_id',
        'purchase_date',
        'due_date',
        'total',
        'status',
        'payment_method',
        'notes',
        'proof_image',
        'created_by',
    ];
```

- [ ] **Paso 5.6: Actualizar PurchaseAccountingService para ramas cash/credit**

Reemplazar `app/Services/Accounting/PurchaseAccountingService.php` con:

```php
<?php

namespace App\Services\Accounting;

use App\Models\Purchase;
use App\Models\Setting;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\AccountingPeriod;
use App\Enums\JournalEntryStatus;
use Illuminate\Support\Carbon;
use RuntimeException;

class PurchaseAccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService
    ) {
    }

    /**
     * Contabiliza una compra según su método de pago.
     * - cash: Débito Inventario / Crédito Caja
     * - credit/cualquier otro: Débito Inventario / Crédito Cuentas por Pagar
     */
    public function postPurchase(Purchase $purchase, int $userId): ?JournalEntry
    {
        $exists = JournalEntry::query()
            ->where('source_type', Purchase::class)
            ->where('source_id', $purchase->id)
            ->where('status', JournalEntryStatus::Posted)
            ->whereNull('reversed_entry_id')
            ->exists();

        if ($exists) {
            return null;
        }

        $entryDate = Carbon::parse($purchase->purchase_date)->toDateString();
        $period = $this->resolveOpenPeriod($entryDate);

        $inventoryAccount = $this->findPostingAccount(Setting::get('accounting_inventory_code', '1.1.04'));
        $total = (int) $purchase->total;

        // Seleccionar cuenta de contrapartida según método de pago
        $paymentMethod = $purchase->payment_method ?? 'cash';
        if ($paymentMethod === 'cash') {
            $creditAccount = $this->findPostingAccount(Setting::get('accounting_purchase_cash_code', '1.1.01'));
            $creditDescription = 'Salida de caja por compra ' . $purchase->invoice_number;
        } else {
            // credit, transfer, o cualquier otro → cuentas por pagar
            $creditAccount = $this->findPostingAccount(Setting::get('accounting_purchase_payable_code', '2.1.01'));
            $creditDescription = 'Cuenta por pagar por compra ' . $purchase->invoice_number;
        }

        $lines = [
            [
                'chart_of_account_id' => $inventoryAccount->id,
                'description' => 'Ingreso de inventario por compra ' . $purchase->invoice_number,
                'debit_amount' => $total,
                'credit_amount' => 0,
                'reference' => $purchase->invoice_number,
            ],
            [
                'chart_of_account_id' => $creditAccount->id,
                'description' => $creditDescription,
                'debit_amount' => 0,
                'credit_amount' => $total,
                'reference' => $purchase->invoice_number,
            ],
        ];

        return $this->journalEntryService->createPostedEntry([
            'entry_date' => $entryDate,
            'accounting_period_id' => $period->id,
            'description' => 'Asiento automático de compra ' . $purchase->invoice_number,
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'created_by' => $userId,
            'posted_by' => $userId,
        ], $lines);
    }

    /**
     * Alias backward-compatible para callers que aún usan el nombre anterior.
     */
    public function postPaidPurchase(Purchase $purchase, int $userId): ?JournalEntry
    {
        return $this->postPurchase($purchase, $userId);
    }

    protected function resolveOpenPeriod(string $entryDate): AccountingPeriod
    {
        return AccountingPeriod::resolveOpenForDate($entryDate);
    }

    protected function findPostingAccount(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('allows_posting', true)
            ->first();

        if (!$account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }

        return $account;
    }
}
```

- [ ] **Paso 5.7: Reducir TTL de cache de búsqueda de productos**

En `app/Http/Controllers/Api/ProductController.php`, línea 35, reemplazar:

```php
        $products = Cache::remember($cacheKey, 60, function () use ($query, $limit) {
```

Con:

```php
        $products = Cache::remember($cacheKey, 15, function () use ($query, $limit) {
```

- [ ] **Paso 5.8: Ejecutar todos los tests**

```bash
php artisan test
```

Resultado esperado: todos los tests pasan.

- [ ] **Paso 5.9: Commit**

```bash
git add app/Services/SaleService.php \
        app/Services/Accounting/PurchaseAccountingService.php \
        app/Models/Purchase.php \
        database/migrations/2026_05_26_300001_add_payment_method_to_purchases.php \
        app/Http/Controllers/Api/ProductController.php
git commit -m "fix: invoice 6-digit + purchase accounting ramas cash/credit + cache TTL 15s

- Invoice number usa str_pad 6 dígitos (previene desborde a >9999/día)
- PurchaseAccountingService::postPurchase() selecciona cuenta según payment_method:
  cash → accounting_purchase_cash_code (1.1.01)
  credit/otro → accounting_purchase_payable_code (2.1.01)
- Alias postPaidPurchase() backward-compatible
- Migración agrega payment_method a purchases con default 'cash'
- ProductController cache TTL 60s → 15s (reduce ventana de sobre-venta)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## TAREA 6 — Tests Críticos: SaleService, StockService, SaleAccounting (Agente B3)

> ⚠️ Ejecutar DESPUÉS de que Grupo A esté mergeado y Tarea 5 completa (invoice 6-digit cambia el formato).

**Archivos:**
- Crear: `tests/Feature/Sales/SaleServiceTest.php`
- Crear: `tests/Feature/Stock/StockServiceTest.php`
- Crear: `tests/Feature/Finance/SaleAccountingTest.php`
- Crear: `tests/Feature/Products/ProductSearchCacheTest.php`

---

- [ ] **Paso 6.1: Crear StockServiceTest**

Crear `tests/Feature/Stock/StockServiceTest.php`:

```php
<?php

namespace Tests\Feature\Stock;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Services\StockService;
use Database\Seeders\AccountingPeriodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;
    private Location $location;
    private Location $location2;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockService::class);

        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'code' => 'ALM-01']);
        $this->location = Location::create(['name' => 'Estante A', 'code' => 'A', 'warehouse_id' => $warehouse->id]);
        $this->location2 = Location::create(['name' => 'Estante B', 'code' => 'B', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create(['quantity' => 0]);
    }

    public function test_pickFifo_returns_location_with_enough_stock(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 5);

        $this->assertNotNull($stock);
        $this->assertEquals($this->location->id, $stock->location_id);
    }

    public function test_pickFifo_returns_null_when_no_single_location_has_enough(): void
    {
        // Dos ubicaciones con 5 cada una, pero necesitamos 10 en una sola
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 5]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 10);

        $this->assertNull($stock);
    }

    public function test_pickFifo_returns_first_location_by_id_when_multiple_qualify(): void
    {
        // location (id menor) tiene stock suficiente, location2 también
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 20]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 20]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 10);

        $this->assertEquals($this->location->id, $stock->location_id); // FIFO = menor id primero
    }

    public function test_decrementAt_reduces_stock_and_syncs_product_quantity(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        $this->service->syncProductQuantity($this->product->id);

        $this->service->decrementAt($this->product->id, $this->location->id, 3);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals(7, $stock->quantity);
        $this->assertEquals(7, $this->product->fresh()->quantity);
    }

    public function test_decrementAt_throws_when_insufficient(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/insuficiente|insufficient/i');

        $this->service->decrementAt($this->product->id, $this->location->id, 5);
    }

    public function test_decrementAt_throws_when_no_stock_row_exists(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no encontrado|not found/i');

        $this->service->decrementAt($this->product->id, $this->location->id, 1);
    }

    public function test_incrementAt_creates_new_row_when_none_exists(): void
    {
        $this->service->incrementAt($this->product->id, $this->location->id, 5);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(5, $stock->quantity);
        $this->assertEquals(5, $this->product->fresh()->quantity);
    }

    public function test_incrementAt_adds_to_existing_stock(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);

        $this->service->incrementAt($this->product->id, $this->location->id, 5);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals(15, $stock->quantity);
        $this->assertEquals(15, $this->product->fresh()->quantity);
    }

    public function test_syncProductQuantity_matches_sum_of_all_locations(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 7]);

        $this->service->syncProductQuantity($this->product->id);

        $this->assertEquals(17, $this->product->fresh()->quantity);
    }

    public function test_totalStock_returns_sum_across_locations(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 5]);

        $total = $this->service->totalStock($this->product->id);

        $this->assertEquals(15, $total);
    }
}
```

- [ ] **Paso 6.2: Ejecutar StockServiceTest**

```bash
php artisan test tests/Feature/Stock/StockServiceTest.php
```

Resultado esperado: 9 tests, 9 passed.

- [ ] **Paso 6.3: Crear SaleServiceTest**

Crear `tests/Feature/Sales/SaleServiceTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\DTOs\SaleData;
use App\DTOs\SaleItemData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $service;
    private User $seller;
    private Product $product;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->service = app(SaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 10000,
            'purchase_price' => 6000,
            'quantity'       => 0,
        ]);

        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $this->location->id,
            'quantity'    => 20,
        ]);
        $this->product->update(['quantity' => 20]);
    }

    private function makeSaleData(array $overrides = []): SaleData
    {
        return SaleData::fromArray(array_merge([
            'customer_id'     => null,
            'buyer_name'      => null,
            'buyer_phone'     => null,
            'created_by'      => $this->seller->id,
            'sale_date'       => now()->toDateTimeString(),
            'status'          => SaleStatus::COMPLETED->value,
            'payment_method'  => PaymentMethod::CASH->value,
            'source'          => 'pos',
            'notes'           => null,
            'cash_received'   => 20000,
            'change'          => 0,
            'global_discount' => 0,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 10000,
                    'discount'   => 0,
                ],
            ],
        ], $overrides));
    }

    public function test_createSale_deducts_stock_and_creates_journal_entry(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());

        $this->assertEquals(SaleStatus::COMPLETED, $sale->status);
        $this->assertEquals(20000, $sale->total);
        $this->assertEquals(18, $this->product->fresh()->quantity); // 20 - 2
        $this->assertDatabaseHas('journal_entries', ['source_id' => $sale->id, 'status' => 'posted']);
    }

    public function test_createSale_throws_when_insufficient_stock(): void
    {
        $this->expectException(SaleException::class);
        $this->expectExceptionMessageMatches('/stock insuficiente|insufficient/i');

        $this->service->createSale($this->makeSaleData([
            'items' => [['product_id' => $this->product->id, 'quantity' => 25, 'unit_price' => 10000, 'discount' => 0]],
        ]));
    }

    public function test_createSale_throws_when_item_discount_exceeds_unit_price(): void
    {
        $this->expectException(SaleException::class);
        $this->expectExceptionMessageMatches('/discount|descuento/i');

        $this->service->createSale($this->makeSaleData([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10000, 'discount' => 15000]],
        ]));
    }

    public function test_createSale_throws_when_global_discount_exceeds_subtotal(): void
    {
        $this->expectException(SaleException::class);
        $this->expectExceptionMessageMatches('/discount|descuento/i');

        $this->service->createSale($this->makeSaleData([
            'global_discount' => 99999999,
            'cash_received'   => 99999999,
        ]));
    }

    public function test_cancelSale_restores_stock_and_reverses_journal_entry(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());
        $this->assertEquals(18, $this->product->fresh()->quantity);

        $this->service->cancelSale($sale, 'Test cancellation');

        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
        $this->assertEquals(20, $this->product->fresh()->quantity); // stock restaurado
        $this->assertDatabaseHas('journal_entries', [
            'source_id' => $sale->id,
            'status'    => 'reversed',
        ]);
    }

    public function test_cancelSale_throws_when_already_cancelled(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());
        $this->service->cancelSale($sale);

        $this->expectException(SaleException::class);

        $this->service->cancelSale($sale->fresh());
    }

    public function test_completeSale_throws_when_cash_received_insufficient(): void
    {
        $pendingData = $this->makeSaleData([
            'status'       => SaleStatus::PENDING->value,
            'cash_received' => 0,
        ]);
        $sale = $this->service->createSale($pendingData);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessageMatches('/pago|payment|insuficiente|insufficient/i');

        $this->service->completeSale($sale, ['cash_received' => 1000]); // necesita 20000
    }

    public function test_completeSale_creates_journal_entry_when_completing_pending_sale(): void
    {
        $pendingData = $this->makeSaleData([
            'status'        => SaleStatus::PENDING->value,
            'cash_received' => 0,
        ]);
        $sale = $this->service->createSale($pendingData);
        $this->assertDatabaseMissing('journal_entries', ['source_id' => $sale->id]);

        $this->service->completeSale($sale, ['cash_received' => 20000]);

        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
        $this->assertDatabaseHas('journal_entries', ['source_id' => $sale->id, 'status' => 'posted']);
    }

    public function test_createSale_does_not_duplicate_journal_entry_if_called_twice(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());

        // Forzar segunda llamada a postCompletedSale
        app(\App\Services\Accounting\SaleAccountingService::class)
            ->postCompletedSale($sale->fresh(['items']), $this->seller->id);

        $count = \App\Models\JournalEntry::where('source_id', $sale->id)
            ->where('source_type', \App\Models\Sale::class)
            ->where('status', 'posted')
            ->whereNull('reversed_entry_id')
            ->count();

        $this->assertEquals(1, $count, 'No debe duplicar el asiento contable');
    }
}
```

- [ ] **Paso 6.4: Confirmar DTOs disponibles**

`App\DTOs\SaleData` y `App\DTOs\SaleItemData` ya existen. `SaleData::fromArray()` acepta exactamente el shape del test (items como arrays `['product_id', 'quantity', 'unit_price', 'discount']`). Nota: `unit_price` en el DTO es ignorado por `SaleService` — usa `$product->selling_price`. Esto es correcto y esperado.

```bash
php artisan tinker --execute="echo class_exists(\App\DTOs\SaleData::class) ? 'OK' : 'MISSING';"
```

Resultado esperado: `OK`

- [ ] **Paso 6.5: Ejecutar SaleServiceTest**

```bash
php artisan test tests/Feature/Sales/SaleServiceTest.php
```

Resultado esperado: 8 tests, 8 passed.

- [ ] **Paso 6.6: Crear SaleAccountingTest**

Crear `tests/Feature/Finance/SaleAccountingTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\SaleAccountingService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAccountingTest extends TestCase
{
    use RefreshDatabase;

    private SaleAccountingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->service = app(SaleAccountingService::class);
        $this->user = User::factory()->admin()->create();
    }

    private function makeSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'invoice_number'  => 'INV.260526.' . str_pad(rand(1, 9999), 6, '0', STR_PAD_LEFT),
            'created_by'      => $this->user->id,
            'sale_date'       => now(),
            'status'          => SaleStatus::COMPLETED,
            'payment_method'  => PaymentMethod::CASH,
            'source'          => 'pos',
            'subtotal'        => 10000,
            'total_discount'  => 0,
            'total'           => 10000,
            'cash_received'   => 10000,
            'change'          => 0,
            'global_discount' => 0,
        ], $overrides));
    }

    public function test_postCompletedSale_creates_balanced_journal_entry(): void
    {
        $sale = $this->makeSale();

        $entry = $this->service->postCompletedSale($sale, $this->user->id);

        $this->assertNotNull($entry);
        $this->assertEquals('posted', $entry->status->value);

        $debitTotal  = $entry->lines->sum('debit_amount');
        $creditTotal = $entry->lines->sum('credit_amount');
        $this->assertEquals($debitTotal, $creditTotal, 'Asiento debe cuadrar');
        $this->assertEquals(10000, $debitTotal);
    }

    public function test_postCompletedSale_is_idempotent(): void
    {
        $sale = $this->makeSale();

        $entry1 = $this->service->postCompletedSale($sale, $this->user->id);
        $entry2 = $this->service->postCompletedSale($sale, $this->user->id); // segunda llamada

        $this->assertNotNull($entry1);
        $this->assertNull($entry2, 'Segunda llamada debe retornar null (no duplicar)');

        $count = JournalEntry::where('source_id', $sale->id)
            ->where('source_type', Sale::class)
            ->where('status', 'posted')
            ->whereNull('reversed_entry_id')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_reverseSaleEntry_creates_reversal_and_marks_original_reversed(): void
    {
        $sale  = $this->makeSale();
        $entry = $this->service->postCompletedSale($sale, $this->user->id);

        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id, 'Test cancelación');

        $this->assertNotNull($reversal);
        $this->assertEquals('posted', $reversal->status->value);
        $this->assertEquals($entry->id, $reversal->reversed_entry_id);
        $this->assertEquals('reversed', $entry->fresh()->status->value);
    }

    public function test_reverseSaleEntry_returns_null_when_no_posted_entry(): void
    {
        $sale = $this->makeSale(['status' => SaleStatus::PENDING]);

        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id);

        $this->assertNull($reversal);
    }

    public function test_postCompletedSale_reversal_is_balanced(): void
    {
        $sale    = $this->makeSale();
        $entry   = $this->service->postCompletedSale($sale, $this->user->id);
        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id);

        $revDebit  = $reversal->lines->sum('debit_amount');
        $revCredit = $reversal->lines->sum('credit_amount');

        $this->assertEquals($revDebit, $revCredit, 'Reverso también debe cuadrar');
        // Los débitos del reverso deben igualar los créditos del original
        $this->assertEquals($entry->lines->sum('credit_amount'), $revDebit);
    }
}
```

- [ ] **Paso 6.7: Crear ProductSearchCacheTest**

Crear `tests/Feature/Products/ProductSearchCacheTest.php`:

```php
<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductSearchCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_inactive_products_not_returned_in_search(): void
    {
        $active   = Product::factory()->create(['name' => 'Producto Activo', 'is_active' => true, 'quantity' => 10]);
        $inactive = Product::factory()->create(['name' => 'Producto Inactivo', 'is_active' => false, 'quantity' => 10]);

        $user = User::factory()->staff()->create();

        $response = $this->actingAs($user)
            ->postJson(route('ajax.products.search'), ['q' => 'Producto', 'limit' => 50]);

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertContains($active->id, $ids->all());
        $this->assertNotContains($inactive->id, $ids->all());
    }

    public function test_cache_key_is_different_for_different_queries(): void
    {
        Product::factory()->create(['name' => 'Zapato Rojo', 'is_active' => true, 'quantity' => 5]);

        $user = User::factory()->staff()->create();

        $this->actingAs($user)->postJson(route('ajax.products.search'), ['q' => 'Zapato']);
        $this->actingAs($user)->postJson(route('ajax.products.search'), ['q' => 'Camisa']);

        // Ambas queries deben generar cache keys distintas — si no, segunda query
        // podría retornar resultados de la primera.
        $key1 = 'products_search_v2_' . md5('Zapato|50');
        $key2 = 'products_search_v2_' . md5('Camisa|50');
        $this->assertNotEquals($key1, $key2);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->postJson(route('ajax.products.search'), ['q' => 'test']);
        $response->assertUnauthorized();
    }
}
```

- [ ] **Paso 6.8: Ejecutar todos los tests nuevos**

```bash
php artisan test tests/Feature/Finance/SaleAccountingTest.php \
               tests/Feature/Products/ProductSearchCacheTest.php
```

Resultado esperado: todos pasan.

- [ ] **Paso 6.9: Ejecutar suite completa**

```bash
php artisan test
```

Resultado esperado: todos los tests (existentes + nuevos) pasan.

- [ ] **Paso 6.10: Commit**

```bash
git add tests/Feature/Sales/SaleServiceTest.php \
        tests/Feature/Stock/StockServiceTest.php \
        tests/Feature/Finance/SaleAccountingTest.php \
        tests/Feature/Products/ProductSearchCacheTest.php
git commit -m "test: cobertura crítica SaleService, StockService, SaleAccounting, ProductSearch

- SaleServiceTest: 8 tests (crear, cancelar, completar, stock, GL)
- StockServiceTest: 9 tests (FIFO, decrement, increment, sync)
- SaleAccountingTest: 5 tests (balance, idempotencia, reversal)
- ProductSearchCacheTest: 3 tests (auth, inactive filter, cache keys)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## VERIFICACIÓN FINAL (Coordinador QA)

- [ ] **QA.1: Ejecutar suite completa desde cero**

```bash
php artisan migrate:fresh --seed
php artisan test
```

Resultado esperado: 0 failures, 0 errors.

- [ ] **QA.2: Verificar cifrado en DB**

```bash
php artisan tinker --execute="
\App\Models\Setting::set('anthropic_api_key', 'sk-test-123');
\$raw = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'anthropic_api_key')->value('value');
echo 'Raw en DB: ' . \$raw . PHP_EOL;
echo 'Via get(): ' . \App\Models\Setting::get('anthropic_api_key') . PHP_EOL;
"
```

Resultado esperado:
```
Raw en DB: eyJpd... (cadena larga cifrada)
Via get(): sk-test-123
```

- [ ] **QA.3: Verificar soft delete**

```bash
php artisan tinker --execute="
\$p = \App\Models\Product::factory()->create();
echo 'ID: ' . \$p->id . PHP_EOL;
\$p->delete();
echo 'find(): ' . (\App\Models\Product::find(\$p->id) ? 'EXISTE (ERROR)' : 'null (OK)') . PHP_EOL;
echo 'withTrashed(): ' . (\App\Models\Product::withTrashed()->find(\$p->id) ? 'EXISTE (OK)' : 'null (ERROR)') . PHP_EOL;
"
```

- [ ] **QA.4: Verificar invoice number format**

```bash
php artisan tinker --execute="
\$method = new \ReflectionMethod(\App\Services\SaleService::class, 'generateInvoiceNumber');
\$method->setAccessible(true);
\$svc = app(\App\Services\SaleService::class);
\$n = \$method->invoke(\$svc);
echo 'Invoice: ' . \$n . PHP_EOL;
echo 'Largo de sufijo: ' . strlen(explode('.', \$n)[2]) . ' dígitos' . PHP_EOL;
"
```

Resultado esperado: `Invoice: INV.260526.000001` (6 dígitos).

- [ ] **QA.5: Verificar rollback de migraciones**

```bash
php artisan migrate:rollback --step=10
php artisan migrate
php artisan test
```

Resultado esperado: todo vuelve al estado correcto sin errores.
