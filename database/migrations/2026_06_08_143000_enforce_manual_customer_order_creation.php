<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_NAME = 'customer_orders_number_format_check';

    public function up(): void
    {
        if (! Schema::hasTable('customer_orders')) {
            return;
        }

        if (Schema::hasTable('customer_order_items') && Schema::hasTable('customer_order_history_events')) {
            DB::table('customer_orders')
                ->where('number', 'like', 'CODEX-VERIFY%')
                ->whereNull('created_by')
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('customer_order_items')
                        ->whereColumn('customer_order_items.customer_order_id', 'customer_orders.id');
                })
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('customer_order_history_events')
                        ->whereColumn('customer_order_history_events.customer_order_id', 'customer_orders.id');
                })
                ->delete();
        }

        if (DB::getDriverName() === 'mysql' && ! $this->checkConstraintExists()) {
            DB::statement(sprintf(
                'ALTER TABLE `customer_orders` ADD CONSTRAINT `%s` CHECK (`number` REGEXP %s)',
                self::CHECK_NAME,
                DB::getPdo()->quote('^ORD-[0-9]{8}-[0-9]{4}$'),
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('customer_orders')) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `customer_orders` DROP CHECK `%s`', self::CHECK_NAME));
    }

    private function checkConstraintExists(): bool
    {
        return DB::table('information_schema.check_constraints')
            ->where('CONSTRAINT_SCHEMA', DB::raw('DATABASE()'))
            ->where('CONSTRAINT_NAME', self::CHECK_NAME)
            ->exists();
    }
};
