<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REVIEW-stage change requests (PLAN.md §API "feedback"): the customer
        // describes what to change, the revise stage implements it, the app is
        // re-tested and re-built, and the project returns to REVIEW.
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->unsignedTinyInteger('round');
            $table->text('text');
            // in_progress → done | out_of_scope | failed
            $table->string('status', 20)->default('in_progress');
            $table->text('agent_summary')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedTinyInteger('revision_rounds')->default(0)->after('fix_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('revision_rounds');
        });
        Schema::dropIfExists('change_requests');
    }
};
