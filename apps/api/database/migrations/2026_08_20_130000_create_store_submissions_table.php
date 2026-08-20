<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('store', 10); // apple|google
            // waiting_account → preparing → submitted → in_review → live | rejected
            $table->string('status', 20)->default('waiting_account');
            // Apple 4.3 / Play repetitive-content: published under the
            // CUSTOMER's own developer account (PLAN.md §2).
            $table->string('account_ref')->nullable();
            $table->string('external_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'store']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_submissions');
    }
};
