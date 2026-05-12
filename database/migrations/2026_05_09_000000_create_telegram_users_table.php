<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->string('chat_id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('identifier'); // email o username guardado
            $table->timestamp('last_login')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
