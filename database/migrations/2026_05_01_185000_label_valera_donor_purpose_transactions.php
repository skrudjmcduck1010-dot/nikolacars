<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'Донор'],
            [
                'operation_type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $ids = DB::table('valera_cash_transactions')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => mb_stripos((string) $row->purpose, 'донор') !== false)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('valera_cash_transactions')
                ->whereIn('id', $ids)
                ->update([
                    'label' => 'Донор',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('valera_cash_transactions')
            ->where('label', 'Донор')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => mb_stripos((string) $row->purpose, 'донор') !== false)
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
