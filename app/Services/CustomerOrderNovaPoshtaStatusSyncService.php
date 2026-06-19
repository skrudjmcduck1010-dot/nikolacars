<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderShipment;
use Illuminate\Support\Facades\DB;

class CustomerOrderNovaPoshtaStatusSyncService
{
    public function __construct(
        protected NovaPoshtaInternetDocumentService $novaPoshtaService,
    ) {}

    public function syncOrder(CustomerOrder $order): array
    {
        $order->loadMissing('novaPoshtaShipment');
        $shipment = $order->novaPoshtaShipment;

        if (
            ! $shipment instanceof CustomerOrderShipment
            || $shipment->carrier !== CustomerOrderShipment::CARRIER_NOVA_POSHTA
            || ! $shipment->tracking_number
        ) {
            return [
                'checked' => false,
                'shipped' => false,
                'message' => 'TTN number is missing.',
            ];
        }

        $status = $this->novaPoshtaService->trackingStatusDocument($shipment);
        $shipped = $this->trackingStatusMeansShipped($status['status_code'], $status['status']);

        DB::transaction(function () use ($order, $shipment, $status, $shipped): void {
            $shipment->forceFill([
                'np_status_code' => $status['status_code'] !== '' ? $status['status_code'] : null,
                'np_status' => $status['status'] !== '' ? $status['status'] : null,
                'np_status_checked_at' => now(),
                'raw_response' => $status['raw_response'],
                'error_message' => null,
            ])->save();

            if (
                $shipped
                && $order->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $order->status === CustomerOrder::STATUS_ASSEMBLED
            ) {
                $oldStatus = $order->status;

                $order->forceFill([
                    'status' => CustomerOrder::STATUS_SHIPPED,
                ])->save();

                $order->historyEvents()->create([
                    'event_type' => 'status_changed',
                    'title' => "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}",
                    'description' => "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D} -> \u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}",
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => [
                        'status' => CustomerOrder::STATUS_SHIPPED,
                        'nova_poshta_status_code' => $status['status_code'],
                        'nova_poshta_status' => $status['status'],
                    ],
                ]);
            }
        });

        return [
            'checked' => true,
            'shipped' => $shipped,
            'status_code' => $status['status_code'],
            'status' => $status['status'],
        ];
    }

    public function syncPending(?callable $progress = null): array
    {
        $stats = [
            'checked' => 0,
            'shipped' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        CustomerOrder::query()
            ->with('novaPoshtaShipment')
            ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
            ->where('status', CustomerOrder::STATUS_ASSEMBLED)
            ->whereHas('novaPoshtaShipment', fn ($query) => $query->whereNotNull('tracking_number'))
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (&$stats, $progress): void {
                foreach ($orders as $order) {
                    try {
                        $result = $this->syncOrder($order);
                    } catch (\Throwable $exception) {
                        $stats['failed']++;
                        if ($progress !== null) {
                            $progress($order, null, $exception);
                        }

                        continue;
                    }

                    if (! $result['checked']) {
                        $stats['skipped']++;
                    } else {
                        $stats['checked']++;
                    }

                    if ($result['shipped']) {
                        $stats['shipped']++;
                    }

                    if ($progress !== null) {
                        $progress($order, $result, null);
                    }
                }
            });

        return $stats;
    }

    public function trackingStatusMeansShipped(?string $statusCode, ?string $status): bool
    {
        $statusCode = trim((string) $statusCode);
        $statusText = mb_strtolower(trim((string) $status));

        if ($statusCode === '' || in_array($statusCode, ['1', '2', '3'], true)) {
            return false;
        }

        foreach ([
            "\u{043E}\u{0447}\u{0456}\u{043A}",
            "\u{043E}\u{0436}\u{0438}\u{0434}",
            "\u{0441}\u{0442}\u{0432}\u{043E}\u{0440}",
            "\u{0432}\u{0438}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}",
            "\u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}",
            "\u{043D}\u{0435} \u{0437}\u{043D}\u{0430}\u{0439}\u{0434}",
            "\u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}",
        ] as $needle) {
            if ($statusText !== '' && str_contains($statusText, $needle)) {
                return false;
            }
        }

        return true;
    }
}
