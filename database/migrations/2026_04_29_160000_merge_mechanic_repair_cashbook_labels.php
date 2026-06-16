<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_LABELS = ['+', '1', '2'];

    private const NEW_LABEL = '';

    public function up(): void
    {
        $now = now();
        $operationType = DB::table('cashbook_labels')
            ->whereIn('name', self::OLD_LABELS)
            ->value('operation_type') ?? 'income';

        DB::table('cashbook_labels')->insertOrIgnore([
            'name' => self::NEW_LABEL,
            'operation_type' => $operationType,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('cash_transactions')
            ->whereIn('label', self::OLD_LABELS)
            ->update(['label' => self::NEW_LABEL]);

        DB::table('cashbook_labels')
            ->whereIn('name', self::OLD_LABELS)
            ->delete();
    }

    public function down(): void
    {
        DB::table('cash_transactions')
            ->where('label', self::NEW_LABEL)
            ->update(['label' => '+']);

        DB::table('cashbook_labels')->insertOrIgnore([
            'name' => '+',
            'operation_type' => 'income',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cashbook_labels')
            ->where('name', self::NEW_LABEL)
            ->delete();
    }
};
