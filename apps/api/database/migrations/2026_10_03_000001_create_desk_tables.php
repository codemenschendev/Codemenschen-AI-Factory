<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sofabuilt's desk (docs/specs/sofabuilt.md): a chat that turns an idea into a priced scope. The
 * session holds the scope the agent keeps and what the research found; the messages are the chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('brand', 16)->default('sofabuilt');
            $t->string('door', 16)->default('idea');
            $t->string('locale', 2)->default('en');
            $t->json('scope')->nullable();
            $t->json('research')->nullable();
            $t->boolean('ready')->default(false);
            $t->string('status', 16)->default('open');
            $t->string('ip', 45)->nullable();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('desk_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('desk_session_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);
            $t->text('body');
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_messages');
        Schema::dropIfExists('desk_sessions');
    }
};
