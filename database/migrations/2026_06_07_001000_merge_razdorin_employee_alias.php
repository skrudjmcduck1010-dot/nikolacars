<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_NAME = 'Раздорин';

    private const NEW_NAME = 'Раздорин Влад';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('cash_transactions')
                ->where('employee', self::OLD_NAME)
                ->update(['employee' => self::NEW_NAME]);

            $target = DB::table('sto_employees')
                ->where('cash_employee_name', self::NEW_NAME)
                ->first();

            $source = DB::table('sto_employees')
                ->where('cash_employee_name', self::OLD_NAME)
                ->first();

            if (! $target && ! $source) {
                return;
            }

            if (! $target) {
                DB::table('sto_employees')
                    ->where('id', $source->id)
                    ->update([
                        'cash_employee_name' => self::NEW_NAME,
                        'first_name' => null,
                        'last_name' => self::NEW_NAME,
                        'updated_at' => now(),
                    ]);

                return;
            }

            if (! $source) {
                return;
            }

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
        });
    }

    public function down(): void
    {
        // Data repair is intentionally one-way.
    }
};
