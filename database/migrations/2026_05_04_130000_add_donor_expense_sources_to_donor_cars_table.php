<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = [
        'estimated_cost_usd',
        'usa_delivery_price_usd',
        'klaipeda_ukraine_delivery_price_usd',
        'customs_clearance_price_usd',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'donor_expense_sources')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->json('donor_expense_sources')->nullable()->after('customs_clearance_price_usd');
            });
        }

        DB::table('donor_cars')
            ->orderBy('id')
            ->get(['id', 'vin', 'donor_expense_sources', ...self::FIELDS])
            ->each(function (object $donorCar): void {
                $sources = json_decode((string) $donorCar->donor_expense_sources, true);
                $sources = is_array($sources) ? $sources : [];

                foreach (self::FIELDS as $field) {
                    if ($donorCar->{$field} === null || array_key_exists($field, $sources)) {
                        continue;
                    }

                    $amount = round((float) $donorCar->{$field}, 2);

                    if ($this->hasMatchingCashbookExpense((string) $donorCar->vin, $amount)) {
                        $sources[$field] = 'cashbook';
                    } elseif ($this->hasMatchingValeraCashbookExpense((string) $donorCar->vin, $amount)) {
                        $sources[$field] = 'valera_cashbook';
                    }
                }

                if ($sources !== json_decode((string) $donorCar->donor_expense_sources, true)) {
                    DB::table('donor_cars')
                        ->where('id', $donorCar->id)
                        ->update(['donor_expense_sources' => $sources ? json_encode($sources) : null]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'donor_expense_sources')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('donor_expense_sources');
            });
        }
    }

    private function hasMatchingCashbookExpense(string $vin, float $amount): bool
    {
        return DB::table('cash_transactions')
            ->where('vehicle_vin', $vin)
            ->get(['expense_cash_usd', 'expense_bank_uah', 'expense_cash_uah', 'exchange_rate'])
            ->contains(fn (object $transaction): bool => $this->cashbookExpenseAmountUsd($transaction) === $amount);
    }

    private function hasMatchingValeraCashbookExpense(string $vin, float $amount): bool
    {
        return DB::table('valera_cash_transactions')
            ->where('vehicle_vin', $vin)
            ->get(['expense_usd', 'expense_uah'])
            ->contains(fn (object $transaction): bool => $this->valeraCashbookExpenseAmountUsd($transaction) === $amount);
    }

    private function cashbookExpenseAmountUsd(object $transaction): float
    {
        $expenseUsd = (float) $transaction->expense_cash_usd;

        if ($expenseUsd > 0) {
            return round($expenseUsd, 2);
        }

        $expenseUah = (float) $transaction->expense_bank_uah + (float) $transaction->expense_cash_uah;
        $exchangeRate = (float) $transaction->exchange_rate;

        if ($expenseUah <= 0 || $exchangeRate <= 0) {
            return 0.0;
        }

        return round($expenseUah / $exchangeRate, 2);
    }

    private function valeraCashbookExpenseAmountUsd(object $transaction): float
    {
        $expenseUsd = (float) $transaction->expense_usd;

        if ($expenseUsd > 0) {
            return round($expenseUsd, 2);
        }

        $expenseUah = (float) $transaction->expense_uah;

        return $expenseUah > 0 ? round($expenseUah / 43, 2) : 0.0;
    }
};
