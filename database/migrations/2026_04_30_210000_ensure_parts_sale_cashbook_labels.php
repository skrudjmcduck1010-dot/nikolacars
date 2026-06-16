<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([' ', '  ', ' '] as $label) {
            DB::table('cashbook_labels')->updateOrInsert(
                ['name' => $label],
                [
                    'operation_type' => 'income',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        //
    }
};
