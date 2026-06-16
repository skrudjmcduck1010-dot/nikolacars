<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_catalog_source_stats', function (Blueprint $table): void {
            $table->string('source')->primary();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('with_image_count')->default(0);
            $table->unsignedInteger('without_image_count')->default(0);
            $table->unsignedInteger('name_conflict_count')->default(0);
            $table->unsignedInteger('missing_ru_count')->default(0);
            $table->unsignedInteger('missing_ua_count')->default(0);
            $table->timestamp('rebuilt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_catalog_source_stats');
    }
};
