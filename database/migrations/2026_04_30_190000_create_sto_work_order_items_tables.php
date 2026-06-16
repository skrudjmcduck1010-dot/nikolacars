<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sto_work_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price_uah', 14, 2)->default(0);
            $table->decimal('total_price_uah', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sto_work_order_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sto_work_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price_uah', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_work_order_works');
        Schema::dropIfExists('sto_work_order_parts');
    }
};
