<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->dateTime('remind_at');
            $table->string('timezone')->default('UTC');
            $table->enum('recurrence', ['none', 'daily', 'weekly', 'monthly', 'custom'])->default('none');
            $table->json('recurrence_rule')->nullable();
            $table->string('remindable_type')->nullable();
            $table->unsignedBigInteger('remindable_id')->nullable();
            $table->enum('status', ['pending', 'sent', 'done', 'cancelled', 'snoozed'])->default('pending');
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->enum('created_via', ['nl', 'command'])->default('command');
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
