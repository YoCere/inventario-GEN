<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('chat_id', 100)->nullable(); // telegram chat_id
            $table->string('channel', 30)->default('telegram'); // telegram/web/etc
            $table->string('model', 100);
            $table->string('action', 50)->nullable(); // 'agent.text', 'voice.stt', 'voice.tts'
            $table->integer('tokens_input')->default(0);
            $table->integer('tokens_output')->default(0);
            $table->integer('tokens_cache_read')->default(0);
            $table->integer('tokens_cache_write')->default(0);
            $table->integer('audio_seconds')->default(0); // for STT/TTS
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->text('summary')->nullable(); // brief description of operation
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('chat_id');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
