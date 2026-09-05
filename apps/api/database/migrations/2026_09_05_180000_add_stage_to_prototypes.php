<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            // Which step a build is on while it is `building`: writing, auditing, repairing,
            // photos. A visitor waiting four minutes on "one moment" leaves; one who can see the
            // page is being checked in a browser and is now getting its photographs stays.
            $table->string('stage', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
