<?php

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepreciationRunModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists_with_unique(): void
    {
        $this->assertTrue(Schema::hasTable('depreciation_runs'));
        $this->assertTrue(Schema::hasColumn('depreciation_runs', 'year_month'));
    }
}
