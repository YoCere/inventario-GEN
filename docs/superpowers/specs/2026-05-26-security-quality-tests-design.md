# Sistema de Mejoras: Seguridad, Calidad y Tests
**Fecha:** 2026-05-26  
**Alcance:** Opción C — Completo (Seguridad + Bugs + Tests)  
**Coordinador:** Claude (QA/control de calidad)  
**Ejecutores:** Agentes paralelos

---

## Contexto

Sistema POS + contabilidad en Laravel 12 + Livewire. Encontradas vulnerabilidades de seguridad, bugs potenciales y cobertura de tests insuficiente para un sistema financiero.

---

## BLOQUE 1 — Seguridad

### S1: Cifrado de API Keys en Base de Datos

**Problema:** `anthropic_api_key`, `openai_api_key`, `telegram_bot_token`, `telegram_webhook_secret` se guardan en texto plano en la tabla `settings`. Cualquier acceso a la DB expone todas las claves.

**Solución:** Usar `Crypt::encryptString()` / `Crypt::decryptString()` nativo de Laravel.

**Implementación:**
- Agregar `private const ENCRYPTED_KEYS` en `Setting` model con la lista de keys sensibles
- `Setting::set()`: si la key está en la lista → cifrar antes de `updateOrCreate`
- `Setting::get()`: si la key está en la lista → descifrar el valor recuperado (con try/catch por si viene sin cifrar — backward compat)
- `Setting::setRaw()` / `Setting::getRaw()` para obtener el valor cifrado (para tests)
- Migración de datos: seeder/migration que lee los valores actuales y los re-guarda cifrados
- Cache: guardar el valor ya descifrado (comportamiento actual no cambia para callers)
- Test: `SettingEncryptionTest` — verifica que el valor en DB es ilegible, que `get()` retorna el valor original, que el cache retorna correcto

**Archivos a modificar:**
- `app/Models/Setting.php`
- Nueva migración: `database/migrations/2026_05_26_100000_encrypt_sensitive_settings.php`
- Nuevo test: `tests/Feature/Settings/SettingEncryptionTest.php`

---

### S2: Autorización en `completeSale`

**Problema:** `SalesController::complete` no verifica quién puede completar. Cualquier usuario autenticado puede completar ventas de otros usuarios.

**Solución:**
```php
// Solo el creador de la venta o un admin puede completarla
abort_if(
    auth()->id() !== $sale->created_by && !auth()->user()->isAdmin(),
    403,
    'Solo el vendedor o un administrador puede completar esta venta.'
);
```

**Archivos a modificar:**
- `app/Http/Controllers/SalesController.php` (método `complete`)
- Test: agregar caso en `AuthorizationTest`

---

### S3: Autorización en acciones Livewire (Settings / AccountingPeriods)

**Problema:** `abort_if` solo en `mount()`. Si Livewire hace un request al componente después del montaje, las acciones write no re-verifican.

**Solución:** Repetir `abort_if(!auth()->user()?->isAdmin(), 403)` al inicio de cada método write público en:
- `AccountingPeriodSettings`: `updatedAutoCreate()`, `updatedPeriodType()` — YA tienen el check ✓
- `AccountingPeriodTable`: acciones `closeperiod`, `reopenperiod` → verificar que tengan guard
- `SettingGroups`: acciones de guardado → ya tiene en `mount()`, verificar acciones write

**Acción:** Auditar y agregar donde falte.

**Archivos a modificar:**
- `app/Livewire/AccountingPeriods/AccountingPeriodTable.php`
- `app/Livewire/Settings/SettingGroups.php` (verificar todas las actions write)

---

### S4: FIELD() MySQL-only en RolesIndex

**Problema:** `orderByRaw("FIELD(name, 'developer', 'admin', 'staff') DESC")` es MySQL-specific. Rompe con SQLite (env dev/test).

**Solución:** Reemplazar con `CASE WHEN` que funciona en ambos:
```sql
ORDER BY CASE name 
  WHEN 'developer' THEN 1 
  WHEN 'admin' THEN 2 
  WHEN 'staff' THEN 3 
  ELSE 4 END ASC
```

**Archivos a modificar:**
- `app/Livewire/Roles/RolesIndex.php:62`

---

## BLOQUE 2 — Bugs

### B1: Soft Deletes en Modelos Críticos

**Modelos afectados:** `Product`, `Customer`, `Supplier`, `Sale`, `Purchase`

**NO incluir:** `JournalEntry`, `AccountingPeriod`, `ChartOfAccount` — tienen estados propios.

