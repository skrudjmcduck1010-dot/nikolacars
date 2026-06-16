<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $blankEmployees = DB::table('sto_employees')
            ->whereRaw("TRIM(COALESCE(cash_employee_name, '')) = ''")
            ->pluck('id');

        if ($blankEmployees->isEmpty()) {
            return;
        }

        $referencedEmployeeIds = Schema::hasTable('sto_work_order_works')
            ? DB::table('sto_work_order_works')
                ->whereIn('sto_employee_id', $blankEmployees)
                ->pluck('sto_employee_id')
                ->unique()
            : collect();

        DB::table('sto_employees')
            ->whereIn('id', $blankEmployees->diff($referencedEmployeeIds))
            ->delete();

        if ($referencedEmployeeIds->isNotEmpty()) {
            DB::table('sto_employees')
                ->whereIn('id', $referencedEmployeeIds)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Removed invalid blank employees cannot be reconstructed safely.
    }
};
