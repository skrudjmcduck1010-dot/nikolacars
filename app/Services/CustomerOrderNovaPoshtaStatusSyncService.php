<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderShipment;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CustomerOrderNovaPoshtaStatusSyncService
{
    public function __construct(
        protected NovaPoshtaInternetDocumentService $novaPoshtaService,
        protected CustomerOrderReservationProjectionService $reservationProjectionService,
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
        $returnStatus = $this->returnTrackingStatus($status['return_tracking_number'] ?? null, $shipment);
        $shipped = $this->trackingStatusMeansShipped($status['status_code'], $status['status']);
        $refused = $this->trackingStatusMeansRefused($status['status_code'], $status['status'], $status['status_detail'] ?? null);

        DB::transaction(function () use ($order, $shipment, $status, $returnStatus, $shipped, $refused): void {
            $shipment->forceFill([
                'np_status_code' => $status['status_code'] !== '' ? $status['status_code'] : null,
                'np_status' => $status['status'] !== '' ? $status['status'] : null,
                'np_status_detail' => ($status['status_detail'] ?? '') !== '' ? $status['status_detail'] : null,
                'afterpayment_amount' => $status['afterpayment_amount'] ?? $shipment->afterpayment_amount,
                'np_return_tracking_number' => ($status['return_tracking_number'] ?? '') !== '' ? $status['return_tracking_number'] : null,
                'np_return_document_type' => ($status['return_document_type'] ?? '') !== '' ? $status['return_document_type'] : null,
                'np_return_created_at' => ($status['return_created_at'] ?? '') !== '' ? $status['return_created_at'] : null,
                'np_return_status_code' => ($returnStatus['status_code'] ?? '') !== '' ? $returnStatus['status_code'] : null,
                'np_return_status' => ($returnStatus['status'] ?? '') !== '' ? $returnStatus['status'] : null,
                'np_return_status_detail' => ($returnStatus['status_detail'] ?? '') !== '' ? $returnStatus['status_detail'] : null,
                'np_return_status_checked_at' => $returnStatus !== null ? now() : null,
                'np_status_checked_at' => now(),
                'raw_response' => $status['raw_response'],
                'error_message' => null,
            ])->save();

            if (
                $refused
                && $order->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && in_array($order->status, [CustomerOrder::STATUS_ASSEMBLED, CustomerOrder::STATUS_SHIPPED], true)
            ) {
                $oldStatus = $order->status;

                $order->forceFill([
                    'status' => CustomerOrder::STATUS_REFUSED,
                ])->save();

                $order->historyEvents()->create([
                    'event_type' => 'status_changed',
                    'title' => "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}",
                    'description' => $this->statusLabel($oldStatus).' -> '.$this->statusLabel(CustomerOrder::STATUS_REFUSED),
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => [
                        'status' => CustomerOrder::STATUS_REFUSED,
                        'nova_poshta_status_code' => $status['status_code'],
                        'nova_poshta_status' => $status['status'],
                        'nova_poshta_status_detail' => $status['status_detail'] ?? null,
                    ],
                ]);

                $this->refreshReservationProjection($order);

                return;
            }

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
            'refused' => $refused,
            'status_code' => $status['status_code'],
            'status' => $status['status'],
            'status_detail' => $status['status_detail'] ?? null,
            'return_tracking_number' => $status['return_tracking_number'] ?? null,
            'return_status' => $returnStatus['status'] ?? null,
        ];
    }

    public function syncPending(?callable $progress = null): array
    {
        $stats = [
            'checked' => 0,
            'shipped' => 0,
            'refused' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        CustomerOrder::query()
            ->with('novaPoshtaShipment')
            ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
            ->whereIn('status', [
                CustomerOrder::STATUS_ASSEMBLED,
                CustomerOrder::STATUS_SHIPPED,
                CustomerOrder::STATUS_REFUSED,
            ])
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

                    if ($result['refused'] ?? false) {
                        $stats['refused']++;
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

        if (preg_match('/\bвідправлення\s+у\s+м\./u', $statusText) === 1) {
            return true;
        }

        foreach ([
            "\u{043E}\u{0447}\u{0456}\u{043A}",
            "\u{043E}\u{0436}\u{0438}\u{0434}",
            "\u{0441}\u{0442}\u{0432}\u{043E}\u{0440}",
            "\u{0432}\u{0438}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}",
            "\u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}",
            "\u{043D}\u{0435} \u{0437}\u{043D}\u{0430}\u{0439}\u{0434}",
            "\u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}",
            "\u{0432}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}",
            "\u{043E}\u{0442}\u{043A}\u{0430}\u{0437}",
            "\u{043F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}",
            "\u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}",
            "\u{0441}\u{043A}\u{0430}\u{0441}\u{0443}\u{0432}",
            "\u{043E}\u{0442}\u{043C}\u{0435}\u{043D}",
        ] as $needle) {
            if ($statusText !== '' && str_contains($statusText, $needle)) {
                return false;
            }
        }

        return in_array($statusCode, ['4', '5', '6', '7', '8', '41', '101'], true)
            || str_contains($statusText, "\u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}")
            || str_contains($statusText, "\u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}")
            || str_contains($statusText, "\u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}");
    }

    public function trackingStatusMeansRefused(?string $statusCode, ?string $status, ?string $statusDetail = null): bool
    {
        $statusCode = trim((string) $statusCode);
        $text = mb_strtolower(trim((string) $status.' '.(string) $statusDetail));

        if (in_array($statusCode, ['102', '103', '108'], true)) {
            return true;
        }

        foreach ([
            "\u{0432}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}",
            "\u{043E}\u{0442}\u{043A}\u{0430}\u{0437}",
            "\u{0441}\u{043A}\u{0430}\u{0441}\u{0443}\u{0432}",
            "\u{043E}\u{0442}\u{043C}\u{0435}\u{043D}",
        ] as $needle) {
            if ($text !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function refreshReservationProjection(CustomerOrder $order): void
    {
        $order->loadMissing(['items.partCatalogItem', 'items.product']);

        $order->items
            ->pluck('partCatalogItem')
            ->filter(fn ($item): bool => $item instanceof PartCatalogItem)
            ->unique('id')
            ->each(fn (PartCatalogItem $item) => $this->reservationProjectionService->refresh($item));

        $order->items
            ->pluck('product')
            ->filter(fn ($product): bool => $product instanceof Product)
            ->unique('id')
            ->each(fn (Product $product) => $this->reservationProjectionService->refresh($product));
    }

    protected function statusLabel(string $status): string
    {
        return CustomerOrder::STATUS_LABELS[$status] ?? $status;
    }

    protected function returnTrackingStatus(?string $trackingNumber, CustomerOrderShipment $shipment): ?array
    {
        $trackingNumber = trim((string) $trackingNumber);

        if ($trackingNumber === '') {
            return null;
        }

        try {
            return $this->novaPoshtaService->trackingStatusNumber(
                $trackingNumber,
                config('services.nova_poshta.sender_phone') ?: $shipment->recipient_phone,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
