<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_orders')) {
            return;
        }

        $this->deleteStaleHistoryEvents();
        $this->deleteStaleItems();
    }

    public function down(): void
    {
        //
    }

    private function deleteStaleHistoryEvents(): void
    {
        if (! Schema::hasTable('customer_order_history_events')) {
            return;
        }

        DB::table('customer_order_history_events')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('customer_orders')
                    ->whereColumn('customer_orders.id', 'customer_order_history_events.customer_order_id');
            })
            ->delete();

        DB::table('customer_order_history_events')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('customer_orders')
                    ->whereColumn('customer_orders.id', 'customer_order_history_events.customer_order_id')
                    ->whereColumn('customer_order_history_events.created_at', '<', 'customer_orders.created_at');
            })
            ->delete();
    }

    private function deleteStaleItems(): void
    {
        if (! Schema::hasTable('customer_order_items')) {
            return;
        }

        DB::table('customer_order_items')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('customer_orders')
                    ->whereColumn('customer_orders.id', 'customer_order_items.customer_order_id');
            })
            ->delete();

        DB::table('customer_order_items')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('customer_orders')
                    ->whereColumn('customer_orders.id', 'customer_order_items.customer_order_id')
                    ->whereColumn('customer_order_items.created_at', '<', 'customer_orders.created_at');
            })
            ->delete();
    }
};
