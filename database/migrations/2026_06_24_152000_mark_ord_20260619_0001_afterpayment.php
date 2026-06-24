<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $order = DB::table('customer_orders')
            ->where('number', 'ORD-20260619-0001')
            ->first(['id', 'payment_confirmed_at']);

        if (! $order) {
            return;
        }

        $exists = DB::table('customer_order_history_events')
            ->where('customer_order_id', $order->id)
            ->where('event_type', 'payment_confirmed')
            ->get(['new_values'])
            ->contains(function (object $event): bool {
                $values = json_decode((string) $event->new_values, true);

                return is_array($values)
                    && (string) ($values['payment_type'] ?? '') === 'bank_fop'
                    && round((float) ($values['payment_received_amount_uah'] ?? 0), 2) === 380.0
                    && (bool) ($values['is_afterpayment'] ?? false);
            });

        if (! $exists) {
            DB::table('customer_order_history_events')->insert([
                'customer_order_id' => $order->id,
                'event_type' => 'payment_confirmed',
                'title' => 'Оплата подтверждена',
                'description' => 'БезНал ФОП, получено 380 грн (наложка)',
                'old_values' => json_encode([], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode([
                    'payment_type' => 'bank_fop',
                    'payment_received_amount' => 380,
                    'payment_received_amount_uah' => 380,
                    'paid_amount_uah' => 680,
                    'is_afterpayment' => true,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('customer_orders')
            ->where('id', $order->id)
            ->update([
                'payment_type' => 'bank_fop',
                'payment_received_amount' => 380,
                'payment_received_amount_uah' => 380,
                'paid_bank_fop_uah' => 680,
                'paid_amount_uah' => 680,
                'payment_confirmed_at' => $order->payment_confirmed_at ?: now(),
            ]);
    }

    public function down(): void
    {
        $order = DB::table('customer_orders')
            ->where('number', 'ORD-20260619-0001')
            ->first(['id']);

        if (! $order) {
            return;
        }

        DB::table('customer_order_history_events')
            ->where('customer_order_id', $order->id)
            ->where('event_type', 'payment_confirmed')
            ->get(['id', 'new_values'])
            ->each(function (object $event): void {
                $values = json_decode((string) $event->new_values, true);

                if (
                    is_array($values)
                    && (string) ($values['payment_type'] ?? '') === 'bank_fop'
                    && round((float) ($values['payment_received_amount_uah'] ?? 0), 2) === 380.0
                    && (bool) ($values['is_afterpayment'] ?? false)
                ) {
                    DB::table('customer_order_history_events')
                        ->where('id', $event->id)
                        ->delete();
                }
            });

        DB::table('customer_orders')
            ->where('id', $order->id)
            ->update([
                'payment_type' => 'bank_fop',
                'payment_received_amount' => 300,
                'payment_received_amount_uah' => 300,
                'paid_bank_fop_uah' => 300,
                'paid_amount_uah' => 300,
                'payment_confirmed_at' => null,
            ]);
    }
};
