<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The table now holds image ads as well as video ads, so "videos" had become a lie.
        // Renamed while it is one hour old and behind a hidden page; the cost only grows.
        Schema::rename('project_videos', 'project_ads');

        Schema::table('project_ads', function (Blueprint $table) {
            $table->string('kind', 10)->default('video')->after('project_id'); // video|image
        });
    }

    public function down(): void
    {
        Schema::table('project_ads', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::rename('project_ads', 'project_videos');
    }
};
