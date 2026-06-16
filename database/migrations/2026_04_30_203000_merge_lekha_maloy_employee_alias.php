<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_NAMES = ['Малой', 'Леха Малой', 'Менеджер Малой', 'Леша'];

    private const NEW_NAME = 'Леха';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('cash_transactions')
                ->whereIn('employee', self::OLD_NAMES)
                ->update(['employee' => self::NEW_NAME]);

            $existing = DB::table('sto_employees')
                ->where('cash_employee_name', self::NEW_NAME)
                ->first();

            $source = DB::table('sto_employees')
                ->whereIn('cash_employee_name', self::OLD_NAMES)
                ->orderByRaw('rate IS NULL')
                ->orderByRaw('bonus_calculation IS NULL')
                ->first();

            if (! $existing) {
                $id = DB::table('sto_employees')->insertGetId([
                    'cash_employee_name' => self::NEW_NAME,
                    'first_name' => null,
                    'last_name' => self::NEW_NAME,
                    'position' => $source->position ?? null,
                    'rate' => $source->rate ?? null,
                    'bonus_calculation' => $source->bonus_calculation ?? null,
                    'start_date' => $source->start_date ?? null,
                    'is_active' => $source->is_active ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $id = $existing->id;

                if ($source) {
                    DB::table('sto_employees')
                        ->where('id', $id)
                        ->update([
                            'position' => $existing->position ?: $source->position,
                            'rate' => $existing->rate ?? $source->rate,
                            'bonus_calculation' => $existing->bonus_calculation ?: $source->bonus_calculation,
                            'start_date' => $existing->start_date ?: $source->start_date,
                            'is_active' => (bool) $existing->is_active || (bool) $source->is_active,
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::table('sto_work_order_works')
                ->whereIn('sto_employee_id', DB::table('sto_employees')->whereIn('cash_employee_name', self::OLD_NAMES)->pluck('id'))
                ->update(['sto_employee_id' => $id]);

            DB::table('sto_employees')
                ->whereIn('cash_employee_name', self::OLD_NAMES)
                ->delete();
        });
    }

    public function down(): void
    {
        // Алиасы уже объединены в истории кассы и заказ-нарядов.
    }
};
