<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('length_cm')->nullable()->after('weight');
            $table->unsignedSmallInteger('width_cm')->nullable()->after('length_cm');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('width_cm');
            $table->decimal('afterpayment_amount', 14, 2)->nullable()->after('declared_cost');
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'length_cm',
                'width_cm',
                'height_cm',
                'afterpayment_amount',
            ]);
        });
    }
};
