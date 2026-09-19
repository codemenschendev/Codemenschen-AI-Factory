<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A campaign's landing page goes live at /l/{id} when its owner says so.
        Schema::table('prototypes', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('revisions');
        });

        // The waitlist behind it. Double opt-in: an address counts once its link is opened, and
        // the row is the consent record (what was agreed to, when, from where).
        Schema::create('landing_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('prototype_id')->constrained('prototypes')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('status', 16)->default('pending');
            $table->text('consent');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->string('source', 120)->nullable();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirm_ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['prototype_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_signups');
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
