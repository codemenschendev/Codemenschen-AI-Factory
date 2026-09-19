<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            // The pictures the visitor uploaded with the sentence: [{path, name}]. They are the
            // business's own pictures and go into the page before anything from its website.
            $table->json('uploads')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('prototypes', function (Blueprint $table) {
            $table->dropColumn('uploads');
        });
    }
};
