<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tick box that is only checked in the browser proves nothing later. The order carries when the
 * terms were accepted and from where, the same way the FAGG waiver already does.
 *
 * Existing rows stay null on purpose: they were placed before the checkout asked, and backdating a
 * consent nobody gave would be worse than an honest gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_accepted_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_accepted_ip']);
        });
    }
};
