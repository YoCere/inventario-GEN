<?php

namespace Tests\Feature\Ui;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FinanceCategoryType;
use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Models\User;
use App\Support\Ui\Module;
use App\Support\Ui\Tone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ColorSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_estados_equivalentes_comparten_tono(): void
    {
        // "Listo" es el mismo verde en ventas, compras y periodos.
        $this->assertSame(Tone::SUCCESS, SaleStatus::COMPLETED->tone());
        $this->assertSame(Tone::SUCCESS, PurchaseStatus::RECEIVED->tone());
        $this->assertSame(Tone::SUCCESS, PurchaseStatus::PAID->tone());
        $this->assertSame(Tone::SUCCESS, AccountingPeriodStatus::Open->tone());

        // "Pendiente" es ámbar en los dos módulos, no amarillo en uno y celeste en otro.
        $this->assertSame(Tone::WARNING, SaleStatus::PENDING->tone());
        $this->assertSame(Tone::WARNING, PurchaseStatus::ORDERED->tone());

        // El rojo queda para lo cancelado.
        $this->assertSame(Tone::DANGER, SaleStatus::CANCELLED->tone());
        $this->assertSame(Tone::DANGER, PurchaseStatus::CANCELLED->tone());
        $this->assertSame(Tone::DANGER, FinanceCategoryType::Expense->tone());
    }

    public function test_la_etiqueta_muestra_el_texto_y_no_solo_el_color(): void
    {
        $html = Blade::render('<x-status-badge :status="$status" />', ['status' => SaleStatus::PENDING]);

        $this->assertStringContainsString('Reservado', $html);
        $this->assertStringContainsString('bg-amber-50', $html);
        $this->assertStringContainsString('rounded-full bg-amber-600', $html);
    }

    public function test_la_etiqueta_acepta_tono_y_texto_sueltos(): void
    {
        $html = Blade::render('<x-status-badge tone="success" label="Activo" />');

        $this->assertStringContainsString('Activo', $html);
        $this->assertStringContainsString('bg-emerald-50', $html);
    }

    public function test_cada_ruta_pertenece_a_su_modulo(): void
    {
        $this->assertSame('ventas', Module::current('sales.index'));
        $this->assertSame('ventas', Module::current('customers.index'));
        $this->assertSame('compras', Module::current('purchases.create'));
        $this->assertSame('productos', Module::current('products.kardex.index'));
        $this->assertSame('finanzas', Module::current('finance.hub.accounting'));
        $this->assertSame('usuarios', Module::current('users.payroll.index'));
        $this->assertSame('ajustes', Module::current('settings.index'));
        $this->assertNull(Module::current('login'));
    }

    public function test_el_encabezado_lleva_el_color_del_modulo(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('sales.index'))
            ->assertOk()
            ->assertSee('bg-emerald-50', false);

        $this->actingAs($admin)->get(route('products.index'))
            ->assertOk()
            ->assertSee('bg-amber-50', false);
    }

    public function test_las_tablas_ya_no_usan_botones_de_colores_llenos(): void
    {
        $offenders = [];
        foreach (glob(app_path('Livewire/*/*.php')) as $file) {
            $src = file_get_contents($file);
            if (preg_match('/bg-(blue|amber|indigo|green|red)-500 hover:/', $src)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'Botones de tabla con color de relleno: ' . implode(', ', $offenders));
    }
}
