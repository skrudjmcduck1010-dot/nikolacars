@php
    $useFixedColumns = $useFixedColumns ?? false;
    $statusClass = fn (\App\Models\CustomerOrder $order): string => match ($order->status) {
        \App\Models\CustomerOrder::STATUS_WAITING_PREPAYMENT => 'tag-warning',
        \App\Models\CustomerOrder::STATUS_CANCELLED => 'tag-danger',
        \App\Models\CustomerOrder::STATUS_REFUSED => 'tag-danger',
        \App\Models\CustomerOrder::STATUS_SHIPPED => 'tag-warning',
        \App\Models\CustomerOrder::STATUS_COMPLETED => 'tag-paid',
        \App\Models\CustomerOrder::STATUS_PAID => 'tag-paid',
        default => '',
    };
    $orderIsFullyPaid = fn (\App\Models\CustomerOrder $order, float $totalAmountUah): bool => round((float) $order->paid_amount_uah) >= round($totalAmountUah);
    $clientNameFor = fn (\App\Models\CustomerOrder $order): string => $order->client_name
        ?: (
            (int) $order->counterparty_id === \App\Models\Counterparty::ANONYMOUS_ID
            || $order->counterparty?->name === \App\Models\Counterparty::ANONYMOUS_NAME
                ? \App\Models\Counterparty::ANONYMOUS_NAME
                : '-'
        );
    $prepaymentPartsFor = function (\App\Models\CustomerOrder $order, ?string $currency = null) use ($money): \Illuminate\Support\Collection {
        return collect([
            [
                'label' => \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_CASH_UAH],
                'amount' => (float) $order->paid_cash_uah,
                'currency' => 'UAH',
            ],
            [
                'label' => \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_CASH_USD],
                'amount' => (float) $order->paid_cash_usd,
                'currency' => 'USD',
            ],
            [
                'label' => \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_TOV],
                'amount' => (float) $order->paid_bank_tov_uah,
                'currency' => 'UAH',
            ],
            [
                'label' => \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP],
                'amount' => (float) $order->paid_bank_fop_uah,
                'currency' => 'UAH',
            ],
            [
                'label' => \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_PROM],
                'amount' => (float) $order->paid_prom_uah,
                'currency' => 'UAH',
            ],
        ])
            ->when($currency !== null, fn (\Illuminate\Support\Collection $parts) => $parts->where('currency', $currency))
            ->filter(fn (array $part): bool => $part['amount'] > 0)
            ->map(fn (array $part): array => $part + ['amount_text' => $money($part['amount'], $part['currency'])])
            ->values();
    };
    $prepaymentSummaryFor = function (\Illuminate\Support\Collection $parts): ?string {
        if ($parts->isEmpty()) {
            return null;
        }

        if ($parts->count() === 1) {
            $part = $parts->first();

            return "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} ({$part['label']}): {$part['amount_text']}";
        }

        return "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}: ".$parts
            ->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")
            ->join(' + ');
    };
    $paymentHistoryEntriesFor = function (\App\Models\CustomerOrder $order) use ($money): \Illuminate\Support\Collection {
        if (! $order->relationLoaded('historyEvents')) {
            return collect();
        }

        $lastBulkPrepaymentDeletedEventId = $order->historyEvents
            ->filter(fn (\App\Models\CustomerOrderHistoryEvent $event): bool => $event->event_type === 'prepayment_deleted'
                && data_get($event->new_values, 'deleted_event_id') === null)
            ->max('id');
        $deletedPrepaymentEventIds = $order->historyEvents
            ->filter(fn (\App\Models\CustomerOrderHistoryEvent $event): bool => $event->event_type === 'prepayment_deleted'
                && data_get($event->new_values, 'deleted_event_id') !== null)
            ->map(fn (\App\Models\CustomerOrderHistoryEvent $event): int => (int) data_get($event->new_values, 'deleted_event_id'))
            ->filter()
            ->values();

        return $order->historyEvents
            ->filter(function (\App\Models\CustomerOrderHistoryEvent $event): bool {
                return $event->event_type === 'prepayment_received'
                    || (
                        $event->event_type === 'payment_confirmed'
                        && (
                            (bool) data_get($event->new_values, 'is_prepayment_flow')
                            || (bool) data_get($event->new_values, 'is_afterpayment')
                            || data_get($event->new_values, 'payment_type') === \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT
                        )
                    );
            })
            ->when($lastBulkPrepaymentDeletedEventId !== null, fn (\Illuminate\Support\Collection $events) => $events
                ->filter(fn (\App\Models\CustomerOrderHistoryEvent $event): bool => $event->id > $lastBulkPrepaymentDeletedEventId))
            ->reject(fn (\App\Models\CustomerOrderHistoryEvent $event): bool => $deletedPrepaymentEventIds->contains((int) $event->id))
            ->sortBy('created_at')
            ->map(function (\App\Models\CustomerOrderHistoryEvent $event) use ($money): array {
                $paymentType = (string) data_get($event->new_values, 'payment_type', '');
                $amount = (float) data_get($event->new_values, 'payment_received_amount', 0);
                $currency = $paymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_CASH_USD ? 'USD' : 'UAH';
                $isAfterpayment = (bool) data_get($event->new_values, 'is_afterpayment')
                    || $paymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT;
                $paymentLabel = $paymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT
                    ? \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP]
                    : (\App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[$paymentType] ?? $paymentType);
                $badge = $isAfterpayment ? "\u{043D}\u{0430}\u{043B}\u{043E}\u{0436}\u{043A}\u{0430}" : "\u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}";

                return [
                    'label' => trim($paymentLabel),
                    'amount_text' => $money($amount, $currency),
                    'badge' => $badge,
                ];
            })
            ->filter(fn (array $entry): bool => $entry['label'] !== '' && $entry['amount_text'] !== '')
            ->values();
    };
    $paymentDueUsdFor = function (\App\Models\CustomerOrder $order, float $paymentDueUah) use ($usdRate): ?float {
        $rate = (float) (($usdRate ?? [])['rate'] ?? 0);

        if ($order->total_amount_usd_hint === null) {
            return $rate > 0 ? round($paymentDueUah / $rate, 2) : null;
        }

        $paidNonUsdUah = (float) $order->paid_cash_uah
            + (float) $order->paid_bank_tov_uah
            + (float) $order->paid_bank_fop_uah
            + (float) $order->paid_prom_uah;
        $paidNonUsd = $rate > 0 ? $paidNonUsdUah / $rate : 0.0;

        return max(0, round((float) $order->total_amount_usd_hint - (float) $order->paid_cash_usd - $paidNonUsd, 2));
    };
    $paymentUsdRateFor = function (\App\Models\CustomerOrder $order, float $paymentDueUah, ?float $paymentDueUsd) use ($usdRate): float {
        $paymentDueUah = max(0, round($paymentDueUah, 2));
        $paymentDueUsd = $paymentDueUsd !== null ? max(0, round($paymentDueUsd, 2)) : null;

        if ($order->total_amount_usd_hint !== null && $paymentDueUah > 0 && $paymentDueUsd !== null && $paymentDueUsd > 0) {
            return round($paymentDueUah / $paymentDueUsd, 6);
        }

        return (float) (($usdRate ?? [])['rate'] ?? 0);
    };
    $isSenderCreatedNovaPoshtaStatus = function (?string $status): bool {
        $status = trim((string) $status);
        $senderCreatedInvoiceStatus = "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043D}\u{0438}\u{043A} \u{0441}\u{0430}\u{043C}\u{043E}\u{0441}\u{0442}\u{0456}\u{0439}\u{043D}\u{043E} \u{0441}\u{0442}\u{0432}\u{043E}\u{0440}\u{0438}\u{0432} \u{0446}\u{044E} \u{043D}\u{0430}\u{043A}\u{043B}\u{0430}\u{0434}\u{043D}\u{0443}, \u{0430}\u{043B}\u{0435} \u{0449}\u{0435} \u{043D}\u{0435} \u{043D}\u{0430}\u{0434}\u{0430}\u{0432} \u{0434}\u{043E} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043A}\u{0438}";

        return $status !== '' && str_contains(mb_strtolower($status), mb_strtolower($senderCreatedInvoiceStatus));
    };
    $novaPoshtaStatusDisplay = function (?string $status) use ($isSenderCreatedNovaPoshtaStatus): string {
        $status = trim((string) $status);

        if ($isSenderCreatedNovaPoshtaStatus($status)) {
            return "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0443} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430} \u{041D}\u{043E}\u{0432}\u{0443} \u{043F}\u{043E}\u{0448}\u{0442}\u{0443}.";
        }
        $status = preg_replace('/\s+Очікуйте повідомлення про прибуття\.?$/u', '', $status) ?? $status;

        return trim($status);
    };
    $displayStatusClass = function (\App\Models\CustomerOrder $order) use ($statusClass, $isSenderCreatedNovaPoshtaStatus): string {
        if (
            $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            && $isSenderCreatedNovaPoshtaStatus($order->novaPoshtaShipment?->np_status)
        ) {
            return 'tag-warning';
        }

        if (
            $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            && $order->novaPoshtaShipment?->np_status_code === \App\Models\CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED
        ) {
            return 'tag-paid';
        }

        return $statusClass($order);
    };
