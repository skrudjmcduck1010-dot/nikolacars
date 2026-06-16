<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbook_labels', function (Blueprint $table): void {
            $table->string('operation_type')->default('income')->after('name');
        });

        DB::table('cash_transactions')
            ->select('label')
            ->selectRaw('COALESCE(SUM(income_bank_uah + income_cash_uah + income_cash_usd), 0) as income_total')
            ->selectRaw('COALESCE(SUM(expense_bank_uah + expense_cash_uah + expense_cash_usd), 0) as expense_total')
            ->whereNotNull('label')
            ->where('label', '<>', '')
            ->groupBy('label')
            ->get()
            ->each(function (object $row): void {
                DB::table('cashbook_labels')
                    ->where('name', $row->label)
                    ->update([
                        'operation_type' => (float) $row->expense_total > (float) $row->income_total ? 'expense' : 'income',
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('cashbook_labels', function (Blueprint $table): void {
            $table->dropColumn('operation_type');
        });
    }
};
