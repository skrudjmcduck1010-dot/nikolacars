<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('status')->default('in_work')->index();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');
            $table->string('client_phone')->nullable();
            $table->string('car_model')->nullable();
            $table->unsignedSmallInteger('car_year')->nullable();
            $table->string('drive_type')->nullable();
            $table->string('vin')->nullable()->index();
            $table->string('license_plate')->nullable()->index();
            $table->unsignedInteger('mileage')->nullable();
            $table->date('opened_at')->index();
            $table->date('planned_finished_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('customer_request')->nullable();
            $table->text('work_description')->nullable();
            $table->text('parts_note')->nullable();
            $table->decimal('labor_cost_uah', 14, 2)->default(0);
            $table->decimal('parts_cost_uah', 14, 2)->default(0);
            $table->decimal('discount_uah', 14, 2)->default(0);
            $table->decimal('total_cost_uah', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_work_orders');
    }
};
