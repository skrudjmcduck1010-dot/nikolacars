<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('part_sales');

        Schema::create('part_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('donor_car_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 64)->default('nikolacars');
            $table->string('code')->nullable()->index();
            $table->string('part_number')->nullable()->index();
            $table->string('name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('sold_at')->nullable()->index();
            $table->string('document_number')->nullable()->index();
            $table->string('counterparty')->nullable();
            $table->string('donor_vin', 17)->nullable()->index();
            $table->text('category_path')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('source_row_hash', 64)->unique();
            $table->timestamps();

            $table->index(['source', 'sold_at']);
            $table->index(['part_catalog_item_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_sales');
    }
};
