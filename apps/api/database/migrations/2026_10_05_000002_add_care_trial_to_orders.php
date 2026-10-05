<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sofabuilt (2026-10-05): the buyer ticked Care with its free first months at checkout. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->boolean('care_trial')->default(false)->after('fagg_waiver_ip'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('care_trial'));
    }
};
