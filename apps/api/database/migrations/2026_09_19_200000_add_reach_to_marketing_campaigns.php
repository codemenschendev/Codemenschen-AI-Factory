<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the ad reached, read with its spend by the guard's watch: the top of the funnel
        // in the validation report.
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('impressions')->default(0)->after('spent_today_eur');
            $table->unsignedInteger('link_clicks')->default(0)->after('impressions');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn(['impressions', 'link_clicks']);
        });
    }
};