**Implementación:**
1. Agregar `use SoftDeletes` en cada modelo
2. Agregar `$table->softDeletes()` en migraciones nuevas (no modificar las existentes)
3. Verificar que relaciones con `restrictOnDelete` sigan funcionando (soft delete no dispara FK)
4. Agregar scope `withTrashed()` en admin views donde sea necesario ver eliminados
5. **Cuidado:** `Sale` ya tiene `SaleStatus::CANCELLED` — el soft delete es para eliminación física, no cancelación. Añadir guard: solo se puede soft-delete una venta ya cancelada.

**Nuevas migraciones:**
```
2026_05_26_200001_add_soft_deletes_to_products.php
2026_05_26_200002_add_soft_deletes_to_customers.php
2026_05_26_200003_add_soft_deletes_to_suppliers.php
2026_05_26_200004_add_soft_deletes_to_sales.php
2026_05_26_200005_add_soft_deletes_to_purchases.php
```

---

### B2: Side Effects en `resolveOpenForDate` (auto-cierre de periodos)

**Problema:** El auto-cierre de periodos ocurre dentro del modelo como efecto secundario de resolución. Si falla a mitad, deja estado inconsistente.

**Solución:** Extraer auto-cierre a `AccountingPeriodAutoCloser` service:
```php
class AccountingPeriodAutoCloser {
    public function closeExpiredAndCreateNext(AccountingPeriod $expired, string $date): AccountingPeriod;
}
```

El modelo `resolveOpenForDate` llama al service, no contiene la lógica.

**Archivos:**
- Nuevo: `app/Services/Accounting/AccountingPeriodAutoCloser.php`
- Modificar: `app/Models/AccountingPeriod.php` (delegar a service vía `app()`)

---

### B3: Desborde de Número de Factura (> 9999/día)

**Problema:** `str_pad($last+1, 4, '0', STR_PAD_LEFT)` con máx 9999. A 10000 el número tiene 5 dígitos, el número anterior ya es `INV.YYMMDD.9999`, el siguiente sería `INV.YYMMDD.10000` — funciona pero rompe el formato visual.

**Solución:** Cambiar a 6 dígitos: `str_pad(..., 6, ...)`. Agnóstico de datos existentes (los anteriores de 4 dígitos siguen siendo únicos).

**Archivo:** `app/Services/SaleService.php` método `generateInvoiceNumber()`

---

### B4: Compras a Crédito sin Asiento Correcto

**Problema:** `postPaidPurchase` siempre debita contra cuenta de caja, ignorando que compras pueden ser a crédito (deuda a proveedor).

**Solución:**
1. Verificar si `Purchase` tiene `payment_method`. Si no, agregar campo.
2. Si `payment_method == 'credit'` → crédito contra `accounting_purchase_payable_code` (nueva setting, default `2.1.01`)
3. Renombrar método a `postPurchase` (backward compat: mantener alias `postPaidPurchase`)

**Archivos:**
- `app/Services/Accounting/PurchaseAccountingService.php`
- Nueva setting seed: agregar `accounting_purchase_payable_code` = `2.1.01`
- Si no existe `payment_method` en purchases: nueva migración

---

### B5: Cache de Búsqueda sin Invalidación por Stock

**Problema:** Cache de 60s en `ProductController::search`. Después de decrementar stock, el search sigue retornando la cantidad anterior por hasta 60s → posible sobre-venta.

**Solución:**
1. Reducir TTL a 15s
2. En `StockService::decrementAt` y `incrementAt`: invalidar cache de búsqueda del producto afectado usando el mismo patrón de key (`products_search_v2_*` + flush de keys que contengan el product id)
3. Alternativa más simple: usar `Cache::tags(['products'])` si el driver lo soporta. Si es `database` (default), usar flush por prefijo.
4. **Decisión final:** TTL = 15s (simple, sin riesgo de bugs en invalidación). En entorno de alta concurrencia evaluar tags.

**Archivos:**
- `app/Http/Controllers/Api/ProductController.php` (TTL 60 → 15)

---

## BLOQUE 3 — Tests

### Suite 1: `SaleServiceTest`
```
tests/Feature/Sales/SaleServiceTest.php
```
**Tests:**
- `test_createSale_deducts_stock_and_creates_journal_entry` — happy path completo
- `test_createSale_throws_when_insufficient_stock` — stock < cantidad
- `test_createSale_throws_when_item_discount_exceeds_unit_price`
- `test_createSale_throws_when_global_discount_exceeds_subtotal`
- `test_cancelSale_restores_stock_and_reverses_journal_entry` — venta completada
- `test_cancelSale_throws_when_already_cancelled`
- `test_completeSale_throws_when_cash_received_insufficient`
- `test_completeSale_is_idempotent_for_accounting_entry` — no duplica asiento

