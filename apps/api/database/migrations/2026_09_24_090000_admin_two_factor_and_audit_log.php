<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A second factor for the console (2026-09-24). The e-mail link proves the mailbox; the
        // authenticator code proves the phone. The secret is encrypted with APP_KEY, the recovery
        // codes are stored hashed, and the last accepted time step stops a code being used twice.
        Schema::table('customers', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            $table->json('two_factor_recovery')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
        });

        // The code is asked once per sign-in: the token remembers that it passed.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('two_factor_at')->nullable();
        });

        // Who did what in the console, and what the machine did on its own (spend guard). Kept
        // 12 months, then deleted by the scheduler.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('actor', 190);              // e-mail of the admin, or "system"
            $table->string('action', 120);             // e.g. "POST admin/ads/kill", "signin", "2fa.enabled"
            $table->string('subject', 190)->nullable(); // what it was done to, e.g. "campaign:12"
            $table->json('data')->nullable();          // request fields, secrets removed
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn('two_factor_at'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_enabled_at', 'two_factor_recovery', 'two_factor_last_step']));
    }
};
