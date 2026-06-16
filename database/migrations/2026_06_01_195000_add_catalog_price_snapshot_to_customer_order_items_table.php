<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_order_items', 'catalog_original_price_amount')) {
                $table->decimal('catalog_original_price_amount', 14, 2)->nullable()->after('usd_exchange_rate');
            }

            if (! Schema::hasColumn('customer_order_items', 'catalog_original_currency')) {
                $table->string('catalog_original_currency', 3)->nullable()->after('catalog_original_price_amount');
            }

            if (! Schema::hasColumn('customer_order_items', 'catalog_price_snapshot_taken')) {
                $table->boolean('catalog_price_snapshot_taken')->default(false)->after('catalog_original_currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_order_items', 'catalog_original_currency')) {
                $table->dropColumn('catalog_original_currency');
            }

            if (Schema::hasColumn('customer_order_items', 'catalog_original_price_amount')) {
                $table->dropColumn('catalog_original_price_amount');
            }

            if (Schema::hasColumn('customer_order_items', 'catalog_price_snapshot_taken')) {
                $table->dropColumn('catalog_price_snapshot_taken');
            }
        });
    }
};