@endphp

<style>
    .customer-order-prepayment-button {
        border-color: var(--accent);
        background: var(--accent);
        color: #ffffff;
        font-size: 10px;
        line-height: 1.2;
        padding: 5px 8px;
    }

    .customer-order-prepayment-button:hover {
        border-color: var(--accent);
        background: var(--accent);
        color: #ffffff;
    }

    .customer-order-items-cell {
        font-size: 12px;
        line-height: 1.35;
    }

    .customer-order-item-main {
        display: flex;
        align-items: baseline;
        gap: 6px;
        min-width: 0;
    }

    .customer-order-item-title {
        min-width: 0;
        flex: 1 1 auto;
        font-size: 11px;
        line-height: 1.3;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .customer-order-item-quantity {
        flex: 0 0 auto;
        white-space: nowrap;
        font-size: 11px;
        line-height: 1.3;
    }

    .customer-order-item-row + .customer-order-item-row,
    .customer-order-extra-items {
        margin-top: 8px;
    }

    .customer-order-extra-items details {
        margin-top: 8px;
    }

    .customer-order-extra-items-list {
        margin-top: 8px;
    }

    .customer-order-extra-items summary {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        color: var(--accent);
        font-size: 12px;
        font-weight: 700;
        line-height: 1.35;
        list-style: none;
    }

    .customer-order-extra-items summary::-webkit-details-marker {
        display: none;
    }

    .customer-order-extra-items summary::before {
        content: '+';
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        margin-right: 5px;
        border-radius: 50%;
        border: 1px solid currentColor;
        font-size: 12px;
        line-height: 1;
    }

    .customer-order-extra-items details[open] summary::before {
        content: '-';
    }

    .customer-order-extra-items details[open] .customer-order-extra-items-show,
    .customer-order-extra-items details:not([open]) .customer-order-extra-items-hide {
        display: none;
    }

    .customer-order-part-number {
        max-width: 100%;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .customer-order-paid-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        background: #dcfce7;
        color: #166534;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.4;
    }

    .customer-order-ttn-row {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 100%;
        flex-wrap: wrap;
    }

    .customer-order-print-button,
    .customer-order-ttn-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        min-width: 24px;
        padding: 0;
        border-radius: 6px;
    }

    .customer-order-print-button svg,
    .customer-order-ttn-button svg {
        width: 14px;
        height: 14px;
    }

    .customer-order-ttn-button-muted {
        color: #6b7280;
    }

    .customer-order-ttn-form {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        max-width: 100%;
    }

    .customer-order-ttn-form input {
        width: 148px;
        min-height: 28px;
        padding: 4px 8px;
        border-radius: 8px;
        font-size: 12px;
    }

    .customer-order-ttn-save,
    .customer-order-ttn-cancel {
        min-height: 28px;
        padding: 4px 8px;
        border-radius: 8px;
    }

    .customer-order-ttn-error {
        width: 100%;
    }

    .customer-order-ttn-afterpayment {
        flex-basis: 100%;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.35;
    }

    .customer-order-ttn-warning {
        display: inline-flex;
        width: 16px;
        height: 16px;
        margin-left: 4px;
        color: var(--danger);
        vertical-align: -3px;
    }

    .customer-order-ttn-warning svg {
        display: block;
        width: 16px;
        height: 16px;
    }

    .customer-order-item-ttn-badge {
        display: inline-flex;
        align-items: center;
        min-height: 18px;
        margin-right: 6px;
        padding: 2px 6px;
        border-radius: 6px;
        background: #fee2e2;
        color: #991b1b;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.1;
        vertical-align: 1px;
        white-space: nowrap;
    }

    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
