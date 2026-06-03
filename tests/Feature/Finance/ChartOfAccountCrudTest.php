<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountNormalBalance;
use App\Enums\AccountType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChartOfAccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountService::class);
        $this->user = User::factory()->admin()->create();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeAccount(array $overrides = []): ChartOfAccount
    {
        return ChartOfAccount::create(array_merge([
            'code'           => 'TEST.' . uniqid(),
            'name'           => 'Cuenta de Prueba',
            'level'          => 1,
            'parent_id'      => null,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
            'description'    => null,
        ], $overrides));
    }

    /**
     * Crea un JournalEntry + JournalEntryLine asociados a la cuenta dada,
     * sin pasar por la capa de validación de periodos (insertamos directamente).
     */
    private function attachMovement(ChartOfAccount $account): JournalEntryLine
    {
        $period = AccountingPeriod::create([
            'name'       => 'Periodo Test ' . uniqid(),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date'   => now()->endOfMonth()->toDateString(),
            'status'     => 'open',
        ]);

        $entry = JournalEntry::create([
            'entry_number'         => 'JRN.TEST.' . uniqid(),
            'entry_date'           => now()->toDateString(),
            'accounting_period_id' => $period->id,
            'description'          => 'Movimiento de prueba',
            'status'               => 'posted',
            'posted_at'            => now(),
            'created_by'           => $this->user->id,
        ]);

        return JournalEntryLine::create([
            'journal_entry_id'    => $entry->id,
            'chart_of_account_id' => $account->id,
            'line_number'         => 1,
            'description'         => null,
            'debit_amount'        => 1000,
            'credit_amount'       => 0,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /** Test 1: Crea cuenta raíz válida (level=1, sin padre). */
    public function test_crea_cuenta_raiz_valida(): void
    {
        $account = $this->service->create([
            'code'           => '9',
            'name'           => 'Cuenta Raíz',
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => false,
            'is_active'      => true,
        ]);

        $this->assertInstanceOf(ChartOfAccount::class, $account);
        $this->assertNotNull($account->id);
        $this->assertEquals('9', $account->code);
        $this->assertEquals(1, $account->level);
        $this->assertNull($account->parent_id);
    }

    /** Test 2: Crea subcuenta válida (level=padre+1, hereda tipo). */
    public function test_crea_subcuenta_valida(): void
    {
        $parent = $this->makeAccount([
            'code'           => '9',
            'level'          => 1,
            'parent_id'      => null,
            'account_type'   => AccountType::Asset,
            'allows_posting' => false,
        ]);

        $child = $this->service->create([
            'code'           => '9.1',
            'name'           => 'Sub Activo Corriente',
            'parent_id'      => $parent->id,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);

        $this->assertEquals(2, $child->level);
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals(AccountType::Asset, $child->account_type);
    }

    /** Test 3: Rechaza código duplicado. */
    public function test_rechaza_codigo_duplicado(): void
    {
        $this->makeAccount(['code' => '9']);

        $this->expectException(RuntimeException::class);

        $this->service->create([
            'code'           => '9',
            'name'           => 'Otra cuenta con mismo código',
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);
    }

    /** Test 4: Rechaza tipo de hija != tipo del padre. */
    public function test_rechaza_tipo_hijo_diferente_al_padre(): void
    {
        $parent = $this->makeAccount([
            'code'           => '9',
            'level'          => 1,
            'account_type'   => AccountType::Asset,
            'allows_posting' => false,
        ]);

        $this->expectException(RuntimeException::class);

        $this->service->create([
            'code'           => '9.1',
            'name'           => 'Sub cuenta tipo diferente',
            'parent_id'      => $parent->id,
            'account_type'   => AccountType::Liability, // diferente al padre (asset)
            'normal_balance' => AccountNormalBalance::Credit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);
    }

    /** Test 5: Rechaza crear hija de padre que ya tiene movimientos. */
    public function test_rechaza_crear_hija_de_padre_con_movimientos(): void
    {
        $parent = $this->makeAccount([
            'code'           => '9',
            'level'          => 1,
            'account_type'   => AccountType::Asset,
            'allows_posting' => true,
        ]);

        $this->attachMovement($parent);

        $this->expectException(RuntimeException::class);

        $this->service->create([
            'code'           => '9.1',
            'name'           => 'Sub Activo',
            'parent_id'      => $parent->id,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);
    }

    /** Test 6: Voltea padre imputable sin movimientos a no-imputable al crearle hija. */
    public function test_voltea_padre_imputable_sin_movimientos_a_no_imputable(): void
    {
        $parent = $this->makeAccount([
            'code'           => '9',
            'level'          => 1,
            'account_type'   => AccountType::Asset,
            'allows_posting' => true, // imputable pero sin movimientos
        ]);

        $this->assertTrue($parent->allows_posting);

        $child = $this->service->create([
            'code'           => '9.1',
            'name'           => 'Sub Activo',
            'parent_id'      => $parent->id,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);

        $parent->refresh();
        $this->assertFalse($parent->allows_posting, 'El padre debe haberse flipado a no-imputable.');
        $this->assertNotNull($child->id);
    }

    /** Test 7: Editar cuenta CON movimientos → cambiar code/account_type se ignora; cambiar name aplica. */
    public function test_editar_cuenta_con_movimientos_solo_actualiza_campos_permitidos(): void
    {
        $account = $this->makeAccount([
            'code'         => '9',
            'name'         => 'Nombre Original',
            'account_type' => AccountType::Asset,
            'level'        => 1,
        ]);

        $this->attachMovement($account);

        $updated = $this->service->update($account, [
            'code'         => '99',                   // debe ignorarse
            'account_type' => AccountType::Liability, // debe ignorarse
            'name'         => 'Nombre Actualizado',   // debe aplicarse
            'is_active'    => false,                  // debe aplicarse
        ]);

        $this->assertEquals('Nombre Actualizado', $updated->name);
        $this->assertFalse($updated->is_active);
        $this->assertEquals('9', $updated->code);                   // sin cambio
        $this->assertEquals(AccountType::Asset, $updated->account_type); // sin cambio
    }

    /** Test 8: Desactivar y reactivar con setActive. */
    public function test_desactivar_y_reactivar_cuenta(): void
    {
        $account = $this->makeAccount(['is_active' => true]);

        $deactivated = $this->service->setActive($account, false);
        $this->assertFalse($deactivated->is_active);

        $reactivated = $this->service->setActive($deactivated, true);
        $this->assertTrue($reactivated->is_active);
    }

    /** Test 9: Editar cuenta SIN movimientos rechaza padre de tipo distinto. */
    public function test_editar_sin_movimientos_rechaza_padre_de_tipo_distinto(): void
    {
        $parentLiability = $this->makeAccount([
            'code'           => '2',
            'name'           => 'Pasivo',
            'account_type'   => AccountType::Liability,
            'normal_balance' => AccountNormalBalance::Credit,
            'allows_posting' => false,
        ]);

        $assetAccount = $this->makeAccount([
            'code'         => '1.9',
            'name'         => 'Cuenta Activo',
            'account_type' => AccountType::Asset,
        ]);

        $this->expectException(RuntimeException::class);

        // Sin movimientos: asignar un padre de tipo Liability a una cuenta Asset debe fallar.
        $this->service->update($assetAccount, [
            'code'           => '1.9',
            'name'           => 'Cuenta Activo',
            'parent_id'      => $parentLiability->id,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
        ]);
    }

    /** Test 10: Editar cuenta SIN movimientos deriva level del nuevo padre. */
    public function test_editar_sin_movimientos_deriva_level_del_padre(): void
    {
        $parent = $this->makeAccount([
            'code'           => '1.1',
            'name'           => 'Activo Corriente',
            'level'          => 2,
            'account_type'   => AccountType::Asset,
            'allows_posting' => false,
        ]);

        $account = $this->makeAccount([
            'code'         => '1.1.99',
            'name'         => 'Sub Activo',
            'account_type' => AccountType::Asset,
        ]);

        $updated = $this->service->update($account, [
            'code'           => '1.1.99',
            'name'           => 'Sub Activo',
            'parent_id'      => $parent->id,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
        ]);

        $this->assertEquals($parent->id, $updated->parent_id);
        $this->assertEquals(3, $updated->level);
    }
}
