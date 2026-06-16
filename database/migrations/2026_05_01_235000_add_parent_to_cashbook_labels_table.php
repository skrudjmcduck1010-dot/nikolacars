<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARENT_LABEL = 'СТО';

    private const CHILD_LABELS = [
        'Аренда',
        'Инструмент',
        'Коммунальные',
        'Налоги',
        'Продукты',
        'Прочие',
        '',
        '',
        ' ',
        'Сайт',
        'Связь',
        ' ',
    ];

    public function up(): void
    {
        Schema::table('cashbook_labels', function (Blueprint $table): void {
            $table
                ->foreignId('parent_id')
                ->nullable()
                ->after('operation_type')
                ->constrained('cashbook_labels')
                ->nullOnDelete();
        });

        $now = now();

        DB::table('cashbook_labels')->insertOrIgnore([
            [
                'name' => self::PARENT_LABEL,
                'operation_type' => 'expense',
                'parent_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('cashbook_labels')
            ->where('name', self::PARENT_LABEL)
            ->update([
                'operation_type' => 'expense',
                'parent_id' => null,
                'updated_at' => $now,
            ]);

        $parentId = DB::table('cashbook_labels')
            ->where('name', self::PARENT_LABEL)
            ->value('id');

        foreach (self::CHILD_LABELS as $label) {
            DB::table('cashbook_labels')->insertOrIgnore([
                [
                    'name' => $label,
                    'operation_type' => 'expense',
                    'parent_id' => $parentId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('cashbook_labels')
                ->where('name', $label)
                ->update([
                    'operation_type' => 'expense',
                    'parent_id' => $parentId,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('cashbook_labels')
            ->whereIn('name', self::CHILD_LABELS)
            ->update(['parent_id' => null]);

        Schema::table('cashbook_labels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
