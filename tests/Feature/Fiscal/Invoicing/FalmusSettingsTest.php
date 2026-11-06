<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FalmusSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_encrypted_at_rest(): void
    {
        Setting::set('falmus_api_key', 'flm_test_abc123');

        $raw = DB::table('settings')->where('key', 'falmus_api_key')->value('value');

        $this->assertNotSame('flm_test_abc123', $raw);
        $this->assertStringNotContainsString('flm_test_', (string) $raw);
        $this->assertSame('flm_test_abc123', Setting::get('falmus_api_key'));
    }
}
