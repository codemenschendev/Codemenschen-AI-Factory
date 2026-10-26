<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funnel steps for Meta (2026-10-26): a visitor who said yes to ad measurement but did not arrive
 * from an ad click has no fbclid, only the pixel's browser id, so the click id may be empty now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_conversions', function (Blueprint $table) {
            $table->string('click_id', 255)->nullable()->change();
            $table->string('event', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ad_conversions', function (Blueprint $table) {
            $table->string('event', 20)->change();
        });
    }
};
