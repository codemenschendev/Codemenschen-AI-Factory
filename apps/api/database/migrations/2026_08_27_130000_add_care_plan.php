<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Appwerk Care: €9/month per app — unlimited change rounds instead of
        // paying per round. A Stripe subscription; state mirrored here by webhook.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('care_status', 20)->default('none')->after('resume_status'); // none|active|past_due|canceled
            $table->string('care_stripe_subscription_id')->nullable()->index()->after('care_status');
            $table->timestamp('care_started_at')->nullable()->after('care_stripe_subscription_id');
            $table->timestamp('care_ends_at')->nullable()->after('care_started_at'); // set once cancelled
        });
        Schema::table('change_requests', function (Blueprint $table) {
            // 'care' = covered by the subscription; null = free round or paid (price_eur > 0).
            $table->string('covered_by', 10)->nullable()->after('price_eur');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn('covered_by');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['care_status', 'care_stripe_subscription_id', 'care_started_at', 'care_ends_at']);
        });
    }
};
