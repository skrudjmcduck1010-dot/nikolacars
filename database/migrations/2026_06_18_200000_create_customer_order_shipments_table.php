<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_order_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->string('carrier')->default('nova_poshta')->index();
            $table->string('status')->default('draft')->index();
            $table->string('recipient_city_name')->nullable();
            $table->string('recipient_warehouse_name')->nullable();
            $table->string('recipient_warehouse_ref')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('payer_type')->default('Recipient');
            $table->string('payment_method')->default('Cash');
            $table->unsignedSmallInteger('seats_amount')->default(1);
            $table->decimal('weight', 8, 3)->default(1);
            $table->decimal('declared_cost', 14, 2)->default(0);
            $table->text('cargo_description')->nullable();
            $table->string('np_ref')->nullable()->index();
            $table->string('tracking_number')->nullable()->index();
            $table->string('label_url', 2048)->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_order_id', 'carrier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_shipments');
    }
};
