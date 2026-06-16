<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('donor_car_id')
            ->whereIn('used_condition', ['good', 'with_nuances', 'defective'])
            ->where(function ($query): void {
                $query
                    ->whereNull('notes')
                    ->orWhereRaw("TRIM(COALESCE(notes, '')) = ''")
                    ->orWhere('notes', "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}");
            })
            ->update([
                'notes' => DB::raw("CASE used_condition
                    WHEN 'good' THEN 'Без повреждений'
                    WHEN 'with_nuances' THEN 'Легкие повреждения'
                    WHEN 'defective' THEN 'Сильные повреждения'
                    ELSE notes
                END"),
            ]);
    }

    public function down(): void
    {
        //
    }
};
