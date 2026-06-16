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

            if (! $existing) {
                $source = DB::table('sto_employees')
                    ->whereIn('cash_employee_name', self::OLD_NAMES)
                    ->first();

                DB::table('sto_employees')->insert([
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
            }

            DB::table('sto_employees')
                ->whereIn('cash_employee_name', self::OLD_NAMES)
                ->delete();
        });
    }

    public function down(): void
    {
        // Обратное разделение невозможно восстановить корректно без истории исходных имен.
    }
};
