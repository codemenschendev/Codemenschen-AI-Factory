<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('locale', 5)->default('de');
            $table->string('stripe_customer_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Either a catalog listing (fixed price) or a custom idea.
            $table->string('listing_slug')->nullable();
            $table->text('idea')->nullable();
            $table->string('audience', 10)->nullable();   // consumer|b2b|both
            $table->string('platform', 10)->nullable();   // web|mobile|both
            $table->json('features')->nullable();
            $table->json('breakdown');                    // full estimate payload
            $table->unsignedInteger('price_eur');
            $table->char('app_type', 1);                  // A|B
            $table->unsignedInteger('hosting_monthly_eur')->default(0);
            $table->text('scope_summary')->nullable();    // AI-generated, optional
            $table->string('status', 20)->default('presented'); // presented|converted|expired
            $table->string('locale', 5)->default('de');
            $table->timestamp('valid_until');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained();
            $table->foreignUuid('quote_id')->constrained('quotes');
            $table->json('packages');                     // {storePublishing, transferAssist, marketingLaunch}
            $table->unsignedInteger('ad_budget_monthly_eur')->default(0);
            $table->unsignedInteger('total_one_time_eur');
            $table->unsignedInteger('hosting_monthly_eur')->default(0);
            $table->string('status', 20)->default('pending'); // pending|paid|failed|refunded
            $table->string('stripe_checkout_session_id')->nullable()->index();
            // FAGG § 18: express waiver (never pre-ticked client-side); when
            // false, build start is deferred 14 days and withdrawal stays intact.
            $table->boolean('fagg_waiver')->default(false);
            $table->timestamp('fagg_waiver_at')->nullable();
            $table->string('fagg_waiver_ip', 45)->nullable();
            $table->string('locale', 5)->default('de');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('stripe_payment_intent')->nullable()->index();
            $table->unsignedInteger('amount_eur');
            $table->string('status', 20);
            $table->json('raw_event')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignId('customer_id')->constrained();
            $table->string('name');
            $table->string('status', 20)->default('PAID');
            $table->string('stack', 10)->default('expo');   // expo|nextjs
            $table->string('repo_full_name')->nullable();
            $table->unsignedTinyInteger('fix_attempts')->default(0);
            $table->text('failed_reason')->nullable();
            $table->timestamp('build_starts_at')->nullable(); // deferred when no FAGG waiver
            $table->timestamps();
        });

        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('type', 40);
            $table->string('actor', 60)->default('system');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_events');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('customers');
    }
};
