<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Store-listing languages the customer picked at checkout (subset of
            // Order::SUPPORTED_STORE_LOCALES). Existing orders keep both.
            $table->json('store_locales')->default('["de","en"]')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('store_locales');
        });
    }
};
