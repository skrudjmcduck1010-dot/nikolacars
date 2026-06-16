<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'part_origin')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('part_origin')->nullable()->after('donor_car_id')->index();
            });
        }

        DB::table('products')
            ->whereNotNull('donor_car_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('part_origin')
                    ->orWhere('part_origin', '');
            })
            ->update(['part_origin' => Product::PART_ORIGIN_ORIGINAL]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'part_origin')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('part_origin');
            });
        }
    }
};
