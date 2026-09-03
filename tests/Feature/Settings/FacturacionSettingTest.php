<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturacionSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_facturacion_default_off(): void
    {
        $this->assertSame('0', Setting::get('facturacion_activada'));
    }
}
