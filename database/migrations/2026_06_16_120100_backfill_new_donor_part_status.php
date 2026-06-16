<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('donor_car_id')
            ->where('condition_type', 'new')
            ->where(function ($query): void {
                $query
                    ->whereNull('notes')
                    ->orWhereRaw("TRIM(COALESCE(notes, '')) = ''")
                    ->orWhere('notes', "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}");
            })
            ->update([
                'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            ]);
    }

    public function down(): void
    {
        //
    }
};
