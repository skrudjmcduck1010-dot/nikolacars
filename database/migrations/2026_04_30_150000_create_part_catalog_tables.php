<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('part_catalog_categories')->cascadeOnDelete();
            $table->string('source')->default('tcarservice');
            $table->string('source_url')->unique();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('code')->nullable()->index();
            $table->string('name');
            $table->string('model_label')->nullable()->index();
            $table->string('model_name')->nullable()->index();
            $table->unsignedSmallInteger('year_from')->nullable()->index();
            $table->unsignedSmallInteger('year_to')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('children_scanned_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'model_name']);
        });

        Schema::create('part_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_catalog_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('tcarservice');
            $table->string('source_url')->unique();
            $table->string('part_number')->nullable()->index();
            $table->string('name');
            $table->unsignedSmallInteger('scheme_number')->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('model_label')->nullable()->index();
            $table->string('model_name')->nullable()->index();
            $table->unsignedSmallInteger('year_from')->nullable()->index();
            $table->unsignedSmallInteger('year_to')->nullable()->index();
            $table->string('main_category_code')->nullable()->index();
            $table->string('main_category_name')->nullable();
            $table->string('subcategory_code')->nullable()->index();
            $table->string('subcategory_name')->nullable();
            $table->string('node_name')->nullable();
            $table->text('compatibility_text')->nullable();
            $table->string('condition')->nullable();
            $table->string('quality')->nullable();
            $table->string('availability')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'part_number']);
            $table->index(['model_name', 'year_from', 'year_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_catalog_items');
        Schema::dropIfExists('part_catalog_categories');
    }
};
