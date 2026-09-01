<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marketing clips rendered by ops/make-video.py. Files live under
        // services.media.videos_path; this table is what ties one to a project, so a customer
        // only ever sees the clips made for their own app.
        Schema::create('project_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');                                  // shown in the portal
            $table->string('path')->unique();                        // relative to the media dir
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_videos');
    }
};
