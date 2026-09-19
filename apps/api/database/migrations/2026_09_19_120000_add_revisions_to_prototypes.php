<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            // A signed-in visitor may change a free prototype once (owner's decision 2026-09-19).
            // The first change claims the prototype for that customer; the count is the allowance.
            $table->foreignId('customer_id')->nullable()->after('project_id')->constrained('customers')->nullOnDelete();
            $table->unsignedSmallInteger('revisions')->default(0)->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('revisions');
        });
    }
};
