<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_type')->index();
            $table->string('code');
            $table->string('description');
            $table->json('extra')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['catalog_type', 'code']);
        });
    }

    public function down(): void { Schema::dropIfExists('fiscal_catalog_entries'); }
};
