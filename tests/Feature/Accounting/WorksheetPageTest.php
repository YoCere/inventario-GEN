<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Accounting\Worksheet;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorksheetPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_worksheet_page_renders(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Worksheet::class)->assertOk();
    }
}
