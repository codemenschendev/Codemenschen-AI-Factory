<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's own ad account, linked to Codemenschen's (2026-09-23).
 *
 * Connecting an ad account by hand took hours on our own account: an allowed-domain list, two
 * admins approving, and a sign-in loop. A customer would give up. This row is the whole state of
 * the fast way instead: they paste their account number, we ask, they press accept once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('platform');               // meta | google
            $table->string('external_id');            // 10 digits (google) or act_… (meta)
            $table->string('status')->default('pending'); // pending | active | refused | removed
            $table->string('name')->nullable();       // what the platform calls the account
            $table->string('manager_link_id')->nullable(); // google, the id of the link itself
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_account_links');
    }
};