### Suite 2: `StockServiceTest`
```
tests/Feature/Stock/StockServiceTest.php
```
**Tests:**
- `test_pickFifo_returns_location_with_enough_stock`
- `test_pickFifo_returns_null_when_no_single_location_has_enough`
- `test_decrementAt_reduces_stock_and_syncs_product_quantity`
- `test_decrementAt_throws_when_insufficient`
- `test_incrementAt_creates_new_row_when_none_exists`
- `test_incrementAt_adds_to_existing_stock`
- `test_syncProductQuantity_matches_sum_of_locations`

### Suite 3: `SaleAccountingTest`
```
tests/Feature/Finance/SaleAccountingTest.php
```
**Tests:**
- `test_postCompletedSale_creates_balanced_journal_entry`
- `test_postCompletedSale_is_idempotent` — no duplica si se llama dos veces
- `test_postCompletedSale_includes_cogs_lines_when_cost_price_set`
- `test_reverseSaleEntry_creates_reversal_and_marks_original_reversed`
- `test_reverseSaleEntry_returns_null_when_no_posted_entry`

### Suite 4: `AuthorizationTest`
```
tests/Feature/Authorization/SaleAuthorizationTest.php
```
**Tests:**
- `test_staff_cannot_complete_another_users_sale`
- `test_staff_can_complete_own_sale`
- `test_admin_can_complete_any_sale`
- `test_staff_cannot_cancel_completed_sale`
- `test_staff_can_cancel_own_pending_sale`
- `test_staff_cannot_access_admin_routes`

### Suite 5: `SettingEncryptionTest`
```
tests/Feature/Settings/SettingEncryptionTest.php
```
**Tests:**
- `test_sensitive_setting_is_stored_encrypted_in_database`
- `test_sensitive_setting_is_returned_decrypted_via_get`
- `test_non_sensitive_setting_is_stored_plaintext`
- `test_encrypted_setting_survives_cache_clear`
- `test_legacy_plaintext_value_is_readable_after_migration`

### Suite 6: `StockCacheTest` (simplificado)
```
tests/Feature/Products/ProductSearchCacheTest.php
```
**Tests:**
- `test_search_cache_expires_after_15_seconds`
- `test_inactive_products_not_returned_in_search`

---

## Estrategia de Ejecución (Agentes Paralelos)

### Grupo A (independientes entre sí):
- **Agente 1:** S1 (Cifrado Setting) + SettingEncryptionTest
- **Agente 2:** S2 + S3 + S4 (Autorizaciones + FIELD fix) + AuthorizationTest
- **Agente 3:** B1 (Soft Deletes — 5 modelos + 5 migraciones)

### Grupo B (después del Grupo A):
- **Agente 4:** B2 (AccountingPeriodAutoCloser refactor)
- **Agente 5:** B3 + B4 + B5 (Invoice overflow + Purchase accounting + Cache TTL)
- **Agente 6:** SaleServiceTest + StockServiceTest + SaleAccountingTest + StockCacheTest

### QA (coordinador principal):
- Revisar diff de cada agente antes de merge
- Ejecutar `php artisan test` completo después de cada grupo
- Verificar que migraciones son reversibles (`php artisan migrate:rollback`)
- Verificar que `php artisan migrate:fresh --seed` sigue funcionando

---

## Criterios de Aceptación

- [ ] `php artisan test` pasa al 100% (incluyendo tests existentes)
- [ ] `php artisan migrate:fresh --seed` termina sin errores
- [ ] Las API keys en `settings` DB están cifradas (verificable con `DB::table('settings')->where('key', 'anthropic_api_key')->value('value')` — debe ser ilegible)
- [ ] Staff no puede completar venta ajena (test pasa)
- [ ] `FIELD()` reemplazado por `CASE WHEN` (tests en SQLite pasan)
- [ ] Soft deletes en 5 modelos (un `Product::first()->delete()` no elimina de DB)
- [ ] TTL cache productos = 15s
- [ ] Invoice number usa 6 dígitos de padding

---

## Riesgos y Mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| Migración de cifrado rompe valores existentes | Script lee con `Setting::getRaw()` y re-cifra; si `decrypt()` falla, asume plaintext y cifra |
| Soft delete rompe queries existentes que asumen registros existen | Revisar todos los `->find()` críticos + FK constraints |
| Refactor B2 (AutoCloser) introduce bug en producción | Feature-flag via setting `auto_close_v2 = true`; default false hasta QA |
| Tests nuevos fallan por falta de seeders | Usar factories existentes + `AccountingPeriodSeeder` + `ChartOfAccountSeeder` |
