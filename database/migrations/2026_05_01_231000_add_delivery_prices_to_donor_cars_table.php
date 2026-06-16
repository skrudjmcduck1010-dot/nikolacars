<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'usa_delivery_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->decimal('usa_delivery_price_usd', 12, 2)->nullable()->after('estimated_cost_usd');
            });
        }

        if (! Schema::hasColumn('donor_cars', 'klaipeda_ukraine_delivery_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->decimal('klaipeda_ukraine_delivery_price_usd', 12, 2)->nullable()->after('usa_delivery_price_usd');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'klaipeda_ukraine_delivery_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('klaipeda_ukraine_delivery_price_usd');
            });
        }

        if (Schema::hasColumn('donor_cars', 'usa_delivery_price_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('usa_delivery_price_usd');
            });
        }
    }
};
