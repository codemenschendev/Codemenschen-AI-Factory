<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('stage', 20);                 // product|uiux|coding|test|fix|release
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status', 20)->default('queued'); // queued|running|succeeded|failed
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('callback_token', 64);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'stage']);
        });

        Schema::create('acceptance_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('key', 60);
            $table->text('criterion');
            $table->string('kind', 10)->default('automated'); // automated|manual
            $table->string('status', 10)->default('pending'); // pending|passed|failed|waived
            $table->timestamps();
            $table->unique(['project_id', 'key']);
        });

        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('platform', 10);              // android|ios|web|bundle
            $table->string('version', 20);
            $table->string('artifact_path')->nullable(); // path inside shared artifacts volume
            $table->string('status', 20)->default('created');
            $table->timestamps();
        });

        Schema::create('test_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->foreignUuid('pipeline_run_id')->constrained('pipeline_runs');
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('report')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_reports');
        Schema::dropIfExists('builds');
        Schema::dropIfExists('acceptance_criteria');
        Schema::dropIfExists('pipeline_runs');
    }
};
