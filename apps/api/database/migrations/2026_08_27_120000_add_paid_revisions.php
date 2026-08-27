<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paid change-request rounds (after the free REVIEW rounds, or once the
        // app is READY/PUBLISHED): quoted → Stripe Checkout → revise stage.
        Schema::table('change_requests', function (Blueprint $table) {
            $table->unsignedInteger('price_eur')->default(0)->after('text');
            $table->string('stripe_checkout_session_id')->nullable()->index()->after('agent_summary');
            $table->string('stripe_payment_intent')->nullable()->after('stripe_checkout_session_id');
            $table->text('checkout_url')->nullable()->after('stripe_payment_intent');
            $table->timestamp('paid_at')->nullable()->after('checkout_url');
            $table->timestamp('fagg_waiver_at')->nullable()->after('paid_at');
            $table->string('fagg_waiver_ip', 45)->nullable()->after('fagg_waiver_at');
        });
        Schema::table('projects', function (Blueprint $table) {
            // Where a revision of an already released app returns to after approval.
            $table->string('resume_status', 20)->nullable()->after('revision_rounds');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('resume_status');
        });
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn(['price_eur', 'stripe_checkout_session_id', 'stripe_payment_intent', 'checkout_url', 'paid_at', 'fagg_waiver_at', 'fagg_waiver_ip']);
        });
    }
};
