<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('customer_order_shipments', function (Blueprint $table): void {
                $table->dropUnique(['customer_order_id', 'carrier']);
            });
        } catch (\Throwable) {
            // A previous interrupted deploy may have already removed the unique key.
        }
    }

    public function down(): void
    {
        $duplicate = DB::table('customer_order_shipments')
            ->select('customer_order_id', 'carrier')
            ->groupBy('customer_order_id', 'carrier')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $duplicate) {
            Schema::table('customer_order_shipments', function (Blueprint $table): void {
                $table->unique(['customer_order_id', 'carrier']);
            });
        }
    }
};
