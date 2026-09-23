<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Meta ad is published by a page, so a customer's own account needs a customer's own page.
 * Google has no equivalent, which is why this is one nullable column and not a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_account_links', function (Blueprint $table) {
            $table->string('page_id')->nullable()->after('external_id');
            $table->string('page_name')->nullable()->after('page_id');
        });
    }

    public function down(): void
    {
        Schema::table('ad_account_links', function (Blueprint $table) {
            $table->dropColumn(['page_id', 'page_name']);
        });
    }
};
