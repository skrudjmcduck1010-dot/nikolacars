<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'Инкассо Женя'],
            [
                'operation_type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('valera_cash_transactions')
            ->where(function ($query): void {
                $query
                    ->where('purpose', 'like', '%инкассо%')
                    ->orWhere('project', 'like', '%инкассо%')
                    ->orWhere('category', 'like', '%инкассо%')
                    ->orWhere('operation', 'like', '%инкассо%')
                    ->orWhere('person', 'like', '%инкассо%');
            })
            ->where(function ($query): void {
                $query
                    ->where('operation_type', 'Приход')
                    ->orWhere('amount_uah', '>', 0)
                    ->orWhere('amount_usd', '>', 0);
            })
            ->update([
                'label' => 'Инкассо Женя',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('valera_cash_transactions')
            ->where('label', 'Инкассо Женя')
            ->where(function ($query): void {
                $query
                    ->where('purpose', 'like', '%инкассо%')
                    ->orWhere('project', 'like', '%инкассо%')
                    ->orWhere('category', 'like', '%инкассо%')
                    ->orWhere('operation', 'like', '%инкассо%')
                    ->orWhere('person', 'like', '%инкассо%');
            })
            ->update([
                'label' => null,
                'updated_at' => now(),
            ]);
    }
};
