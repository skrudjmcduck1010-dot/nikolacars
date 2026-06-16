<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_catalog_item_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('zone')->index();
            $table->unsignedTinyInteger('confidence')->default(70);
            $table->string('matched_rule')->nullable();
            $table->timestamps();

            $table->unique(['part_catalog_item_id', 'zone'], 'part_catalog_item_zones_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_catalog_item_zones');
    }
};
