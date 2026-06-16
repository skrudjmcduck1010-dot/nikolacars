<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_employees', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('is_active')
                ->unique()
                ->constrained('users')
                ->nullOnDelete();
        });

        $userId = DB::table('users')
            ->where('email', 'z080182@gmail.com')
            ->value('id');

        if ($userId) {
            DB::table('sto_employees')
                ->where('cash_employee_name', 'Зинченко Антон')
                ->whereNull('user_id')
                ->update([
                    'user_id' => $userId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('sto_employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
