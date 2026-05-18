<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'ai_provider',     'value' => 'anthropic'],
            ['key' => 'ai_api_base_url', 'value' => ''],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['ai_provider', 'ai_api_base_url'])->delete();
    }
};
