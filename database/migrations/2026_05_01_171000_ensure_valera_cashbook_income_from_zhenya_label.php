<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('cashbook_labels')
            ->where('name', 'Инкассо Женя')
            ->exists();

        if ($exists) {
            DB::table('cashbook_labels')
                ->where('name', 'Инкассо Женя')
                ->update([
                    'operation_type' => 'income',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('cashbook_labels')->insert([
            'name' => 'Инкассо Женя',
            'operation_type' => 'income',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('cashbook_labels')
            ->where('name', 'Инкассо Женя')
            ->delete();
    }
};
