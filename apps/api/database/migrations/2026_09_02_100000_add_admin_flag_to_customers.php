<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operator signs in through the same magic link as everybody else; this flag is the only
 * difference between them and a customer. Kept on `customers` rather than in a second user table
 * because a second identity would mean a second login, a second token and a second way to lock
 * yourself out of your own factory.
 */
return new class extends Migration
{
    private const FOUNDING_ADMIN = 'developerweb@codemenschen.at';

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
        });

        // Idempotent on purpose: the row exists on the server, not on a fresh test database.
        $now = now();
        if (DB::table('customers')->where('email', self::FOUNDING_ADMIN)->exists()) {
            DB::table('customers')->where('email', self::FOUNDING_ADMIN)->update(['is_admin' => true]);
        } else {
            DB::table('customers')->insert([
                'email' => self::FOUNDING_ADMIN, 'name' => 'Codemenschen', 'locale' => 'de',
                'is_admin' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
