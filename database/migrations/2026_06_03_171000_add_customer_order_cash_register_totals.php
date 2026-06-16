<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_orders', 'paid_cash_uah')) {
                $table->decimal('paid_cash_uah', 14, 2)->default(0)->after('payment_received_amount_uah');
            }

            if (! Schema::hasColumn('customer_orders', 'paid_cash_usd')) {
                $table->decimal('paid_cash_usd', 14, 2)->default(0)->after('paid_cash_uah');
            }

            if (! Schema::hasColumn('customer_orders', 'paid_bank_tov_uah')) {
                $table->decimal('paid_bank_tov_uah', 14, 2)->default(0)->after('paid_cash_usd');
            }

            if (! Schema::hasColumn('customer_orders', 'paid_bank_fop_uah')) {
                $table->decimal('paid_bank_fop_uah', 14, 2)->default(0)->after('paid_bank_tov_uah');
            }

            if (! Schema::hasColumn('customer_orders', 'paid_amount_uah')) {
                $table->decimal('paid_amount_uah', 14, 2)->default(0)->after('paid_bank_fop_uah');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            $columns = collect([
                'paid_cash_uah',
                'paid_cash_usd',
                'paid_bank_tov_uah',
                'paid_bank_fop_uah',
                'paid_amount_uah',
            ])->filter(fn (string $column): bool => Schema::hasColumn('customer_orders', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
