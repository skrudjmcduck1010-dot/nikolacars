<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_LABELS = ['Инкассо Женя', 'Приход Из Кассы Женя'];

    private const NEW_LABEL = 'Приход из Кассы и работ';

    public function up(): void
    {
        $now = now();

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => self::NEW_LABEL],
            [
                'operation_type' => 'income',
                'parent_id' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('cash_transactions')
            ->whereIn('label', self::OLD_LABELS)
            ->update([
                'label' => self::NEW_LABEL,
                'updated_at' => $now,
            ]);

        DB::table('valera_cash_transactions')
            ->whereIn('label', self::OLD_LABELS)
            ->update([
                'label' => self::NEW_LABEL,
                'updated_at' => $now,
            ]);

        DB::table('cashbook_labels')
            ->whereIn('name', self::OLD_LABELS)
            ->delete();
    }

    public function down(): void
    {
        $now = now();
        $oldLabel = self::OLD_LABELS[0];

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => $oldLabel],
            [
                'operation_type' => 'income',
                'parent_id' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('cash_transactions')
            ->where('label', self::NEW_LABEL)
            ->update([
                'label' => $oldLabel,
                'updated_at' => $now,
            ]);

        DB::table('valera_cash_transactions')
            ->where('label', self::NEW_LABEL)
            ->update([
                'label' => $oldLabel,
                'updated_at' => $now,
            ]);

        DB::table('cashbook_labels')
            ->where('name', self::NEW_LABEL)
            ->delete();
    }
};
