<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operator switches that must survive a deploy and apply without one (payments mode first).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value')->nullable();
            $table->string('updated_by', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // null for orders from before the switch existed; all of those ran in Stripe test mode.
            $table->boolean('livemode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('livemode'));
        Schema::dropIfExists('settings');
    }
};
