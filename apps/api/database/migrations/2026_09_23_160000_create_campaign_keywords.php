<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words a search campaign is bought for (2026-09-23).
 *
 * A Google search campaign without keywords shows nothing and spends nothing, which is where our
 * campaigns stood until now. A row here is a proposal until a person approves it, and it only
 * reaches Google when somebody presses apply. Nothing in this table can spend on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->string('text', 80);
            // phrase or exact. Broad match is not offered: it is where a budget leaks.
            $table->string('match_type', 10)->default('phrase');
            // A negative keyword is a search we refuse to pay for. It sits on the campaign, not
            // the ad group, so one list covers every ad group under it.
            $table->boolean('negative')->default(false);
            // proposed -> approved -> applied. paused is a keyword that was applied and then taken
            // out of service; it is never deleted on Google, so its history stays readable.
            $table->string('status', 12)->default('proposed');
            $table->string('source', 10)->default('ai');   // ai|admin
            $table->string('resource_name')->nullable();   // what Google called it
            $table->timestamp('applied_at')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            // The same word twice on one campaign is a duplicate whatever its match type: Google
            // refuses it and an admin should not have to see it twice.
            $table->unique(['campaign_id', 'text', 'negative']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_keywords');
    }
};
