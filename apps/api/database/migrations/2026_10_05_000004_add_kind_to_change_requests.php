<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sofabuilt (2026-10-05): a round can be a paid new feature, priced from the parts it needs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $t) {
            $t->string('kind', 16)->default('change');
            $t->json('modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', fn (Blueprint $t) => $t->dropColumn(['kind', 'modules']));
    }
};
