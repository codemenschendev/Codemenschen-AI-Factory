<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The console (2026-10-05): change credits a customer buys in packs, and the record of each purchase. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->unsignedInteger('edit_credits')->default(0));
        Schema::create('credit_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('edits');
            $t->unsignedInteger('eur');
            $t->string('stripe_session_id')->unique();
            $t->string('status', 16)->default('pending');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('edit_credits'));
    }
};
