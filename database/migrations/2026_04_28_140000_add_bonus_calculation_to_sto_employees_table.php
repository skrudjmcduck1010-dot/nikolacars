<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_employees', function (Blueprint $table) {
            $table->string('bonus_calculation')->nullable()->after('rate');
        });

        DB::table('sto_employees')
            ->where('last_name', 'Зинченко')
            ->where('first_name', 'Евгений')
            ->update([
                'bonus_calculation' => 'zinchenko_eugene_profit_7pct',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('sto_employees', function (Blueprint $table) {
            $table->dropColumn('bonus_calculation');
        });
    }
};
