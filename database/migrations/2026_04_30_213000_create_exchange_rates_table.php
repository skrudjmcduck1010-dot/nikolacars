<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 12, 6);
            $table->string('source')->default('nbu');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'rate_date']);
            $table->index(['currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
