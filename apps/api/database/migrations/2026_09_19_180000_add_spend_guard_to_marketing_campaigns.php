<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // A validation test runs a campaign prototype's ad before there is a project.
            $table->foreignUuid('project_id')->nullable()->change();
            $table->foreignUuid('prototype_id')->nullable()->after('project_id')->constrained('prototypes')->nullOnDelete();
            // The ad file when it is not a project ad: the picture of the prototype's ad.
            $table->string('creative_path')->nullable()->after('project_ad_id');
            // The spend guard (step 3 of the funnel, 2026-09-19). The most the campaign may ever
            // spend, what it has spent at the last look, when that was, when it ends, and why it
            // was stopped if the guard stopped it.
            $table->unsignedInteger('spend_cap_eur')->nullable()->after('ad_budget_monthly_eur');
            $table->decimal('spent_eur', 10, 2)->default(0)->after('spend_cap_eur');
            $table->decimal('spent_today_eur', 10, 2)->default(0)->after('spent_eur');
            $table->timestamp('spend_checked_at')->nullable()->after('spent_today_eur');
            $table->timestamp('ends_at')->nullable()->after('activated_at');
            $table->string('stopped_reason', 40)->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prototype_id');
            $table->dropColumn(['creative_path', 'spend_cap_eur', 'spent_eur', 'spent_today_eur', 'spend_checked_at', 'ends_at', 'stopped_reason']);
        });
    }
};
