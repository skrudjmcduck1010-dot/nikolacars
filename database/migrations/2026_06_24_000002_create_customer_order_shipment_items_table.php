<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_order_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_order_shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_order_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_order_shipment_id', 'customer_order_item_id'], 'customer_order_shipment_items_unique');
            $table->index('customer_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_shipment_items');
    }
};
