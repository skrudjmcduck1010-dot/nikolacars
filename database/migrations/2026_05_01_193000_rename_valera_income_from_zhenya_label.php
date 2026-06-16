<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_LABEL = 'Приход Из Кассы Женя';

    private const NEW_LABEL = 'Инкассо Женя';

    public function up(): void
    {
        DB::table('cash_transactions')
            ->where('label', self::OLD_LABEL)
            ->update([
                'label' => self::NEW_LABEL,
                'updated_at' => now(),
            ]);

        DB::table('valera_cash_transactions')
            ->where('label', self::OLD_LABEL)
            ->update([
                'label' => self::NEW_LABEL,
                'updated_at' => now(),
            ]);

        if (DB::table('cashbook_labels')->where('name', self::NEW_LABEL)->exists()) {
            DB::table('cashbook_labels')
                ->where('name', self::NEW_LABEL)
                ->update([
                    'operation_type' => 'income',
                    'updated_at' => now(),
                ]);

            DB::table('cashbook_labels')
                ->where('name', self::OLD_LABEL)
                ->delete();

            return;
        }

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => self::OLD_LABEL],
            [
                'name' => self::NEW_LABEL,
                'operation_type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('cash_transactions')
            ->where('label', self::NEW_LABEL)
            ->update([
                'label' => self::OLD_LABEL,
                'updated_at' => now(),
            ]);

        DB::table('valera_cash_transactions')
            ->where('label', self::NEW_LABEL)
            ->update([
                'label' => self::OLD_LABEL,
                'updated_at' => now(),
            ]);

        if (DB::table('cashbook_labels')->where('name', self::OLD_LABEL)->exists()) {
            DB::table('cashbook_labels')
                ->where('name', self::OLD_LABEL)
                ->update([
                    'operation_type' => 'income',
                    'updated_at' => now(),
                ]);

            DB::table('cashbook_labels')
                ->where('name', self::NEW_LABEL)
                ->delete();

            return;
        }

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => self::NEW_LABEL],
            [
                'name' => self::OLD_LABEL,
                'operation_type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
