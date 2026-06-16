<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('main');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('zone')->nullable();
            $table->string('row')->nullable();
            $table->string('shelf')->nullable();
            $table->string('cell')->nullable();
            $table->string('full_code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('donor_cars', function (Blueprint $table) {
            $table->id();
            $table->string('vin')->unique();
            $table->string('status')->default('in_transit');
            $table->string('brand')->default('Tesla');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('supplier');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('external_sku')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('donor_car_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('compatibility')->nullable();
            $table->string('model')->nullable();
            $table->string('generation')->nullable();
            $table->string('side')->nullable();
            $table->string('condition_grade')->default('A');
            $table->string('testing_status')->default('not_tested');
            $table->string('unit')->default('pcs');
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('barcode')->nullable()->unique();
            $table->string('qr_code')->nullable()->unique();
            $table->string('main_image')->nullable();
            $table->json('images_json')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('available_quantity')->default(0);
            $table->string('condition_grade')->default('A');
            $table->string('testing_status')->default('not_tested');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'location_id', 'condition_grade', 'testing_status'], 'stock_items_unique_slot');
        });

        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->unsignedInteger('quantity');
            $table->string('reason')->nullable();
            $table->string('document_number')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('customer_order_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('movements');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('products');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('donor_cars');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
    }
};
