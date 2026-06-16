<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TERMS = [
        'хостинг',
        'nikolacars',
        'nikola-cars',
        'раскрутка',
        'сайт',
        'сайта',
        'сайту',
    ];

    public function up(): void
    {
        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'Сайт'],
            [
                'operation_type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $ids = DB::table('valera_cash_transactions')
            ->where('operation_type', '')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => $this->matches((string) $row->purpose))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('valera_cash_transactions')
                ->whereIn('id', $ids)
                ->update([
                    'label' => 'Сайт',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('valera_cash_transactions')
            ->where('operation_type', '')
            ->where('label', 'Сайт')
            ->whereNotNull('purpose')
            ->get(['id', 'purpose'])
            ->filter(fn (object $row): bool => $this->matches((string) $row->purpose))
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

    private function matches(string $purpose): bool
    {
        foreach (self::TERMS as $term) {
            if (mb_stripos($purpose, $term) !== false) {
                return true;
            }
        }

        return false;
    }
};
