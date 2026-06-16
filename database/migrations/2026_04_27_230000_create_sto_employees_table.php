<?php

use App\Models\StoEmployee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_employees', function (Blueprint $table) {
            $table->id();
            $table->string('cash_employee_name')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        if (! Schema::hasTable('cash_transactions')) {
            return;
        }

        DB::table('cash_transactions')
            ->select('employee')
            ->whereNotNull('employee')
            ->where('employee', '<>', '')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['ЗП'])
            ->distinct()
            ->orderBy('employee')
            ->pluck('employee')
            ->each(function (string $employee): void {
                $names = StoEmployee::splitCashName($employee);

                DB::table('sto_employees')->insert([
                    'cash_employee_name' => $employee,
                    'first_name' => $names['first_name'],
                    'last_name' => $names['last_name'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_employees');
    }
};
