<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_language_markers')) {
            return;
        }

        Schema::create('translation_language_markers', function (Blueprint $table): void {
            $table->id();
            $table->string('ua_marker');
            $table->string('ru_marker');
            $table->timestamps();

            $table->unique(['ua_marker', 'ru_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_language_markers');
    }
};
