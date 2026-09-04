<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the browser saw when it opened this page at three widths, before anyone else did.
        // Null means the audit did not run: no node in the image, or an older row from before it
        // existed. That is different from an empty finding list, which means it ran and found
        // nothing, and the admin panel needs to be able to tell those apart.
        Schema::table('prototypes', function (Blueprint $table) {
            $table->json('qa')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropColumn('qa');
        });
    }
};
