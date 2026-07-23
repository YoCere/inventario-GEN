<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('doc_type')->nullable()->after('name');
            $table->string('doc_number')->nullable()->after('doc_type');
            $table->string('doc_complement')->nullable()->after('doc_number');
            $table->string('business_name')->nullable()->after('doc_complement');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'doc_number', 'doc_complement', 'business_name']);
        });
    }
};
