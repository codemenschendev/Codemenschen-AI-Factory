<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Free, anonymous prompt-to-prototype: a lead magnet, not a customer asset. A visitor
        // types a sentence, gets a clickable static page at a share link, and is invited to turn
        // it into a real project. Rows are throwaway: they expire and are pruned.
        Schema::create('prototypes', function (Blueprint $table) {
            $table->uuid('id')->primary();                 // also the share slug
            $table->string('status', 12)->default('queued'); // queued|building|ready|failed
            $table->text('prompt');
            $table->string('title')->nullable();
            $table->longText('html')->nullable();          // the generated page, untrusted content
            $table->text('error')->nullable();
            $table->string('ip', 45)->nullable();          // for the per-IP abuse cap
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete(); // set if it became real
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prototypes');
    }
};
