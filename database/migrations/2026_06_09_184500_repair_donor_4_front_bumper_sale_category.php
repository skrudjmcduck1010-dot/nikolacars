<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('part_sales')
            ->where('donor_car_id', 4)
            ->where(function ($query): void {
                $query
                    ->where('part_number', '1084168-S0-E')
                    ->orWhereRaw("replace(upper(coalesce(part_number, '')), '-', '') = ?", ['1084168S0E']);
            })
            ->where(function ($query): void {
                $query
                    ->where('category_path', 'like', 'Tesla;%')
                    ->orWhere('category_path', 'BODY / Bumper and Fascia / Front Bumper Fascia');
            })
            ->update([
                'category_path' => 'Кузов / Бампер и облицовка бампера / Облицовка переднего бампера',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
