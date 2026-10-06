<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The tokens an agent reads from and writes to the prompt cache: most of what a code stage reads. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $t) {
            $t->unsignedBigInteger('tokens_cache_read')->default(0)->after('tokens_out');
            $t->unsignedBigInteger('tokens_cache_write')->default(0)->after('tokens_cache_read');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', fn (Blueprint $t) => $t->dropColumn(['tokens_cache_read', 'tokens_cache_write']));
    }
};
