<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')
            ->where('name', 'Продажа ЗЧР Интернет')
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
