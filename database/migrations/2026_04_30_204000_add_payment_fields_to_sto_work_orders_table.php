<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table): void {
            $table->decimal('paid_cash_uah', 14, 2)->default(0)->after('total_cost_uah');
            $table->decimal('paid_cash_usd', 14, 2)->default(0)->after('paid_cash_uah');
            $table->decimal('paid_bank_uah', 14, 2)->default(0)->after('paid_cash_usd');
            $table->decimal('paid_amount_uah', 14, 2)->default(0)->after('paid_bank_uah');
            $table->timestamp('payment_confirmed_at')->nullable()->after('paid_amount_uah');
        });
    }

    public function down(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'paid_cash_uah',
                'paid_cash_usd',
                'paid_bank_uah',
                'paid_amount_uah',
                'payment_confirmed_at',
            ]);
        });
    }
};
