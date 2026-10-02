<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI-visibility kit the marketing agent writes with the ads plan (2026-10-02): llms.txt,
 * schema.org JSON-LD, FAQ answers, test prompts and directory listings, handed to the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('ai_visibility')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('ai_visibility');
        });
    }
};
