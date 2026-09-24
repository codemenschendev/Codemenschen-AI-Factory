<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website as a product (2026-09-24): a site preview can be bought, goes live at payment and
 * gets the customer's own domain. A quote and a project say what they are for; until now both
 * were apps only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('kind', 8)->default('app')->after('listing_slug');
            $table->foreignUuid('prototype_id')->nullable()->after('kind')->constrained()->nullOnDelete();
        });
        // A bought page never expires.
        Schema::table('prototypes', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('kind', 8)->default('app')->after('name');
            // The customer's own domain, once they asked for it; our team connects it by hand.
            $table->string('domain')->nullable()->after('stack');
            $table->timestamp('domain_requested_at')->nullable()->after('domain');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn(['kind', 'domain', 'domain_requested_at']);
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prototype_id');
            $table->dropColumn('kind');
        });
    }
};
