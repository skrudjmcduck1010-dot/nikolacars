<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->decimal('income_usd', 14, 2)->default(0);
            $table->decimal('income_uah', 14, 2)->default(0);
            $table->decimal('expense_usd', 14, 2)->default(0);
            $table->decimal('expense_uah', 14, 2)->default(0);
            $table->string('employee')->nullable()->index();
            $table->text('comment')->nullable();
        });

        DB::table('valera_cash_transactions')
            ->orderBy('id')
            ->get(['id', 'amount_usd', 'amount_uah', 'purpose', 'project', 'person'])
            ->each(function (object $transaction): void {
                $purpose = trim((string) $transaction->purpose);
                $project = trim((string) $transaction->project);
                $comment = collect([$purpose, $project])
                    ->filter(fn (string $value): bool => $value !== '')
                    ->implode(' - ');

                DB::table('valera_cash_transactions')
                    ->where('id', $transaction->id)
                    ->update([
                        'income_usd' => max((float) $transaction->amount_usd, 0),
                        'income_uah' => max((float) $transaction->amount_uah, 0),
                        'expense_usd' => abs(min((float) $transaction->amount_usd, 0)),
                        'expense_uah' => abs(min((float) $transaction->amount_uah, 0)),
                        'employee' => trim((string) $transaction->person) ?: null,
                        'comment' => $comment !== '' ? $comment : null,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'income_usd',
                'income_uah',
                'expense_usd',
                'expense_uah',
                'employee',
                'comment',
            ]);
        });
    }
};
