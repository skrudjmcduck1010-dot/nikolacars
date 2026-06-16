<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'warehouse_arrival_date')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->date('warehouse_arrival_date')->nullable()->after('purchase_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'warehouse_arrival_date')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('warehouse_arrival_date');
            });
        }
    }
};
