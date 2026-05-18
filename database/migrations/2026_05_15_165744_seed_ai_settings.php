<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            // Master switches
            'ai_chatbot_enabled' => '0',
            'ai_voice_enabled' => '0',

            // Model config
            'ai_model' => 'claude-haiku-4-5-20251001',
            'ai_max_tokens_response' => '1024',

            // Cost guards
            'ai_max_cost_usd_per_day' => '5.00',
            'ai_max_msgs_per_minute' => '6',

            // STT (default OpenAI Whisper API)
            'whisper_provider' => 'openai', // openai | local
            'whisper_language' => 'es',
            'whisper_max_seconds' => '120',

            // TTS (default Piper self-hosted)
            'tts_provider' => 'piper',     // piper | openai
            'tts_voice' => 'es_MX-claude-medium',
            'tts_binary_path' => 'piper', // resolves from PATH or absolute
            'tts_model_path' => '', // path to .onnx model

            // Reply mode
            'ai_voice_reply' => '1', // 1 = reply voice if voice received

            // System prompt (editable from settings UI later)
            'ai_system_prompt' => <<<PROMPT
Eres un asistente conversacional para un sistema de inventario y ventas en Bolivia. Tu rol:
- Ayudar al dueño a consultar inventario, ventas, reportes
- Crear ventas, devoluciones, productos solo después de confirmar con el usuario
- Responder en español, conciso (max 3 lineas en lo posible)
- Usar las herramientas (tools) disponibles para obtener datos reales
- NUNCA inventar SKUs ni IDs de productos: siempre buscar primero
- Para acciones destructivas (vender, cancelar, eliminar): proponer y pedir confirmación "sí/no"
- Si el usuario habla por voz, su mensaje puede tener errores de transcripción - pide aclaración si dudas
PROMPT,
        ];

        foreach ($defaults as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();
            if (!$exists) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'ai_chatbot_enabled', 'ai_voice_enabled',
            'ai_model', 'ai_max_tokens_response',
            'ai_max_cost_usd_per_day', 'ai_max_msgs_per_minute',
            'whisper_provider', 'whisper_language', 'whisper_max_seconds',
            'tts_provider', 'tts_voice', 'tts_binary_path', 'tts_model_path',
            'ai_voice_reply', 'ai_system_prompt',
        ];
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
