<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique()->index();
            $table->string('step')->default('idle'); // idle | nuevo:nombre | nuevo:precio_compra | etc.
            $table->json('data')->nullable(); // accumulated form data
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_conversations');
    }
};
