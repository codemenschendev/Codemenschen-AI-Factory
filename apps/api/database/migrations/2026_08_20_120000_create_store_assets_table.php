<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('kind', 20);   // name|subtitle|description|keywords|release_notes|icon|screenshot|promo
            $table->string('locale', 5)->nullable();
            $table->text('content')->nullable();
            $table->string('file_url')->nullable();
            $table->string('status', 20)->default('generated'); // generated|approved|rejected
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();
            $table->index(['project_id', 'kind', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_assets');
    }
};
