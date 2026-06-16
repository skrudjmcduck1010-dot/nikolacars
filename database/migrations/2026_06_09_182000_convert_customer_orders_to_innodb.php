<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('customer_orders')) {
            return;
        }

        $engine = DB::table('information_schema.tables')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'customer_orders')
            ->value('engine');

        if (is_string($engine) && strtolower($engine) === 'innodb') {
            return;
        }

        DB::statement('ALTER TABLE `customer_orders` ENGINE=InnoDB');
    }

    public function down(): void
    {
        //
    }
};
