<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->decimal('cancelled_amount_uah', 14, 2)->default(0)->after('expense_cash_usd');
            $table->decimal('cancelled_amount_usd', 14, 2)->default(0)->after('cancelled_amount_uah');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_amount_usd');
        });

        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->decimal('cancelled_amount_uah', 14, 2)->default(0)->after('expense_uah');
            $table->decimal('cancelled_amount_usd', 14, 2)->default(0)->after('cancelled_amount_uah');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_amount_usd');
        });

        Schema::table('valera_cashbook_transfers', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'cancelled_amount_uah',
                'cancelled_amount_usd',
                'cancelled_at',
            ]);
        });

        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'cancelled_amount_uah',
                'cancelled_amount_usd',
                'cancelled_at',
            ]);
        });

        Schema::table('valera_cashbook_transfers', function (Blueprint $table): void {
            $table->dropColumn('cancelled_at');
        });
    }
};
