<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('platform', 10);                    // google|meta
            $table->json('strategy');                          // audience, angle, budget hint …
            // Content approval by the customer; SPEND approval is a separate
            // operator gate that arrives with the ads-API integrations.
            $table->string('status', 20)->default('pending_approval'); // pending_approval|approved|rejected|live|ended
            $table->unsignedInteger('ad_budget_monthly_eur')->default(0);
            $table->string('external_campaign_id')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns');
            $table->string('kind', 20);   // headline|ad_copy|landing|image_prompt
            $table->string('locale', 5)->nullable();
            $table->text('content');
            $table->string('status', 20)->default('generated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creatives');
        Schema::dropIfExists('marketing_campaigns');
    }
};
