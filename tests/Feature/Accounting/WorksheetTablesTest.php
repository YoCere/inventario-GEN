<?php

namespace Tests\Feature\Accounting;

use App\Models\WorksheetAnnotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorksheetTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_exist_and_annotation_defaults(): void
    {
        $this->assertTrue(Schema::hasTable('worksheet_rows'));
        $this->assertTrue(Schema::hasTable('worksheet_annotations'));

        $ann = new WorksheetAnnotation();
        $this->assertEquals('pendiente', $ann->getAttributes()['action_status'] ?? 'pendiente');
    }
}
