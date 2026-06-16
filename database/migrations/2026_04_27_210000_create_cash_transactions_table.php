<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('operation_date')->index();
            $table->decimal('income_bank_uah', 14, 2)->default(0);
            $table->decimal('income_cash_uah', 14, 2)->default(0);
            $table->decimal('income_cash_usd', 14, 2)->default(0);
            $table->decimal('expense_bank_uah', 14, 2)->default(0);
            $table->decimal('expense_cash_uah', 14, 2)->default(0);
            $table->decimal('expense_cash_usd', 14, 2)->default(0);
            $table->string('label')->nullable()->index();
            $table->string('employee')->nullable()->index();
            $table->string('vehicle_vin')->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('source')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
