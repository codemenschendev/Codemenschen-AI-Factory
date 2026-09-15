<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            // Hash of address, browser and a salt that changes every day. Not reversible, not stored anywhere else.
            $table->string('visitor', 16)->nullable();
            $table->string('path', 200)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('referrer', 120)->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('click_id', 10)->nullable();
            $table->string('device', 10)->nullable();
            $table->uuid('quote_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->json('props')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['name', 'created_at']);
            $table->index('visitor');
            $table->index('quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
