<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_parts', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('part_catalog_item_id')->nullable()->index();
            $table->unsignedBigInteger('donor_car_id')->nullable()->index();
            $table->string('donor_vin')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->string('part_number')->nullable()->index();
            $table->string('name')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->json('part_catalog_item_snapshot')->nullable();
            $table->json('related_product_snapshots')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_parts');
    }
};
