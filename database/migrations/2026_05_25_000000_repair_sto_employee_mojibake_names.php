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
            $this->repairCashbookEmployees();
            $this->repairStoEmployees();
        });
    }

    public function down(): void
    {
        // Data repair is intentionally one-way.
    }

    private function repairCashbookEmployees(): void
    {
        DB::table('cash_transactions')
            ->select('employee')
            ->whereNotNull('employee')
            ->where('employee', '<>', '')
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
    }

    private function repairStoEmployees(): void
    {
        DB::table('sto_employees')
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
                    $this->mergeStoEmployee($employee, $target);

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
    }

    private function mergeStoEmployee(object $source, object $target): void
    {
        if (Schema::hasTable('sto_work_order_works')) {
            DB::table('sto_work_order_works')
                ->where('sto_employee_id', $source->id)
                ->update(['sto_employee_id' => $target->id]);
        }

        DB::table('sto_employees')
            ->where('id', $target->id)
            ->update([
                'position' => $target->position ?: $source->position,
                'rate' => $target->rate ?? $source->rate,
                'bonus_calculation' => $target->bonus_calculation ?: $source->bonus_calculation,
                'start_date' => $target->start_date ?: $source->start_date,
                'is_active' => (bool) $target->is_active || (bool) $source->is_active,
                'updated_at' => now(),
            ]);

        DB::table('sto_employees')
            ->where('id', $source->id)
            ->delete();
    }
};
