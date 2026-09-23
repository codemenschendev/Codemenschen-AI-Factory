<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ad click a quote came from, kept only when the visitor allowed ad measurement, so a
        // purchase days later can still be reported against the click that started it.
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('ad_click')->nullable()->after('locale');
        });

        // Every result reported back to an ad platform (2026-09-23): what, for which click, and
        // whether the platform took it. A row per platform and event, so a refusal is visible.
        Schema::create('ad_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10);            // meta|google
            $table->string('event', 20);               // lead|purchase
            $table->string('event_id', 80)->unique();   // also the platform's dedup key
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignUuid('prototype_id')->nullable()->constrained('prototypes')->nullOnDelete();
            $table->string('click_id', 255);           // the fbclid or gclid value
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('happened_at');
            $table->decimal('value_eur', 10, 2)->nullable();
            // Matching data for the platform (hashed e-mail, address, browser). Cleared once sent.
            $table->json('match')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('status', 12)->default('pending'); // pending|sent|failed|expired
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_conversions');
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('ad_click');
        });
    }
};
