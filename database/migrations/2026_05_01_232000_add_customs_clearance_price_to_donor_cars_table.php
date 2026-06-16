<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'customs_clearance_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->decimal('customs_clearance_price_usd', 12, 2)->nullable()->after('klaipeda_ukraine_delivery_price_usd');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'customs_clearance_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('customs_clearance_price_usd');
            });
        }
    }
};
