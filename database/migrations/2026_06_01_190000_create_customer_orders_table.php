<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('status')->default('processing')->index();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_phone')->nullable()->index();
            $table->string('client_first_name')->nullable();
            $table->string('client_last_name')->nullable();
            $table->text('note')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('part_number')->nullable()->index();
            $table->string('code')->nullable()->index();
            $table->string('donor_vin')->nullable()->index();
            $table->text('category')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('source_url')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_items');
        Schema::dropIfExists('customer_orders');
    }
};
