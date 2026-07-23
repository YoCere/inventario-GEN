<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service')->index();
            $table->string('environment')->nullable();
            $table->longText('request')->nullable();
            $table->longText('response')->nullable();
            $table->boolean('success')->default(false);
            $table->string('error_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_logs'); }
};
