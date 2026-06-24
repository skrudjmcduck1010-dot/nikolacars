<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setAfterpaymentFlag(true);
    }

    public function down(): void
    {
        $this->setAfterpaymentFlag(false);
    }

    private function setAfterpaymentFlag(bool $enabled): void
    {
        $orderId = DB::table('customer_orders')
            ->where('number', 'ORD-20260612-0004')
            ->value('id');

        if (! $orderId) {
            return;
        }

        $events = DB::table('customer_order_history_events')
            ->where('customer_order_id', $orderId)
            ->where('event_type', 'payment_confirmed')
            ->get(['id', 'new_values']);

        foreach ($events as $event) {
            $values = json_decode((string) $event->new_values, true);

            if (! is_array($values)) {
                continue;
            }

            $paymentType = (string) ($values['payment_type'] ?? '');
            $amountUah = round((float) ($values['payment_received_amount_uah'] ?? 0), 2);

            if ($paymentType !== 'bank_fop' || $amountUah !== 1480.0) {
                continue;
            }

            if ($enabled) {
                $values['is_afterpayment'] = true;
            } else {
                unset($values['is_afterpayment']);
            }

            DB::table('customer_order_history_events')
                ->where('id', $event->id)
                ->update(['new_values' => json_encode($values, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
