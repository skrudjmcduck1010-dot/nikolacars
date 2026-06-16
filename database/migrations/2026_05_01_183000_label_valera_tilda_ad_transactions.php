<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => ''],
            [
                'operation_type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('valera_cash_transactions')
            ->where(function ($query): void {
                foreach (['purpose', 'project', 'category', 'operation', 'person'] as $column) {
                    $query->orWhere($column, 'like', '%Тільда%');
                }
            })
            ->update([
                'label' => '',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('valera_cash_transactions')
            ->where('label', '')
            ->where(function ($query): void {
                foreach (['purpose', 'project', 'category', 'operation', 'person'] as $column) {
                    $query->orWhere($column, 'like', '%Тільда%');
                }
            })
            ->update([
                'label' => null,
                'updated_at' => now(),
            ]);
    }
};
