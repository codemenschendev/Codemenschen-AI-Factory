<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The change chat (docs/specs/change-chat.md): one thread per project. A draft is the run
        // of messages without a change request; confirming its summary card creates the request
        // and the messages point at it from then on.
        Schema::create('change_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->foreignId('change_request_id')->nullable()->constrained('change_requests');
            // customer | assistant | system | operator
            $table->string('role', 12);
            $table->text('body');
            // questions, summary card, system event, links: rendered by type, never free text
            $table->json('meta')->nullable();
            // operator e-mail, so a reply in the thread has a name behind it
            $table->string('author')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'id']);
        });

        Schema::table('change_requests', function (Blueprint $table) {
            // The confirmed checklist and what the revise agent reports per item.
            $table->json('items')->nullable()->after('text');
            $table->json('result_items')->nullable()->after('agent_summary');
        });

        Schema::table('projects', function (Blueprint $table) {
            // An operator can take a thread over: the customer still writes, the assistant waits.
            $table->boolean('assistant_paused')->default(false)->after('revision_rounds');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('assistant_paused'));
        Schema::table('change_requests', fn (Blueprint $table) => $table->dropColumn(['items', 'result_items']));
        Schema::dropIfExists('change_messages');
    }
};
