<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was drawn. It used to live only in the queue payload, which meant the share page
        // could not tell an app from a landing page and framed both in the same wide box. An app
        // belongs in a phone.
        Schema::table('prototypes', function (Blueprint $table) {
            $table->string('kind', 8)->default('site')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
