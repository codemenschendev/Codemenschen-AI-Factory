<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which storefront sold it (docs/specs/sofabuilt.md): links, mails and names follow the brand. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotes', 'orders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('brand', 16)->default('appmitki'));
        }
    }

    public function down(): void
    {
        foreach (['quotes', 'orders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('brand'));
        }
    }
};
