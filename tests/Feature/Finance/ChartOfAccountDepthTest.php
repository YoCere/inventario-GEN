<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountNormalBalance;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChartOfAccountDepthTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountService::class);
    }

    private function parent(string $code, int $level, bool $postable = false): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code, 'name' => 'Padre', 'level' => $level, 'parent_id' => null,
            'account_type' => AccountType::Asset, 'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => $postable, 'is_active' => true, 'description' => null,
        ]);
    }

    private function childData(string $code, ChartOfAccount $parent): array
    {
        return [
            'code' => $code, 'name' => 'Hija', 'parent_id' => $parent->id,
            'account_type' => AccountType::Asset, 'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true, 'is_active' => true, 'description' => null,
        ];
    }

    public function test_permite_nivel_5(): void
    {
        $p = $this->parent('1.1.1.4', 4);
        $child = $this->service->create($this->childData('1.1.1.4.1', $p));
        $this->assertSame(5, $child->level);
    }

    public function test_rechaza_nivel_6(): void
    {
        $p = $this->parent('1.1.1.4.1', 5);
        $this->expectException(RuntimeException::class);
        $this->service->create($this->childData('1.1.1.4.1.1', $p));
    }

    public function test_rechaza_codigo_incoherente_con_el_padre(): void
    {
        $p = $this->parent('1.1', 2);
        $this->expectException(RuntimeException::class);
        $this->service->create($this->childData('2.1.01', $p)); // no empieza con '1.1.'
    }

    public function test_acepta_codigo_coherente(): void
    {
        $p = $this->parent('1.1.1.4', 4);
        $child = $this->service->create($this->childData('1.1.1.4.9', $p));
        $this->assertSame('1.1.1.4.9', $child->code);
    }

    public function test_cuenta_raiz_sin_validacion(): void
    {
        $root = $this->service->create([
            'code' => '9', 'name' => 'Raiz', 'parent_id' => null,
            'account_type' => AccountType::Asset, 'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true, 'is_active' => true, 'description' => null,
        ]);
        $this->assertSame(1, $root->level);
    }

    public function test_validacion_fallida_no_voltea_al_padre(): void
    {
        $p = $this->parent('1.1', 2, postable: true);
        try {
            $this->service->create($this->childData('2.1.01', $p)); // código incoherente → falla
        } catch (RuntimeException $e) {
            // esperado
        }
        $this->assertTrue($p->fresh()->allows_posting, 'El padre no debe voltearse si la validación falló.');
    }
}
