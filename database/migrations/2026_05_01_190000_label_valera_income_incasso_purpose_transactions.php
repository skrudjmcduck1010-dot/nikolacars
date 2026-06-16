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

        $ids = DB::table('valera_cash_transactions')
            ->where('operation_type', 'Приход')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => mb_stripos((string) $row->purpose, 'инкассо') !== false)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('valera_cash_transactions')
                ->whereIn('id', $ids)
                ->update([
                    'label' => 'Инкассо Женя',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('valera_cash_transactions')
            ->where('operation_type', 'Приход')
            ->where('label', 'Инкассо Женя')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => mb_stripos((string) $row->purpose, 'инкассо') !== false)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('valera_cash_transactions')
                ->whereIn('id', $ids)
                ->update([
                    'label' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
