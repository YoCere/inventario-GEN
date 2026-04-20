<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Models\PayrollSheet;
use App\Models\JournalEntry;
use App\Services\PayrollService;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\AccountingPeriodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_post_payroll_sheet(): void
    {
        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $service = app(PayrollService::class);

        $payload = [
            'period_month' => now()->startOfMonth()->toDateString(),
            'payment_date' => now()->toDateString(),
            'description' => 'Planilla prueba',
            'items' => [
                [
                    'employee_name' => 'Empleado Uno',
                    'position' => 'Operario',
                    'area' => 'mod',
                    'antiquity_rate' => 0.05,
                    'worked_days' => 30,
                    'base_salary' => 10000,
                    'hours_extra' => 1000,
                    'other_discounts' => 100,
                    'apply_border_bonus' => true,
                ],
            ],
        ];

        $expected = $service->calculateItem($payload['items'][0]);

        $sheet = $service->createSheet($payload, (int) $admin->id);

        $this->assertEquals('draft', $sheet->status->value);
        $this->assertEquals($expected['total_earned'], $sheet->total_earned);
        $this->assertEquals($expected['total_deductions'], $sheet->total_deductions);
        $this->assertEquals($expected['net_payable'], $sheet->net_payable);
        $this->assertEquals(
            $expected['employer_contribution'] + $expected['aguinaldo_provision'] + $expected['indemnization_provision'],
            $sheet->total_employer_contributions
        );
        $this->assertEquals($expected['total_employer_cost'], $sheet->total_employer_cost);

        $this->actingAs($admin)
            ->post(route('finance.payroll.post', $sheet))
            ->assertRedirect(route('finance.payroll.show', $sheet));

        $sheet->refresh();
        $this->assertEquals('posted', $sheet->status->value);
        $this->assertNotNull($sheet->journal_entry_id);

        $entry = JournalEntry::query()->findOrFail($sheet->journal_entry_id);
        $this->assertEquals('posted', $entry->status->value);

        $debit = (int) $entry->lines()->sum('debit_amount');
        $credit = (int) $entry->lines()->sum('credit_amount');
        $this->assertEquals($debit, $credit);
        $this->assertEquals($expected['total_employer_cost'], $debit);
    }

    public function test_staff_cannot_access_payroll_module(): void
    {
        $staff = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'staff',
        ]);

        $this->actingAs($staff)
            ->get(route('finance.payroll.index'))
            ->assertForbidden();
    }

    public function test_payroll_pages_load_for_admin(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get(route('finance.payroll.index'))
            ->assertOk()
            ->assertSeeText('Planillas de sueldo');

        $this->actingAs($admin)
            ->get(route('finance.payroll.create'))
            ->assertOk()
            ->assertSeeText('Nueva planilla de sueldo');
    }
}
