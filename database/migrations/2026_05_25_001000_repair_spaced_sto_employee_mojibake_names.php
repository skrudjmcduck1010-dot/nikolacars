<?php

use App\Models\CashTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('cash_transactions')
                ->select('employee')
                ->whereNotNull('employee')
                ->where('employee', 'like', 'Р %')
                ->distinct()
                ->pluck('employee')
                ->each(function (string $employee): void {
                    $fixed = CashTransaction::normalizeEmployeeName($employee);

                    if ($fixed === null || $fixed === $employee) {
                        return;
                    }

                    DB::table('cash_transactions')
                        ->where('employee', $employee)
                        ->update(['employee' => $fixed]);
                });

            DB::table('sto_employees')
                ->where('cash_employee_name', 'like', 'Р %')
                ->orderBy('id')
                ->get()
                ->each(function (object $employee): void {
                    $fixed = CashTransaction::normalizeEmployeeName($employee->cash_employee_name);

                    if ($fixed === null || $fixed === $employee->cash_employee_name) {
                        return;
                    }

                    $target = DB::table('sto_employees')
                        ->where('cash_employee_name', $fixed)
                        ->where('id', '<>', $employee->id)
                        ->first();

                    if ($target) {
                        if (Schema::hasTable('sto_work_order_works')) {
                            DB::table('sto_work_order_works')
                                ->where('sto_employee_id', $employee->id)
                                ->update(['sto_employee_id' => $target->id]);
                        }

                        DB::table('sto_employees')
                            ->where('id', $target->id)
                            ->update([
                                'position' => $target->position ?: $employee->position,
                                'rate' => $target->rate ?? $employee->rate,
                                'bonus_calculation' => $target->bonus_calculation ?: $employee->bonus_calculation,
                                'start_date' => $target->start_date ?: $employee->start_date,
                                'is_active' => (bool) $target->is_active || (bool) $employee->is_active,
                                'updated_at' => now(),
                            ]);

                        DB::table('sto_employees')
                            ->where('id', $employee->id)
                            ->delete();

                        return;
                    }

                    DB::table('sto_employees')
                        ->where('id', $employee->id)
                        ->update([
                            'cash_employee_name' => $fixed,
                            'first_name' => null,
                            'last_name' => $fixed,
                            'updated_at' => now(),
                        ]);
                });
        });
    }

    public function down(): void
    {
        // Data repair is intentionally one-way.
    }
};