</style>

<table @if($useFixedColumns) style="table-layout:fixed; overflow-wrap:anywhere;" @endif>
    @if($useFixedColumns)
        <colgroup>
            <col style="width:9%;">
            <col style="width:17%;">
            <col style="width:17%;">
            <col style="width:14%;">
            <col style="width:14%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:9%;">
        </colgroup>
    @endif
    <thead>
    <tr>
        <th>Номер</th>
        <th>Товары</th>
        <th>Клиент</th>
        <th>{{ "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431}" }}<br>{{ "\u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}</th>
        <th>Статус</th>
        <th>Сумма</th>
        <th>Дата</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    @forelse($orders as $order)
        @php($rowTotalAmountUah = (float) ($orderTotalAmountUah[$order->id] ?? $order->total_amount))
        @php($rowIsFullyPaid = $orderIsFullyPaid($order, $rowTotalAmountUah))
        @php($rowHasNovaPoshtaTtn = $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && filled($order->novaPoshtaShipment?->tracking_number))
        @php($rowNovaPoshtaShipments = $order->relationLoaded('novaPoshtaShipments') ? $order->novaPoshtaShipments->filter(fn ($shipment) => filled($shipment->tracking_number))->values() : collect([$order->novaPoshtaShipment])->filter(fn ($shipment) => filled($shipment?->tracking_number))->values())
        @php($rowShipmentLabelsByItemId = $rowNovaPoshtaShipments->flatMap(fn ($shipment, $index) => $shipment->relationLoaded('items') ? $shipment->items->mapWithKeys(fn ($shipmentItem) => [$shipmentItem->id => "\u{0422}\u{0422}\u{041D}".($index + 1)]) : collect())->all())
        @php($rowCanBeMarkedAsCompleted = ! $rowHasNovaPoshtaTtn && $order->canBeMarkedAsCompleted() && ($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO || $rowIsFullyPaid))
        <tr>
            <td class="customer-order-items-cell">
                <a href="{{ route('admin.customer-orders.show', $order) }}"><strong>{{ $order->number }}</strong></a>
                @if($order->creator)
                    <div class="help">Создал: {{ $order->creator->stoEmployee?->full_name ?: ($order->creator->name ?: $order->creator->email) }}</div>
                @endif
            </td>
            <td>
                @php($hiddenItemsCount = max(0, $order->items->count() - 3))
                @foreach($order->items as $item)
                    @if($loop->iteration === 4)
                        <div class="customer-order-extra-items">
                            <details>
                                <summary>
                                    <span class="customer-order-extra-items-show">{{ "\u{041F}\u{043E}\u{043A}\u{0430}\u{0437}\u{0430}\u{0442}\u{044C} \u{0435}\u{0449}\u{0451}" }} {{ $hiddenItemsCount }}</span>
                                    <span class="customer-order-extra-items-hide">{{ "\u{0421}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C}" }} {{ $hiddenItemsCount }}</span>
                                </summary>
                                <div class="customer-order-extra-items-list">
                    @endif
                    @php($itemProductUrl = ($itemProductUrls ?? collect())->get($item->id))
                    @php($itemDisplayCode = ($itemDisplayCodes ?? collect())->get($item->id, $item->code))
                    @php($itemDisplayPartNumber = ($itemDisplayPartNumbers ?? collect())->get($item->id, $item->part_number))
                    @php($itemDisplayName = ($itemDisplayNames ?? collect())->get($item->id, $item->name))
                    @php($itemShipmentLabel = $rowShipmentLabelsByItemId[$item->id] ?? null)
                    <div class="customer-order-item-row">
                        <div class="customer-order-item-main">
                            <div class="customer-order-item-title">
                                @if($itemShipmentLabel)
                                    <span class="customer-order-item-ttn-badge">{{ $itemShipmentLabel }}</span>
                                @endif
                                @if($itemProductUrl)
                                    <a href="{{ $itemProductUrl }}">
                                        @if($itemDisplayCode)
                                            <strong>{{ $itemDisplayCode }}</strong>
                                        @endif
                                        {{ $itemDisplayName }}
                                    </a>
                                @else
                                    @if($itemDisplayCode)
                                        <strong>{{ $itemDisplayCode }}</strong>
                                    @endif
                                    {{ $itemDisplayName }}
                                @endif
                            </div>
                            <span class="help customer-order-item-quantity">x {{ $quantity($item->quantity) }}</span>
                        </div>
                        @if($itemDisplayPartNumber)
                            <div class="help customer-order-part-number">
                                @if($itemProductUrl)
                                    <a href="{{ $itemProductUrl }}">{{ "\u{0410}\u{0440}\u{0442}.:" }} {{ $itemDisplayPartNumber }}</a>
                                @else
                                    {{ "\u{0410}\u{0440}\u{0442}.:" }} {{ $itemDisplayPartNumber }}
                                @endif
                            </div>
                        @endif
                    </div>
                    @if($loop->last && $hiddenItemsCount > 0)
                                </div>
                            </details>
                        </div>
                    @endif
                @endforeach
            </td>
            <td>
                {{ $clientNameFor($order) }}
                @if($order->client_phone)
                    <div class="help">{{ $order->client_phone }}</div>
                @endif
            </td>
            <td>
                {{ $order->delivery_method_label ?: '-' }}
                @if($order->novaPoshtaShipment?->recipient_warehouse_name)
                    <div class="help">{{ $order->novaPoshtaShipment?->recipient_city_name }} · {{ $order->novaPoshtaShipment->recipient_warehouse_name }}</div>
                @endif
                @foreach($rowNovaPoshtaShipments as $novaPoshtaRowShipment)
                    @include('admin.customer_orders._tracking_number_editor', [
                        'order' => $order,
                        'shipment' => $novaPoshtaRowShipment,
                        'trackingLabel' => $rowNovaPoshtaShipments->count() > 1 ? "\u{0422}\u{0422}\u{041D} ".($loop->iteration) : "\u{0422}\u{0422}\u{041D}",
                        'showAddTrackingButton' => $loop->first,
                        'showLabelButton' => true,
                        'showTrackingButton' => ! $loop->first,
                    ])
                @endforeach
                @if($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && $rowNovaPoshtaShipments->isEmpty())
                    @include('admin.customer_orders._tracking_number_editor', [
                        'order' => $order,
                        'shipment' => null,
                        'trackingLabel' => "\u{0422}\u{0422}\u{041D}",
                        'showAddTrackingButton' => true,
                        'showLabelButton' => false,
                    ])
                @endif
            </td>
            <td>
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <div>
                        @php($statusIsFromNovaPoshta = $order->status !== \App\Models\CustomerOrder::STATUS_REFUSED && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && $order->novaPoshtaShipment?->np_status)
                        @if(($rowNovaPoshtaShipments ?? collect())->count() > 1)
                            <div style="display:grid; gap:4px; justify-items:start;">
                                @foreach($rowNovaPoshtaShipments as $statusShipment)
                                    <div class="tag tag-warning" style="text-align:left; line-height:1.35;">
                                        {{ "\u{0422}\u{0422}\u{041D} ".$loop->iteration.": ".($statusShipment->np_status ? $novaPoshtaStatusDisplay($statusShipment->np_status) : $order->status_label) }}
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <span style="display:inline-flex; align-items:center; gap:6px;">
                                <span
                                    class="tag {{ $displayStatusClass($order) }}"
                                    data-customer-order-status-label
                                    data-customer-order-id="{{ $order->id }}"
                                    @if($order->status === \App\Models\CustomerOrder::STATUS_REFUSED && $order->novaPoshtaShipment?->np_status_detail)
                                        title="{{ $order->novaPoshtaShipment->np_status_detail }}"
                                    @endif
                                >{{ $statusIsFromNovaPoshta ? $novaPoshtaStatusDisplay($order->novaPoshtaShipment->np_status) : $order->status_label }}</span>
                                @if($statusIsFromNovaPoshta && $order->novaPoshtaShipment?->tracking_url)
                                    <a
                                        class="btn btn-small btn-secondary customer-order-print-button"
                                        href="{{ $order->novaPoshtaShipment->tracking_url }}"
                                        target="_blank"
                                        rel="noopener"
                                        data-customer-order-status-tracking
                                        data-customer-order-ttn-tracking-link
                                        data-customer-order-id="{{ $order->id }}"
                                        aria-label="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0422}\u{0422}\u{041D}" }}"
                                        title="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0422}\u{0422}\u{041D}" }}"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 0 1 18 0Z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                    </a>
                                @endif
                            </span>
                        @endif
                        @if($order->status === \App\Models\CustomerOrder::STATUS_REFUSED && $order->novaPoshtaShipment?->np_return_tracking_number)
                            <div class="help customer-order-ttn-row" style="margin-top:4px;">
                                <span>{{ "\u{0412}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{043D}\u{0430}\u{044F} \u{0422}\u{0422}\u{041D}: " }}{{ $order->novaPoshtaShipment->np_return_tracking_number }}</span>
                                @if($order->novaPoshtaShipment->return_tracking_url)
                                    <a
                                        class="btn btn-small btn-secondary customer-order-print-button"
                                        href="{{ $order->novaPoshtaShipment->return_tracking_url }}"
                                        target="_blank"
                                        rel="noopener"
                                        aria-label="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{043D}\u{043E}\u{0439} \u{0422}\u{0422}\u{041D}" }}"
                                        title="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{043D}\u{043E}\u{0439} \u{0422}\u{0422}\u{041D}" }}"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 0 1 18 0Z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                    </a>
                                @endif
                            </div>
                            @if($order->novaPoshtaShipment->np_return_status)
                                <div class="help">
                                    {{ "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{0430}: " }}{{ $order->novaPoshtaShipment->np_return_status }}
                                </div>
                            @endif
                        @endif
                        @if($order->canBeMarkedAsAssembled())
                            @if($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                                @php($assembleDialogId = 'customer-order-assemble-'.$order->id)
                                @php($novaPoshtaShipment = $order->novaPoshtaShipment)
                                @php($novaPoshtaAfterpaymentAmount = max(0, round($rowTotalAmountUah - (float) $order->paid_amount_uah, 2)))
                                @php($novaPoshtaPackageDefaults = [
                                    'seats_amount' => old('nova_poshta_seats_amount', $novaPoshtaShipment?->seats_amount ?: 1),
                                    'weight' => old('nova_poshta_weight', $novaPoshtaShipment?->weight ?: 1),
                                    'length_cm' => old('nova_poshta_length_cm', $novaPoshtaShipment?->length_cm),
                                    'width_cm' => old('nova_poshta_width_cm', $novaPoshtaShipment?->width_cm),
                                    'height_cm' => old('nova_poshta_height_cm', $novaPoshtaShipment?->height_cm),
                                ])
                                <button type="button" class="btn btn-small" style="margin-top:6px;" onclick="document.getElementById(@js($assembleDialogId))?.showModal()">{{ "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}" }}</button>
                                <dialog class="modal" id="{{ $assembleDialogId }}">
                                    <div class="modal-header">
                                        <h2>{{ "\u{041F}\u{043E}\u{0441}\u{044B}\u{043B}\u{043A}\u{0430} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}" }}</h2>
                                        <button type="button" class="btn btn-secondary btn-small" onclick="this.closest('dialog')?.close()" aria-label="{{ "\u{0417}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C}" }}">&times;</button>
                                    </div>
                                    <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="customer-order-delivery-form">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
                                        <label>
                                            <span>{{ "\u{041A}\u{043E}\u{043B}\u{0438}\u{0447}\u{0435}\u{0441}\u{0442}\u{0432}\u{043E} \u{043C}\u{0435}\u{0441}\u{0442}" }}</span>
                                            <input type="number" name="nova_poshta_seats_amount" min="1" max="99" step="1" required value="{{ $novaPoshtaPackageDefaults['seats_amount'] }}">
                                        </label>
                                        <label>
                                            <span>{{ "\u{0412}\u{0435}\u{0441}, \u{043A}\u{0433}" }}</span>
                                            <input type="number" name="nova_poshta_weight" min="0.1" max="1000" step="0.1" required value="{{ $novaPoshtaPackageDefaults['weight'] }}">
                                        </label>
                                        <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px;">
                                            <label>
                                                <span>{{ "\u{0414}\u{043B}\u{0438}\u{043D}\u{0430}, \u{0441}\u{043C}" }}</span>
                                                <input type="number" name="nova_poshta_length_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['length_cm'] }}">
                                            </label>
                                            <label>
                                                <span>{{ "\u{0428}\u{0438}\u{0440}\u{0438}\u{043D}\u{0430}, \u{0441}\u{043C}" }}</span>
                                                <input type="number" name="nova_poshta_width_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['width_cm'] }}">
                                            </label>
                                            <label>
                                                <span>{{ "\u{0412}\u{044B}\u{0441}\u{043E}\u{0442}\u{0430}, \u{0441}\u{043C}" }}</span>
                                                <input type="number" name="nova_poshta_height_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['height_cm'] }}">
                                            </label>
                                        </div>
                                        <div class="help">
                                            @if($novaPoshtaAfterpaymentAmount > 0)
                                                {{ "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}: " }}{{ $money($novaPoshtaAfterpaymentAmount, 'UAH') }}
                                            @else
                                                {{ "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}: \u{043D}\u{0435}\u{0442}, \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}" }}
                                            @endif
                                        </div>
                                        <div class="actions">
                                            <button type="button" class="btn btn-small btn-secondary" onclick="this.closest('dialog')?.close()">{{ "\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0430}" }}</button>
                                            <button type="submit" class="btn btn-small">{{ "\u{0421}\u{043E}\u{0437}\u{0434}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D} \u{0438} \u{043E}\u{0442}\u{043C}\u{0435}\u{0442}\u{0438}\u{0442}\u{044C} \u{0441}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}" }}</button>
                                        </div>
                                    </form>
                                </dialog>
                            @else
                                <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" style="display:block; margin-top:6px;">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
                                    <button type="submit" class="btn btn-small">{{ "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}" }}</button>
                                </form>
                            @endif
                        @endif
                        @if($order->isIssuedToClient() && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO)
                            <div class="help" style="font-size:11px; margin-top:4px;">
                                {{ "\u{0411}\u{0435}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}" }}
                            </div>
                        @endif
                    </div>
                    @if($order->canBeMarkedAsShipped() && $order->delivery_method !== \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                        <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_SHIPPED }}">
                            <button type="submit" class="btn btn-small">{{ "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}" }}</button>
                        </form>
                    @endif
                    @if($rowCanBeMarkedAsCompleted)
                        <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" style="display:block; width:100%; margin-top:6px;">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_COMPLETED }}">
                            <button type="submit" class="btn btn-small">{{ "\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}" }}</button>
                        </form>
                    @endif
                </div>
            </td>
            <td>
                <strong>{{ $money($rowTotalAmountUah, 'UAH') }}</strong>
                @if($order->total_amount_usd_hint !== null)
                    <div class="help">{{ $money($order->total_amount_usd_hint, 'USD') }}</div>
                @endif
                @if((float) $order->paid_amount_uah > 0)
                    @php($rowPaymentParts = $prepaymentPartsFor($order))
                    @php($rowPaymentHistoryEntries = $paymentHistoryEntriesFor($order))
                    @php($rowPaymentSummary = $rowPaymentParts->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")->join(' + '))
                    @php($rowPrepaymentSummary = $prepaymentSummaryFor($rowPaymentParts))
                    @if($rowPaymentHistoryEntries->isNotEmpty())
                        <div class="help" style="display:grid; gap:3px; margin-top:12px;">
                            @foreach($rowPaymentHistoryEntries as $rowPaymentEntry)
                                <div>{{ $rowPaymentEntry['label'] }}: {{ $rowPaymentEntry['amount_text'] }} ({{ $rowPaymentEntry['badge'] }})</div>
                            @endforeach
                        </div>
                    @elseif($rowPrepaymentSummary)
                        <div class="help" style="margin-top:12px;">{{ $rowPrepaymentSummary }}</div>
                    @endif
                    @if($rowIsFullyPaid)
                        <div class="help" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:8px;">
                            @if($rowPaymentHistoryEntries->isEmpty())
                                <strong>{{ $rowPaymentSummary ?: $money($order->paid_amount_uah, 'UAH') }}</strong>
                            @endif
                            <span class="customer-order-paid-badge">{{ "\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}" }}</span>
                        </div>
                    @endif
                @endif
                @if($order->canAcceptPrepayment() && ! $rowIsFullyPaid)
                    @php($prepaymentDialogId = 'customer-order-prepayment-'.$order->id)
                    @php($hasPrepayment = (float) $order->paid_amount_uah > 0)
                    @php($prepaymentDueUah = max(0, $rowTotalAmountUah - (float) $order->paid_amount_uah))
                    @php($prepaymentDueUsd = $paymentDueUsdFor($order, $prepaymentDueUah))
                    @php($prepaymentConfirmsFullPayment = $hasPrepayment && $order->canConfirmPayment())
                    @php($prepaymentButtonLabel = $hasPrepayment ? "\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" : "\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
                    <div style="margin-top:8px;">
                        <button type="button" class="btn btn-small customer-order-prepayment-button" onclick="document.getElementById(@js($prepaymentDialogId))?.showModal()">{{ $prepaymentButtonLabel }}</button>
                    </div>
                    @include('admin.customer_orders._payment_modal', [
                        'paymentDialogId' => $prepaymentDialogId,
                        'paymentFormAction' => $prepaymentConfirmsFullPayment ? route('admin.customer-orders.payment.confirm', $order) : route('admin.customer-orders.prepayment.store', $order),
                        'paymentDueUah' => $prepaymentDueUah,
                        'paymentDueUsd' => $prepaymentDueUsd,
                        'paymentUsdRate' => $paymentUsdRateFor($order, $prepaymentDueUah, $prepaymentDueUsd),
                        'paymentDialogTitle' => $prepaymentButtonLabel,
                        'paymentSubmitLabel' => $prepaymentConfirmsFullPayment ? "\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" : "\u{0421}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{044C} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}",
                        'paymentDefaultAmount' => $prepaymentConfirmsFullPayment ? number_format($prepaymentDueUah, 2, '.', '') : '',
                        'paymentAutofill' => $prepaymentConfirmsFullPayment,
                        'paymentRequiresFullAmount' => $prepaymentConfirmsFullPayment,
                        'paymentTypes' => $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                            ? \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS
                            : null,
                        'paymentFixedAmounts' => [
                            \App\Models\CustomerOrder::PAYMENT_TYPE_PROM => number_format($prepaymentDueUah, 2, '.', ''),
                        ],
                    ])
                @endif
            </td>
            <td>{{ $order->created_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</td>
            <td>
                @if($order->canConfirmPayment() && $order->delivery_method !== \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && ! $rowIsFullyPaid)
                    @php($paymentDialogId = 'customer-order-payment-'.$order->id)
                    @php($paymentDueUah = max(0, $rowTotalAmountUah - (float) $order->paid_amount_uah))
                    @php($paymentDueUsd = $paymentDueUsdFor($order, $paymentDueUah))
                    <button type="button" class="btn btn-small" onclick="document.getElementById(@js($paymentDialogId))?.showModal()">{{ "\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" }}</button>
                    @include('admin.customer_orders._payment_modal', [
                        'paymentDueUah' => $paymentDueUah,
                        'paymentDueUsd' => $paymentDueUsd,
                        'paymentUsdRate' => $paymentUsdRateFor($order, $paymentDueUah, $paymentDueUsd),
                    ])
                @endif
                @if(! $rowHasNovaPoshtaTtn && $order->canBeCancelled() && ! $rowIsFullyPaid)
                    <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form" onsubmit='return confirm(@json("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} {$order->number}? \u{0422}\u{043E}\u{0432}\u{0430}\u{0440}\u{044B} \u{0431}\u{0443}\u{0434}\u{0443}\u{0442} \u{0441}\u{043D}\u{044F}\u{0442}\u{044B} \u{0441} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0430}."));'>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_CANCELLED }}">
                        <button type="submit" class="btn btn-small btn-danger">{{ "\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C}" }}</button>
                    </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="empty">{{ $emptyText }}</td></tr>
    @endforelse
    </tbody>
</table>
