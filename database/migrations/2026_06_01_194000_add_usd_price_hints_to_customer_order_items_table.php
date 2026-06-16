<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_order_items', 'unit_price_usd_hint')) {
                $table->decimal('unit_price_usd_hint', 14, 2)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('customer_order_items', 'total_price_usd_hint')) {
                $table->decimal('total_price_usd_hint', 14, 2)->nullable()->after('unit_price_usd_hint');
            }

            if (! Schema::hasColumn('customer_order_items', 'usd_exchange_rate')) {
                $table->decimal('usd_exchange_rate', 14, 6)->nullable()->after('total_price_usd_hint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_order_items', 'usd_exchange_rate')) {
                $table->dropColumn('usd_exchange_rate');
            }

            if (Schema::hasColumn('customer_order_items', 'total_price_usd_hint')) {
                $table->dropColumn('total_price_usd_hint');
            }

            if (Schema::hasColumn('customer_order_items', 'unit_price_usd_hint')) {
                $table->dropColumn('unit_price_usd_hint');
            }
        });
    }
};
