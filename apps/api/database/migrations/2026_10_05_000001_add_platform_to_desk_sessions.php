<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sofabuilt builds more than WordPress plugins (2026-10-05): which one this chat is about. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_sessions', fn (Blueprint $t) => $t->string('platform', 16)->default('wordpress')->after('door'));
    }

    public function down(): void
    {
        Schema::table('desk_sessions', fn (Blueprint $t) => $t->dropColumn('platform'));
    }
};
