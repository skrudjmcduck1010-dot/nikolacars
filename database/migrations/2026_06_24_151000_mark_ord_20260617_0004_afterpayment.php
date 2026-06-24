<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $order = DB::table('customer_orders')
            ->where('number', 'ORD-20260617-0004')
            ->first(['id', 'paid_cash_uah', 'paid_bank_fop_uah']);

        if (! $order) {
            return;
        }

        $event = $this->targetEvent((int) $order->id);

        if (! $event) {
            return;
        }

        $values = json_decode((string) $event->new_values, true);

        if (! is_array($values)) {
            return;
        }

        $values['payment_type'] = 'bank_fop';
        $values['is_afterpayment'] = true;

        DB::table('customer_order_history_events')
            ->where('id', $event->id)
            ->update(['new_values' => json_encode($values, JSON_UNESCAPED_UNICODE)]);

        DB::table('customer_orders')
            ->where('id', $order->id)
            ->update([
                'payment_type' => 'bank_fop',
                'payment_received_amount' => 1660,
                'payment_received_amount_uah' => 1660,
                'paid_cash_uah' => max(0, round((float) $order->paid_cash_uah - 1660, 2)),
                'paid_bank_fop_uah' => round((float) $order->paid_bank_fop_uah + 1660, 2),
            ]);
    }

    public function down(): void
    {
        $order = DB::table('customer_orders')
            ->where('number', 'ORD-20260617-0004')
            ->first(['id', 'paid_cash_uah', 'paid_bank_fop_uah']);

        if (! $order) {
            return;
        }

        $event = $this->targetEvent((int) $order->id, 'bank_fop');

        if (! $event) {
            return;
        }

        $values = json_decode((string) $event->new_values, true);

        if (! is_array($values)) {
            return;
        }

        $values['payment_type'] = 'cash_uah';
        unset($values['is_afterpayment']);

        DB::table('customer_order_history_events')
            ->where('id', $event->id)
            ->update(['new_values' => json_encode($values, JSON_UNESCAPED_UNICODE)]);

        DB::table('customer_orders')
            ->where('id', $order->id)
            ->update([
                'payment_type' => 'cash_uah',
                'payment_received_amount' => 1660,
                'payment_received_amount_uah' => 1660,
                'paid_cash_uah' => round((float) $order->paid_cash_uah + 1660, 2),
                'paid_bank_fop_uah' => max(0, round((float) $order->paid_bank_fop_uah - 1660, 2)),
            ]);
    }

    private function targetEvent(int $orderId, ?string $paymentType = 'cash_uah'): ?object
    {
        return DB::table('customer_order_history_events')
            ->where('customer_order_id', $orderId)
            ->where('event_type', 'payment_confirmed')
            ->get(['id', 'new_values'])
            ->first(function (object $event) use ($paymentType): bool {
                $values = json_decode((string) $event->new_values, true);

                if (! is_array($values)) {
                    return false;
                }

                return ($paymentType === null || (string) ($values['payment_type'] ?? '') === $paymentType)
                    && round((float) ($values['payment_received_amount_uah'] ?? 0), 2) === 1660.0;
            });
    }
};
