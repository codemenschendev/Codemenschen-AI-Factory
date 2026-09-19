<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            // A campaign prototype is three prototypes built from one message: the ad, the landing
            // page and the e-mails. Each part is an ordinary prototype pointing at the campaign.
            $table->foreignUuid('parent_id')->nullable()->after('id')->constrained('prototypes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
