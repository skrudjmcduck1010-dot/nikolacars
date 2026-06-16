<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_order_items')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            foreach (['source_url', 'image_url'] as $column) {
                if (Schema::hasColumn('customer_order_items', $column)) {
                    DB::statement("ALTER TABLE `customer_order_items` MODIFY `{$column}` TEXT NULL");
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            return;
        }

        foreach (['source_url', 'image_url'] as $column) {
            if (Schema::hasColumn('customer_order_items', $column)) {
                DB::statement("ALTER TABLE customer_order_items ALTER COLUMN {$column} TYPE TEXT");
            }
        }
    }

    public function down(): void
    {
        //
    }
};
