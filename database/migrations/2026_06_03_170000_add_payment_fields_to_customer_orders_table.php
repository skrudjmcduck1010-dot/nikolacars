<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            $table->string('payment_type')->nullable()->after('currency');
            $table->decimal('payment_received_amount', 14, 2)->nullable()->after('payment_type');
            $table->decimal('payment_received_amount_uah', 14, 2)->nullable()->after('payment_received_amount');
            $table->decimal('paid_cash_uah', 14, 2)->default(0)->after('payment_received_amount_uah');
            $table->decimal('paid_cash_usd', 14, 2)->default(0)->after('paid_cash_uah');
            $table->decimal('paid_bank_tov_uah', 14, 2)->default(0)->after('paid_cash_usd');
            $table->decimal('paid_bank_fop_uah', 14, 2)->default(0)->after('paid_bank_tov_uah');
            $table->decimal('paid_amount_uah', 14, 2)->default(0)->after('paid_bank_fop_uah');
            $table->timestamp('payment_confirmed_at')->nullable()->after('paid_amount_uah');
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_type',
                'payment_received_amount',
                'payment_received_amount_uah',
                'paid_cash_uah',
                'paid_cash_usd',
                'paid_bank_tov_uah',
                'paid_bank_fop_uah',
                'paid_amount_uah',
                'payment_confirmed_at',
            ]);
        });
    }
};
