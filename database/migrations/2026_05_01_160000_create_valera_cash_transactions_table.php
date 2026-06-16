<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valera_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('operation_date')->index();
            $table->string('operation_type')->nullable()->index();
            $table->decimal('amount_usd', 14, 2)->default(0);
            $table->decimal('amount_uah', 14, 2)->default(0);
            $table->text('purpose')->nullable();
            $table->string('project')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->string('operation')->nullable()->index();
            $table->string('person')->nullable()->index();
            $table->decimal('balance_usd', 14, 2)->nullable();
            $table->decimal('balance_uah', 14, 2)->nullable();
            $table->string('source')->nullable();
            $table->string('source_sheet')->nullable()->index();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valera_cash_transactions');
    }
};
