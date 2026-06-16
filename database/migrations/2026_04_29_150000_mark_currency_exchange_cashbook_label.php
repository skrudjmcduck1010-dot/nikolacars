<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cashbook_labels')
            ->whereIn('name', ['Обмен Валют', 'Обмен валют', 'Обмен Валюты', 'Обмен валюты'])
            ->update(['operation_type' => 'exchange']);
    }

    public function down(): void
    {
        DB::table('cashbook_labels')
            ->whereIn('name', ['Обмен Валют', 'Обмен валют', 'Обмен Валюты', 'Обмен валюты'])
            ->update(['operation_type' => 'income']);
    }
};
