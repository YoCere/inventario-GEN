<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sembrar settings de búsqueda por imagen vía Vision AI.
     *
     *   ai_vision_enabled — switch maestro ('0' default — el dev lo activa después
     *                       de confirmar que openai_api_key + crédito están listos).
     *   ai_vision_model   — modelo OpenAI Vision a usar. gpt-4o-mini default por
     *                       precio (~USD 0.001-0.003/imagen) + buena precisión.
     */
    public function up(): void
    {
        Setting::firstOrCreate(['key' => 'ai_vision_enabled'], ['value' => '0']);
        Setting::firstOrCreate(['key' => 'ai_vision_model'], ['value' => 'gpt-4o-mini']);
    }

    public function down(): void
    {
        Setting::whereIn('key', ['ai_vision_enabled', 'ai_vision_model'])->delete();
    }
};
