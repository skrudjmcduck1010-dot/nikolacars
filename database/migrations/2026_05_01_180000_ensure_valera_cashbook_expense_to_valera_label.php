<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'В кассу Валеры'],
            [
                'operation_type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('cashbook_labels')
            ->where('name', 'В кассу Валеры')
            ->delete();
    }
};
