<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_catalog_item_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_catalog_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->index();
            $table->string('occurrence_key', 64)->unique();
            $table->string('page_url')->nullable();
            $table->string('product_url')->nullable()->index();
            $table->string('part_number')->nullable()->index();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('scheme_number')->nullable();
            $table->string('quantity')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();

            $table->index(['source', 'part_catalog_item_id'], 'pci_occ_source_item_idx');
            $table->index(['source', 'part_catalog_category_id'], 'pci_occ_source_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_catalog_item_occurrences');
    }
};
