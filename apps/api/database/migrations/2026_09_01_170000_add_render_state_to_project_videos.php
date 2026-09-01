<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_videos', function (Blueprint $table) {
            // A clip now starts life before its file exists: the customer submits a prompt and
            // the render happens on the queue, so the row carries the state the portal polls.
            $table->string('status', 12)->default('ready')->after('project_id'); // queued|rendering|ready|failed
            $table->string('source', 10)->default('upload')->after('status');    // upload|ai
            $table->text('prompt')->nullable()->after('source');
            $table->text('error')->nullable()->after('prompt');
            $table->json('spec')->nullable()->after('error');                    // what was actually rendered
            $table->string('path')->nullable()->change();                        // empty until the render lands
        });
    }

    public function down(): void
    {
        Schema::table('project_videos', function (Blueprint $table) {
            $table->dropColumn(['status', 'source', 'prompt', 'error', 'spec']);
        });
    }
};
