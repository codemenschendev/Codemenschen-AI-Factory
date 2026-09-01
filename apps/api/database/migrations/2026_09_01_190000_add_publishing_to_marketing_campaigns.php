<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // The campaign's life ON THE PLATFORM, separate from `status` (the customer's content
            // sign-off). It never becomes `active` on its own: a person flips it, and until then
            // no money moves. `paused` means it exists on the platform, ready, spending nothing.
            $table->string('platform_status', 12)->default('unpublished')->after('status');
            // unpublished|publishing|paused|active|failed

            // The creative file (image/video ad) this campaign runs. Null = copy-only for now.
            $table->foreignId('project_ad_id')->nullable()->after('project_id')->constrained('project_ads')->nullOnDelete();

            // Ids the platform hands back: campaign, ad set / ad group, ad, creative, uploaded
            // asset. Kept so activate/pause/read can address the right objects later.
            $table->json('platform_ref')->nullable()->after('external_campaign_id');
            $table->text('publish_error')->nullable()->after('platform_ref');
            $table->timestamp('published_at')->nullable()->after('publish_error');
            $table->timestamp('activated_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_ad_id');
            $table->dropColumn(['platform_status', 'platform_ref', 'publish_error', 'published_at', 'activated_at']);
        });
    }
};
