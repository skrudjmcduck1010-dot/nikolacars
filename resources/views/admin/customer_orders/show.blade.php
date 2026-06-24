@extends('layouts.admin', [
    'heading' => 'Заказ '.$order->number,
    'subheading' => ($order->client_name ?: 'Клиент не указан').' · '.$order->status_label,
])

@php
    $money = fn ($value, string $currency = 'UAH') => $currency === 'UAH'
        ? number_format((float) $value, 0, '.', ' ').' грн'
        : number_format((float) $value, 2, '.', ' ').' '.$currency;
    $imageUrl = fn (?string $path): ?string => \App\Support\PublicStorageUrl::url($path);
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $statusClass = match ($order->status) {
        \App\Models\CustomerOrder::STATUS_WAITING_PREPAYMENT => 'tag-warning',
        \App\Models\CustomerOrder::STATUS_CANCELLED => 'tag-danger',
        \App\Models\CustomerOrder::STATUS_SHIPPED => 'tag-warning',
        \App\Models\CustomerOrder::STATUS_COMPLETED => 'tag-paid',
        \App\Models\CustomerOrder::STATUS_PAID => 'tag-paid',
        default => '',
    };
    $canEditOrder = $order->canBeEdited();
    $canEditOrderItems = $canEditOrder && (float) $order->paid_amount_uah <= 0;
    $orderIsFullyPaid = round((float) $order->paid_amount_uah) >= round((float) $orderTotalAmountUah);
    $orderHasNovaPoshtaTtn = $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && filled($order->novaPoshtaShipment?->tracking_number);
    $orderCanBeMarkedAsCompleted = ! $orderHasNovaPoshtaTtn && $order->canBeMarkedAsCompleted() && ($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO || $orderIsFullyPaid);
    $isSenderCreatedNovaPoshtaStatus = function (?string $status): bool {
        $status = trim((string) $status);
        $senderCreatedInvoiceStatus = "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043D}\u{0438}\u{043A} \u{0441}\u{0430}\u{043C}\u{043E}\u{0441}\u{0442}\u{0456}\u{0439}\u{043D}\u{043E} \u{0441}\u{0442}\u{0432}\u{043E}\u{0440}\u{0438}\u{0432} \u{0446}\u{044E} \u{043D}\u{0430}\u{043A}\u{043B}\u{0430}\u{0434}\u{043D}\u{0443}, \u{0430}\u{043B}\u{0435} \u{0449}\u{0435} \u{043D}\u{0435} \u{043D}\u{0430}\u{0434}\u{0430}\u{0432} \u{0434}\u{043E} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043A}\u{0438}";

        return $status !== '' && str_contains(mb_strtolower($status), mb_strtolower($senderCreatedInvoiceStatus));
    };
    if (
        $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        && $isSenderCreatedNovaPoshtaStatus($order->novaPoshtaShipment?->np_status)
    ) {
        $statusClass = 'tag-warning';
    } elseif (
        $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        && $order->novaPoshtaShipment?->np_status_code === \App\Models\CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED
    ) {
        $statusClass = 'tag-paid';
    }
    $novaPoshtaStatusDisplay = function (?string $status) use ($isSenderCreatedNovaPoshtaStatus): string {
        $status = trim((string) $status);

        if ($isSenderCreatedNovaPoshtaStatus($status)) {
            return "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0443} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430} \u{041D}\u{043E}\u{0432}\u{0443} \u{043F}\u{043E}\u{0448}\u{0442}\u{0443}.";
        }
        $status = preg_replace('/\s+Очікуйте повідомлення про прибуття\.?$/u', '', $status) ?? $status;

        return trim($status);
    };
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
    $paymentParts = $prepaymentPartsFor($order);
    $prepaymentSummary = $prepaymentSummaryFor($paymentParts);
    $paymentSummary = $paymentParts
        ->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")
        ->join(' + ');
    $prepaymentAmountSummary = $paymentParts
        ->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")
        ->join(' + ');
    $paymentIsFull = $orderIsFullyPaid && (float) $order->paid_amount_uah > 0;
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
    $prepaymentEntries = $order->historyEvents
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
            $label = $paymentLabel;

            return [
                'event_id' => $event->id,
                'is_afterpayment' => $isAfterpayment,
                'badge' => $isAfterpayment ? "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{043A}\u{0430}" : "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
                'label' => trim($label) !== '' ? $label : "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
                'amount_text' => $money($amount, $currency),
            ];
        })
        ->filter(fn (array $entry): bool => $entry['amount_text'] !== '')
        ->values();
    if (
        $prepaymentEntries->isNotEmpty()
        && $paymentIsFull
        && ! (bool) data_get($order->historyEvents->where('event_type', 'payment_confirmed')->sortByDesc('created_at')->first()?->new_values, 'is_prepayment_flow')
        && ! $prepaymentEntries->contains('is_afterpayment', true)
    ) {
        $lastPaymentConfirmedEvent = $order->historyEvents
            ->where('event_type', 'payment_confirmed')
            ->sortByDesc('created_at')
            ->first();
        $displayedPrepaymentTotal = $order->historyEvents
            ->filter(fn (\App\Models\CustomerOrderHistoryEvent $event): bool => $event->event_type === 'prepayment_received'
                && ($lastBulkPrepaymentDeletedEventId === null || $event->id > $lastBulkPrepaymentDeletedEventId)
                && ! $deletedPrepaymentEventIds->contains((int) $event->id))
            ->sum(fn (\App\Models\CustomerOrderHistoryEvent $event): float => (float) data_get($event->new_values, 'payment_received_amount_uah', 0));
        $missingPaidAmount = round((float) $order->paid_amount_uah - $displayedPrepaymentTotal, 2);

        if ($missingPaidAmount > 0) {
            $lastPaymentType = (string) $order->payment_type;
            $lastPaymentIsAfterpayment = (bool) data_get($lastPaymentConfirmedEvent?->new_values, 'is_afterpayment')
                || $lastPaymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT;
            $lastPaymentMethodLabel = $lastPaymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT
                ? \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP]
                : (\App\Models\CustomerOrder::PAYMENT_TYPE_LABELS[$lastPaymentType] ?? $lastPaymentType);
            $lastPaymentLabel = $lastPaymentMethodLabel;
            $prepaymentEntries->push([
                'event_id' => $lastPaymentConfirmedEvent?->id,
                'is_afterpayment' => $lastPaymentIsAfterpayment,
                'badge' => $lastPaymentIsAfterpayment ? "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{043A}\u{0430}" : "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
                'label' => trim($lastPaymentLabel) !== '' ? $lastPaymentLabel : "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
                'amount_text' => $money($missingPaidAmount, $lastPaymentType === \App\Models\CustomerOrder::PAYMENT_TYPE_CASH_USD ? 'USD' : 'UAH'),
            ]);
        }
    }
    if ($prepaymentEntries->isEmpty() && ! $paymentIsFull && (float) $order->paid_amount_uah > 0) {
        $prepaymentEntries = collect([[
            'event_id' => null,
            'badge' => "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
            'label' => $prepaymentAmountSummary !== '' ? '' : "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
            'amount_text' => $prepaymentAmountSummary !== '' ? $prepaymentAmountSummary : $money($order->paid_amount_uah, 'UAH'),
        ]]);
    }
    $novaPoshtaShipment = $order->novaPoshtaShipment;
    $novaPoshtaAfterpaymentAmount = max(0, round((float) $order->total_amount - (float) $order->paid_amount_uah, 2));
    $novaPoshtaPackageDefaults = [
        'seats_amount' => old('nova_poshta_seats_amount', $novaPoshtaShipment?->seats_amount ?: 1),
        'weight' => old('nova_poshta_weight', $novaPoshtaShipment?->weight ?: 1),
        'length_cm' => old('nova_poshta_length_cm', $novaPoshtaShipment?->length_cm),
        'width_cm' => old('nova_poshta_width_cm', $novaPoshtaShipment?->width_cm),
        'height_cm' => old('nova_poshta_height_cm', $novaPoshtaShipment?->height_cm),
    ];
    $paymentDueUsdFor = function (float $paymentDueUah) use ($order, $paymentUsdRate, $usdRate): ?float {
        $rate = (float) (($paymentUsdRate ?? $usdRate)['rate'] ?? 0);

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
    $paymentUsdRateFor = function (float $paymentDueUah, ?float $paymentDueUsd) use ($order, $paymentUsdRate, $usdRate): float {
        $paymentDueUah = max(0, round($paymentDueUah, 2));
        $paymentDueUsd = $paymentDueUsd !== null ? max(0, round($paymentDueUsd, 2)) : null;

        if ($order->total_amount_usd_hint !== null && $paymentDueUah > 0 && $paymentDueUsd !== null && $paymentDueUsd > 0) {
            return round($paymentDueUah / $paymentDueUsd, 6);
        }

        return (float) (($paymentUsdRate ?? $usdRate)['rate'] ?? 0);
    };
@endphp

@section('heading-actions')
    <a class="btn btn-small btn-secondary" href="{{ route('admin.customer-orders.index') }}">Все заказы</a>
@endsection

@section('content')
    <style>
        .customer-order-photo-thumb { padding: 0; border: 0; border-radius: 8px; background: transparent; color: inherit; }
        .customer-order-photo-thumb:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .customer-order-photo-lightbox { width: min(980px, calc(100vw - 32px)); border: 0; border-radius: 12px; padding: 0; background: #111827; color: #fff; box-shadow: 0 24px 80px rgba(0, 0, 0, .35); }
        .customer-order-photo-lightbox::backdrop { background: rgba(15, 23, 42, .72); }
        .customer-order-photo-lightbox__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; }
        .customer-order-photo-lightbox__title { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
        .customer-order-photo-lightbox__counter { color: rgba(255, 255, 255, .7); font-size: 13px; white-space: nowrap; }
        .customer-order-photo-lightbox__stage { position: relative; display: grid; place-items: center; min-height: min(72vh, 680px); padding: 0 56px 20px; }
        .customer-order-photo-lightbox__image { display: block; max-width: 100%; max-height: min(72vh, 680px); object-fit: contain; border-radius: 8px; background: #fff; }
        .customer-order-photo-lightbox__close,
        .customer-order-photo-lightbox__nav { border-color: rgba(255, 255, 255, .24); background: rgba(255, 255, 255, .1); color: #fff; }
        .customer-order-photo-lightbox__nav { position: absolute; top: 50%; width: 42px; height: 42px; padding: 0; transform: translateY(-50%); font-size: 28px; line-height: 1; }
        .customer-order-photo-lightbox__nav--prev { left: 10px; }
        .customer-order-photo-lightbox__nav--next { right: 10px; }
        .customer-order-zero-usd-price {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 6px;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
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
        .customer-order-part-number {
            max-width: 100%;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .customer-order-np-autocomplete {
            position: relative;
            min-width: 0;
        }
        .customer-order-np-autocomplete input[type="text"] {
            width: 100%;
        }
        .customer-order-np-suggestions {
            position: static;
            z-index: 60;
            margin-top: 4px;
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 14px 34px rgba(25, 32, 36, .18);
        }
        .customer-order-np-suggestion {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 0;
            padding: 9px 10px;
            background: #fff;
            color: var(--text);
            text-align: left;
        }
        .customer-order-np-suggestion:hover,
        .customer-order-np-suggestion:focus {
            background: var(--accent-soft);
            outline: none;
        }
        .customer-order-np-suggestion strong,
        .customer-order-np-suggestion span {
            display: block;
        }
        .customer-order-np-suggestion span {
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
        }
        .customer-order-icon-button {
            width: 30px;
            height: 30px;
            min-height: 30px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .customer-order-icon-button svg {
            display: block;
            width: 17px;
            height: 17px;
        }
        .customer-order-icon-button-muted {
            color: #6b7280;
        }
        .customer-order-icon-button-muted:hover,
        .customer-order-icon-button-muted:focus-visible {
            color: #374151;
        }
        .customer-order-icon-button-danger {
            border-color: transparent;
            background: transparent;
            color: var(--danger);
            font-size: 22px;
            line-height: 1;
        }
        .customer-order-icon-button-danger:hover,
        .customer-order-icon-button-danger:focus-visible {
            border-color: transparent;
            background: transparent;
            color: #7f1d1d;
        }
        .customer-order-ttn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .customer-order-ttn-button {
            width: 30px;
            height: 30px;
            min-height: 30px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .customer-order-ttn-button svg {
            display: block;
            width: 17px;
            height: 17px;
        }
        .customer-order-ttn-button-muted {
            color: #6b7280;
        }
        .customer-order-ttn-form {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            max-width: 100%;
        }
        .customer-order-ttn-form input {
            width: 180px;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 10px;
        }
        .customer-order-ttn-save,
        .customer-order-ttn-cancel {
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 10px;
        }
        .customer-order-ttn-error {
            width: 100%;
        }
        .customer-order-ttn-afterpayment {
            order: 20;
            flex-basis: 100%;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.35;
        }
        .customer-order-ttn-warning {
            display: inline-flex;
            width: 17px;
            height: 17px;
            margin-left: 4px;
            color: var(--danger);
            vertical-align: -3px;
        }
        .customer-order-ttn-warning svg {
            display: block;
            width: 17px;
            height: 17px;
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
        .customer-order-delivery-form {
            display: grid;
            gap: 10px;
        }
        .customer-order-delivery-form label {
            display: grid;
            gap: 5px;
            font-weight: 700;
        }
        .customer-order-delivery-form label span {
            font-size: 13px;
            color: var(--muted);
        }
        @media (max-width: 700px) {
            .customer-order-photo-lightbox__stage { min-height: 58vh; padding: 0 44px 16px; }
        }
    </style>

    <div class="grid grid-2" style="margin-bottom:18px;">
        <div class="panel">
            <h2 class="section-title" style="margin-top:0;">Клиент</h2>
            <table>
                <tr><th>Телефон</th><td>{{ $order->client_phone ?: '-' }}</td></tr>
                <tr><th>Имя</th><td>{{ $order->client_first_name ?: '-' }}</td></tr>
                <tr><th>Фамилия</th><td>{{ $order->client_last_name ?: '-' }}</td></tr>
                <tr>
                    <th>Карточка</th>
                    <td>
                        @if($order->counterparty)
                            <a href="{{ route('admin.counterparties.show', $order->counterparty) }}">{{ $order->counterparty->name }}</a>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            </table>
        </div>
        <div class="panel">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;">
                <h2 class="section-title" style="margin:0;">Заказ</h2>
                @if(! $orderHasNovaPoshtaTtn && $order->canBeCancelled() && ! $orderIsFullyPaid)
                    <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form" onsubmit='return confirm(@json("Отменить заказ {$order->number}? Товары будут сняты с резерва."));'>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_CANCELLED }}">
                        <button type="submit" class="btn btn-small btn-danger">Отменить</button>
                    </form>
                @endif
            </div>
            <table>
                <tr><th>Номер</th><td>{{ $order->number }}</td></tr>
                <tr>
                    <th>Статус</th>
                    <td>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <div>
                                @php($statusIsFromNovaPoshta = $order->status !== \App\Models\CustomerOrder::STATUS_REFUSED && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && $novaPoshtaShipment?->np_status)
                                @php($statusNovaPoshtaShipments = $order->relationLoaded('novaPoshtaShipments') ? $order->novaPoshtaShipments->filter(fn ($shipment) => filled($shipment->tracking_number))->values() : collect([$novaPoshtaShipment])->filter(fn ($shipment) => filled($shipment?->tracking_number))->values())
                                @if($statusNovaPoshtaShipments->count() > 1)
                                    <div style="display:grid; gap:4px; justify-items:start;">
                                        @foreach($statusNovaPoshtaShipments as $statusShipment)
                                            <div class="tag tag-warning" style="text-align:left; line-height:1.35;">
                                                {{ "\u{0422}\u{0422}\u{041D} ".$loop->iteration.": ".($statusShipment->np_status ? $novaPoshtaStatusDisplay($statusShipment->np_status) : $order->status_label) }}
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span style="display:inline-flex; align-items:center; gap:6px;">
                                        <span
                                            class="tag {{ $statusClass }}"
                                            data-customer-order-status-label
                                            data-customer-order-id="{{ $order->id }}"
                                            @if($order->status === \App\Models\CustomerOrder::STATUS_REFUSED && $novaPoshtaShipment?->np_status_detail)
                                                title="{{ $novaPoshtaShipment->np_status_detail }}"
                                            @endif
                                        >{{ $statusIsFromNovaPoshta ? $novaPoshtaStatusDisplay($novaPoshtaShipment->np_status) : $order->status_label }}</span>
                                        @if($statusIsFromNovaPoshta && $novaPoshtaShipment?->tracking_url)
                                            <a
                                                class="btn btn-small btn-secondary customer-order-icon-button customer-order-icon-button-muted"
                                                href="{{ $novaPoshtaShipment->tracking_url }}"
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
                                @if($order->isIssuedToClient() && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO)
                                    <div class="help" style="font-size:11px; margin-top:4px;">
                                        {{ "\u{0411}\u{0435}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}" }}
                                    </div>
                                @endif
                                @if($order->status === \App\Models\CustomerOrder::STATUS_REFUSED && $novaPoshtaShipment?->np_return_tracking_number)
                                    <div class="help" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:4px;">
                                        <strong>{{ "\u{0412}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{043D}\u{0430}\u{044F} \u{0422}\u{0422}\u{041D}: " }}{{ $novaPoshtaShipment->np_return_tracking_number }}</strong>
                                        @if($novaPoshtaShipment->return_tracking_url)
                                            <a
                                                class="btn btn-small btn-secondary customer-order-icon-button customer-order-icon-button-muted"
                                                href="{{ $novaPoshtaShipment->return_tracking_url }}"
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
                                    @if($novaPoshtaShipment->np_return_status)
                                        <div class="help" style="margin-top:4px;">
                                            {{ "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{0430}: " }}{{ $novaPoshtaShipment->np_return_status }}
                                            @if($novaPoshtaShipment->np_return_status_checked_at)
                                                {{ "\u{00B7} " }}{{ $novaPoshtaShipment->np_return_status_checked_at->timezone('Europe/Kiev')->format('d.m.Y H:i') }}
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </div>
                            @if($order->canBeMarkedAsAssembled())
                                @if($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                                    <button type="button" class="btn btn-small" data-customer-order-assemble-np>Собран</button>
                                @else
                                    <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
                                        <button type="submit" class="btn btn-small">Собран</button>
                                    </form>
                                @endif
                            @endif
                            @if($order->canBeMarkedAsShipped() && $order->delivery_method !== \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                                <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_SHIPPED }}">
                                    <button type="submit" class="btn btn-small">{{ "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}" }}</button>
                                </form>
                            @endif
                            @if($orderCanBeMarkedAsCompleted)
                                <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" style="display:block; width:100%; margin-top:6px;">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_COMPLETED }}">
                                    <button type="submit" class="btn btn-small">{{ "\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}" }}</button>
                                </form>
                            @endif
                            @if($order->canConfirmPayment() && $order->delivery_method !== \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && ! $orderIsFullyPaid)
                                <button type="button" class="btn btn-small" onclick="document.getElementById('customer-order-payment')?.showModal()">Подтвердить оплату</button>
                            @endif
                            @if($order->status === \App\Models\CustomerOrder::STATUS_CANCELLED)
                                <form method="POST" action="{{ route('admin.customer-orders.recreate', $order) }}" class="inline-form" onsubmit='return confirm(@json("Пересоздать заказ {$order->number}? Запчасти будут проверены на наличие и поставлены в резерв по новому заказу."));'>
                                    @csrf
                                    <button type="submit" class="btn btn-small">Пересоздать</button>
                                </form>
                            @endif
                        </div>
                        @error('status')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('order')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('payment_type')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('received_amount')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @foreach(['nova_poshta_seats_amount', 'nova_poshta_weight', 'nova_poshta_length_cm', 'nova_poshta_width_cm', 'nova_poshta_height_cm'] as $packageField)
                            @error($packageField)
                                <div class="error">{{ $message }}</div>
                            @enderror
                        @endforeach
                        @error('nova_poshta')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </td>
                </tr>
                <tr>
                    <th>Способ получения</th>
                    <td>
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <span>{{ $order->delivery_method_label ?: '-' }}</span>
                            @if($canEditOrder && $order->status !== \App\Models\CustomerOrder::STATUS_SHIPPED)
                                <button
                                    type="button"
                                    class="btn btn-small btn-secondary customer-order-icon-button"
                                    title="{{ "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}" }}"
                                    aria-label="{{ "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C} \u{0441}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}"
                                    data-customer-order-delivery-edit
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 20h9"></path>
                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                    </svg>
                                </button>
                            @endif
                        </span>
                        @php($showNovaPoshtaShipments = $order->relationLoaded('novaPoshtaShipments') ? $order->novaPoshtaShipments->filter(fn ($shipment) => filled($shipment->tracking_number))->values() : collect([$novaPoshtaShipment])->filter(fn ($shipment) => filled($shipment?->tracking_number))->values())
                        @if($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && $showNovaPoshtaShipments->isNotEmpty())
                            @foreach($showNovaPoshtaShipments as $showNovaPoshtaShipment)
                                @include('admin.customer_orders._tracking_number_editor', [
                                    'order' => $order,
                                    'shipment' => $showNovaPoshtaShipment,
                                    'trackingLabel' => $showNovaPoshtaShipments->count() > 1 ? "\u{0422}\u{0422}\u{041D} ".($loop->iteration) : "\u{0422}\u{0422}\u{041D}",
                                    'showAddTrackingButton' => $loop->first,
                                    'showLabelButton' => true,
                                    'showTrackingButton' => true,
                                    'rowStyle' => 'margin-top:4px;',
                                ])
                            @endforeach
                            <div class="help" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:4px;">
                                @if($novaPoshtaShipment->status === \App\Models\CustomerOrderShipment::STATUS_CANCELLED)
                                    <span class="tag tag-danger">{{ "\u{0423}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}" }}</span>
                                @endif
                                <form method="POST" action="{{ route('admin.customer-orders.nova-poshta.sync-status', $order) }}" class="inline-form">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="btn btn-small btn-secondary customer-order-icon-button customer-order-icon-button-muted"
                                        aria-label="{{ "\u{041E}\u{0431}\u{043D}\u{043E}\u{0432}\u{0438}\u{0442}\u{044C} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0422}\u{0422}\u{041D}" }}"
                                        title="{{ "\u{041E}\u{0431}\u{043D}\u{043E}\u{0432}\u{0438}\u{0442}\u{044C} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0422}\u{0422}\u{041D}" }}"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 12a9 9 0 0 1-15.5 6.2"></path>
                                            <path d="M3 12a9 9 0 0 1 15.5-6.2"></path>
                                            <path d="M18 2v4h4"></path>
                                            <path d="M6 22v-4H2"></path>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        @elseif($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                            @include('admin.customer_orders._tracking_number_editor', [
                                'order' => $order,
                                'shipment' => null,
                                'trackingLabel' => "\u{0422}\u{0422}\u{041D}",
                                'showAddTrackingButton' => true,
                                'showLabelButton' => false,
                                'rowStyle' => 'margin-top:4px;',
                            ])
                        @endif
                        @error('delivery_method')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('order')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('nova_poshta_city')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('nova_poshta_warehouse')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('nova_poshta_warehouse_ref')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </td>
                </tr>
                @if($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                    <tr>
                        <th>{{ "\u{041D}\u{043E}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0447}\u{0442}\u{0430}" }}</th>
                        <td>
                            <div>
                                {{ $novaPoshtaShipment?->recipient_city_name ?: '-' }}
                                @if($novaPoshtaShipment?->recipient_warehouse_name)
                                    <div class="help">{{ $novaPoshtaShipment->recipient_warehouse_name }}</div>
                                @endif
                            </div>
                            @if($novaPoshtaShipment?->error_message)
                                <div class="error" style="margin-top:8px;">{{ $novaPoshtaShipment->error_message }}</div>
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <th>Создан</th>
                    <td>
                        {{ $order->created_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}
                        @if($order->creator?->name)
                            ({{ $order->creator->name }})
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>Сумма заказа</th>
                    <td data-customer-order-summary data-usd-rate="{{ (float) ($usdRate['rate'] ?? 0) }}">
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <strong data-customer-order-total-uah>{{ $money($orderTotalAmountUah, 'UAH') }}</strong>
                            @if($paymentIsFull)
                                <span class="customer-order-paid-badge">{{ "\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}" }}</span>
                            @endif
                        </div>
                        <div class="help" data-customer-order-total-usd @if($order->total_amount_usd_hint === null) style="display:none;" @endif>
                            @if($order->total_amount_usd_hint !== null)
                                {{ $money($order->total_amount_usd_hint, 'USD') }}
                            @endif
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>{{ "\u{041E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}" }}</th>
                    <td>
                        <div style="display:grid; gap:8px; align-items:start;">
                            @if($prepaymentEntries->isNotEmpty())
                                <div style="display:grid; gap:5px; align-items:start;">
                                    @foreach($prepaymentEntries as $index => $prepaymentEntry)
                                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                            <span>{{ $prepaymentEntry['label'] }}@if($prepaymentEntry['label'] !== ''): @endif{{ $prepaymentEntry['amount_text'] }}</span>
                                            <span class="tag">{{ $prepaymentEntry['badge'] ?? "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}" }}</span>
                                            @if($order->canAcceptPrepayment())
                                                <form method="POST" action="{{ $prepaymentEntry['event_id'] ? route('admin.customer-orders.prepayment-entry.destroy', [$order, $prepaymentEntry['event_id']]) : route('admin.customer-orders.prepayment.destroy', $order) }}" class="inline-form" onsubmit='return confirm(@json("Удалить эту предоплату по заказу {$order->number}?"));'>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button
                                                        type="submit"
                                                        class="btn btn-small customer-order-icon-button customer-order-icon-button-danger"
                                                        title="{{ "\u{0423}\u{0434}\u{0430}\u{043B}\u{0438}\u{0442}\u{044C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" }}"
                                                        aria-label="{{ "\u{0423}\u{0434}\u{0430}\u{043B}\u{0438}\u{0442}\u{044C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" }}"
                                                    >&times;</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($paymentIsFull)
                                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                    <strong>{{ $paymentSummary ?: $money($order->paid_amount_uah, 'UAH') }}</strong>
                                </div>
                            @endif
                            @if($order->payment_confirmed_at)
                                <div class="help">{{ $order->payment_confirmed_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</div>
                            @endif
                            @if($order->canAcceptPrepayment() && ! $orderIsFullyPaid)
                                <div>
                                    <button type="button" class="btn btn-small btn-secondary" onclick="document.getElementById('customer-order-prepayment')?.showModal()">{{ "\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" }}</button>
                                </div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Товары</h2>
        @php($showShipmentLabelsByItemId = ($order->relationLoaded('novaPoshtaShipments') ? $order->novaPoshtaShipments->filter(fn ($shipment) => filled($shipment->tracking_number))->values() : collect([$novaPoshtaShipment])->filter(fn ($shipment) => filled($shipment?->tracking_number))->values())->flatMap(fn ($shipment, $index) => $shipment->relationLoaded('items') ? $shipment->items->mapWithKeys(fn ($shipmentItem) => [$shipmentItem->id => "\u{0422}\u{0422}\u{041D}".($index + 1)]) : collect())->all())
        <table>
            <thead>
            <tr>
                <th>Фото</th>
                <th>Товар</th>
                <th>Артикул</th>
                <th>VIN</th>
                <th>Кол-во</th>
                <th>Цена</th>
                <th>Сумма</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->items as $item)
                @php($galleryUrls = collect(($itemImageUrls ?? collect())->get($item->id, []))->filter()->values())
                @php($previewUrl = $galleryUrls->first() ?: $imageUrl($item->image_url))
                @php($itemProductUrl = $itemProductUrls->get($item->id))
                @php($itemDisplayCode = $itemDisplayCodes->get($item->id, $item->code))
                @php($itemDisplayPartNumber = $itemDisplayPartNumbers->get($item->id, $item->part_number))
                @php($itemDisplayName = $itemDisplayNames->get($item->id, $item->name))
                @php($itemShipmentLabel = $showShipmentLabelsByItemId[$item->id] ?? null)
                <tr data-customer-order-item-row data-quantity="{{ (float) $item->quantity }}">
                    <td>
                        @if($previewUrl)
                            <button
                                type="button"
                                class="customer-order-photo-thumb"
                                data-customer-order-photo-trigger
                                data-customer-order-photo-images='@json($galleryUrls->isNotEmpty() ? $galleryUrls->all() : [$previewUrl])'
                                data-customer-order-photo-title="{{ $itemDisplayCode ? $itemDisplayCode.' · '.$itemDisplayName : $itemDisplayName }}"
                                aria-label="Открыть фото {{ $itemDisplayName }}"
                            >
                                <img class="table-preview" src="{{ $previewUrl }}" alt="Превью {{ $itemDisplayName }}">
                            </button>
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                    <td>
                        <strong>
                            @if($itemShipmentLabel)
                                <span class="customer-order-item-ttn-badge">{{ $itemShipmentLabel }}</span>
                            @endif
                            @if($itemProductUrl)
                                <a href="{{ $itemProductUrl }}">
                                    @if($itemDisplayCode)
                                        {{ $itemDisplayCode }}
                                    @endif
                                    {{ $itemDisplayName }}
                                </a>
                            @else
                                @if($itemDisplayCode)
                                    {{ $itemDisplayCode }}
                                @endif
                                {{ $itemDisplayName }}
                            @endif
                        </strong>
                        @if($item->category)<div class="help">{{ $item->category }}</div>@endif
                        @if($itemProductUrl)<div class="help"><a href="{{ $itemProductUrl }}">Открыть товар</a></div>@endif
                    </td>
                    <td class="customer-order-part-number">
                        @if($itemProductUrl && $itemDisplayPartNumber)
                            <a href="{{ $itemProductUrl }}">{{ $itemDisplayPartNumber }}</a>
                        @else
                            {{ $itemDisplayPartNumber ?: '-' }}
                        @endif
                    </td>
                    <td>{{ $item->donor_vin ?: '-' }}</td>
                    <td>{{ $quantity($item->quantity) }}</td>
                    <td>
                        @if($canEditOrderItems)
                        <form method="POST" action="{{ route('admin.customer-orders.items.update', [$order, $item]) }}" style="display:flex; gap:8px; align-items:center; max-width:220px;">
                            @csrf
                            @method('PATCH')
                            <input type="number" name="unit_price" value="{{ old('unit_price', number_format((float) ($itemUnitPriceUah[$item->id] ?? $item->unit_price), 2, '.', '')) }}" min="0" step="0.01" aria-label="Цена, грн" data-customer-order-unit-price>
                            <button type="submit" class="btn btn-small">Сохр.</button>
                        </form>
                        @else
                            <strong>{{ $money($itemUnitPriceUah[$item->id] ?? $item->unit_price, 'UAH') }}</strong>
                        @endif
                        <div
                            @class(['help', 'customer-order-zero-usd-price' => $item->unit_price_usd_hint !== null && (float) $item->unit_price_usd_hint === 0.0])
                            data-customer-order-unit-usd
                            @if($item->unit_price_usd_hint === null) style="display:none;" @endif
                        >
                            @if($item->unit_price_usd_hint !== null)
                                {{ $money($item->unit_price_usd_hint, 'USD') }}
                            @endif
                        </div>
                    </td>
                    <td>
                        <strong data-customer-order-line-uah>{{ $money($itemTotalPriceUah[$item->id] ?? $item->total_price, 'UAH') }}</strong>
                        <div
                            @class(['help', 'customer-order-zero-usd-price' => $item->total_price_usd_hint !== null && (float) $item->total_price_usd_hint === 0.0])
                            data-customer-order-line-usd
                            @if($item->total_price_usd_hint === null) style="display:none;" @endif
                        >
                            @if($item->total_price_usd_hint !== null)
                                {{ $money($item->total_price_usd_hint, 'USD') }}
                            @endif
                        </div>
                    </td>
                    <td class="actions" style="width:42px; text-align:center; white-space:nowrap;">
                        @if($canEditOrderItems)
                        <form method="POST" action="{{ route('admin.customer-orders.items.destroy', [$order, $item]) }}" class="inline-form" onsubmit="return confirm('Удалить товар из заказа?');">
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                class="btn btn-small customer-order-icon-button customer-order-icon-button-danger"
                                title="{{ "\u{0423}\u{0434}\u{0430}\u{043B}\u{0438}\u{0442}\u{044C} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}" }}"
                                aria-label="{{ "\u{0423}\u{0434}\u{0430}\u{043B}\u{0438}\u{0442}\u{044C} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}" }}"
                            >&times;</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($canEditOrderItems)
        <div class="actions" style="margin-top:16px;">
            <button type="button" class="btn" data-customer-order-add-item>Добавить товар</button>
        </div>
        @endif
    </div>

    <dialog class="customer-order-photo-lightbox" data-customer-order-photo-lightbox>
        <div class="customer-order-photo-lightbox__bar">
            <div class="customer-order-photo-lightbox__title" data-customer-order-photo-title></div>
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="customer-order-photo-lightbox__counter" data-customer-order-photo-counter></div>
                <button type="button" class="btn btn-small customer-order-photo-lightbox__close" data-customer-order-photo-close aria-label="Закрыть">&times;</button>
            </div>
        </div>
        <div class="customer-order-photo-lightbox__stage">
            <button type="button" class="customer-order-photo-lightbox__nav customer-order-photo-lightbox__nav--prev" data-customer-order-photo-prev aria-label="Предыдущее фото">&lsaquo;</button>
            <img class="customer-order-photo-lightbox__image" src="" alt="" data-customer-order-photo-image>
            <button type="button" class="customer-order-photo-lightbox__nav customer-order-photo-lightbox__nav--next" data-customer-order-photo-next aria-label="Следующее фото">&rsaquo;</button>
        </div>
    </dialog>

    @if($canEditOrder)
    <dialog class="modal" data-customer-order-delivery-dialog>
        <div class="modal-header">
            <h2>{{ "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}</h2>
            <button type="button" class="btn btn-secondary btn-small" data-customer-order-delivery-close aria-label="{{ "\u{0417}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C}" }}">&times;</button>
        </div>
        <form
            method="POST"
            action="{{ route('admin.customer-orders.delivery-method.update', $order) }}"
            class="customer-order-delivery-form"
            data-nova-poshta-delivery-form
            data-cities-url="{{ route('admin.customer-orders.nova-poshta.cities') }}"
            data-warehouses-url="{{ route('admin.customer-orders.nova-poshta.warehouses') }}"
        >
            @csrf
            @method('PATCH')
            <label>
                <span>{{ "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}</span>
                <select name="delivery_method" required aria-label="{{ "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}">
                    @foreach(\App\Models\CustomerOrder::DELIVERY_METHOD_LABELS as $method => $label)
                        <option value="{{ $method }}" @selected(old('delivery_method', $order->delivery_method) === $method)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ "\u{0413}\u{043E}\u{0440}\u{043E}\u{0434} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B}" }}</span>
                <input
                    type="text"
                    name="nova_poshta_city"
                    autocomplete="off"
                    data-nova-poshta-city-input
                    value="{{ old('nova_poshta_city', $order->novaPoshtaShipment?->recipient_city_name) }}"
                >
            </label>
            <input type="hidden" value="" data-nova-poshta-city-ref>
            <div class="customer-order-np-suggestions" data-nova-poshta-city-suggestions hidden></div>
            <label>
                <span>{{ "\u{041E}\u{0442}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{0438}\u{043B}\u{0438} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}" }}</span>
                <input
                    type="text"
                    name="nova_poshta_warehouse"
                    autocomplete="off"
                    data-nova-poshta-warehouse-input
                    value="{{ old('nova_poshta_warehouse', $order->novaPoshtaShipment?->recipient_warehouse_name) }}"
                >
            </label>
            <input
                type="hidden"
                name="nova_poshta_warehouse_ref"
                value="{{ old('nova_poshta_warehouse_ref', $order->novaPoshtaShipment?->recipient_warehouse_ref) }}"
                data-nova-poshta-warehouse-ref
            >
            <div class="customer-order-np-suggestions" data-nova-poshta-warehouse-suggestions hidden></div>
            <div class="actions">
                <button type="button" class="btn btn-small btn-secondary" data-customer-order-delivery-close>{{ "\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0430}" }}</button>
                <button type="submit" class="btn btn-small">{{ "\u{0421}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{044C}" }}</button>
            </div>
        </form>
    </dialog>
    @endif

    @if($order->canBeMarkedAsAssembled() && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
    <dialog class="modal" data-customer-order-assemble-dialog>
        <div class="modal-header">
            <h2>Посылка Новой почты</h2>
            <button type="button" class="btn btn-secondary btn-small" data-customer-order-assemble-close aria-label="Закрыть">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="customer-order-delivery-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
            <label>
                <span>Количество мест</span>
                <input type="number" name="nova_poshta_seats_amount" min="1" max="99" step="1" required value="{{ $novaPoshtaPackageDefaults['seats_amount'] }}">
            </label>
            <label>
                <span>Вес, кг</span>
                <input type="number" name="nova_poshta_weight" min="0.1" max="1000" step="0.1" required value="{{ $novaPoshtaPackageDefaults['weight'] }}">
            </label>
            <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px;">
                <label>
                    <span>Длина, см</span>
                    <input type="number" name="nova_poshta_length_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['length_cm'] }}">
                </label>
                <label>
                    <span>Ширина, см</span>
                    <input type="number" name="nova_poshta_width_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['width_cm'] }}">
                </label>
                <label>
                    <span>Высота, см</span>
                    <input type="number" name="nova_poshta_height_cm" min="1" max="300" step="1" required value="{{ $novaPoshtaPackageDefaults['height_cm'] }}">
                </label>
            </div>
            <div class="help">
                @if($novaPoshtaAfterpaymentAmount > 0)
                    Наложенный платеж: {{ $money($novaPoshtaAfterpaymentAmount, 'UAH') }}
                @else
                    Наложенный платеж: нет, заказ оплачен
                @endif
            </div>
            <div class="actions">
                <button type="button" class="btn btn-small btn-secondary" data-customer-order-assemble-close>Отмена</button>
                <button type="submit" class="btn btn-small">Создать ТТН и отметить собран</button>
            </div>
        </form>
    </dialog>
    @endif

    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Примечание</h2>
        <form method="POST" action="{{ route('admin.customer-orders.note.update', $order) }}" style="display:grid; gap:10px;">
            @csrf
            @method('PATCH')
            <textarea name="note" rows="4" maxlength="10000">{{ old('note', $order->note) }}</textarea>
            @error('note')
                <div class="error">{{ $message }}</div>
            @enderror
            <div class="actions">
                <button type="submit" class="btn btn-small">{{ "\u{0421}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{044C} \u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0447}\u{0430}\u{043D}\u{0438}\u{0435}" }}</button>
            </div>
        </form>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 class="section-title" style="margin-top:0;">История заказа</h2>
        @if($orderHistoryEvents->isEmpty())
            <p class="help">История пока пустая.</p>
        @else
            <div style="display:grid; gap:12px;">
                @foreach($orderHistoryEvents as $historyEvent)
                    <div style="display:grid; grid-template-columns:150px 1fr; gap:12px; align-items:start; border-bottom:1px solid #e5e7eb; padding-bottom:12px;">
                        <div class="help">{{ $historyEvent['created_at']?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</div>
                        <div>
                            <strong>{{ $historyEvent['title'] }}</strong>
                            @if($historyEvent['description'])
                                <div>{{ $historyEvent['description'] }}</div>
                            @endif
                            @if($historyEvent['user_name'])
                                <div class="help">{{ $historyEvent['user_name'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($order->canAcceptPrepayment() && ! $orderIsFullyPaid)
        @php($prepaymentDueUah = max(0, (float) $orderTotalAmountUah - (float) $order->paid_amount_uah))
        @php($prepaymentDueUsd = $paymentDueUsdFor($prepaymentDueUah))
        @include('admin.customer_orders._payment_modal', [
            'paymentDialogId' => 'customer-order-prepayment',
            'paymentFormAction' => route('admin.customer-orders.prepayment.store', $order),
            'paymentDueUah' => $prepaymentDueUah,
            'paymentDueUsd' => $prepaymentDueUsd,
            'paymentUsdRate' => $paymentUsdRateFor($prepaymentDueUah, $prepaymentDueUsd),
            'paymentDialogTitle' => "\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}",
            'paymentSubmitLabel' => "\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}",
            'paymentDefaultAmount' => '',
            'paymentAutofill' => false,
            'paymentRequiresFullAmount' => false,
            'paymentTypes' => $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                ? \App\Models\CustomerOrder::PAYMENT_TYPE_LABELS
                : null,
            'paymentFixedAmounts' => [
                \App\Models\CustomerOrder::PAYMENT_TYPE_PROM => number_format($prepaymentDueUah, 2, '.', ''),
            ],
        ])
    @endif

    @if($order->canConfirmPayment() && $order->delivery_method !== \App\Models\CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA && ! $orderIsFullyPaid)
        @php($paymentDueUah = max(0, (float) $orderTotalAmountUah - (float) $order->paid_amount_uah))
        @php($paymentDueUsd = $paymentDueUsdFor($paymentDueUah))
        @include('admin.customer_orders._payment_modal', [
            'paymentDialogId' => 'customer-order-payment',
            'paymentDueUah' => $paymentDueUah,
            'paymentDueUsd' => $paymentDueUsd,
            'paymentUsdRate' => $paymentUsdRateFor($paymentDueUah, $paymentDueUsd),
        ])
    @endif

    @if($canEditOrderItems)
    <dialog class="modal" data-customer-order-item-dialog>
        <div class="modal-header">
            <h2>Добавить товар</h2>
            <button type="button" class="btn btn-secondary" data-customer-order-item-close aria-label="Закрыть">&times;</button>
        </div>
        <div style="display:grid; gap:12px;">
            <input type="search" placeholder="Код, артикул или название" autocomplete="off" data-customer-order-item-search>
            <div class="help" data-customer-order-item-status>Введите запрос для поиска в Запчастях НиколаКарз.</div>
            <div style="display:grid; gap:10px; max-height:420px; overflow:auto;" data-customer-order-item-results></div>
        </div>
    </dialog>
    @endif

    <script>
        @include('admin.customer_orders._payment_modal_scripts')
        @include('admin.customer_orders._tracking_number_editor_scripts')

        (() => {
            const lightbox = document.querySelector('[data-customer-order-photo-lightbox]');
            const image = lightbox?.querySelector('[data-customer-order-photo-image]');
            const titleNode = lightbox?.querySelector('[data-customer-order-photo-title]');
            const counterNode = lightbox?.querySelector('[data-customer-order-photo-counter]');
            const closeButton = lightbox?.querySelector('[data-customer-order-photo-close]');
            const prevButton = lightbox?.querySelector('[data-customer-order-photo-prev]');
            const nextButton = lightbox?.querySelector('[data-customer-order-photo-next]');
            let photoUrls = [];
            let currentIndex = 0;
            let currentTitle = '';

            if (!lightbox || !image) return;

            const showPhoto = (index) => {
                if (photoUrls.length === 0) return;

                currentIndex = (index + photoUrls.length) % photoUrls.length;
                image.src = photoUrls[currentIndex];
                image.alt = currentTitle;

                if (titleNode) titleNode.textContent = currentTitle;
                if (counterNode) counterNode.textContent = `${currentIndex + 1} / ${photoUrls.length}`;

                const hasMultiple = photoUrls.length > 1;
                if (prevButton) prevButton.hidden = !hasMultiple;
                if (nextButton) nextButton.hidden = !hasMultiple;
            };

            const openLightbox = (trigger) => {
                try {
                    photoUrls = JSON.parse(trigger.dataset.customerOrderPhotoImages || '[]').filter(Boolean);
                } catch (error) {
                    photoUrls = [];
                }

                if (photoUrls.length === 0) return;

                currentTitle = trigger.dataset.customerOrderPhotoTitle || '';
                showPhoto(0);

                if (typeof lightbox.showModal === 'function') {
                    lightbox.showModal();
                } else {
                    lightbox.setAttribute('open', 'open');
                }
            };

            document.querySelectorAll('[data-customer-order-photo-trigger]').forEach((trigger) => {
                trigger.addEventListener('click', (event) => {
                    event.preventDefault();
                    openLightbox(trigger);
                });
            });

            closeButton?.addEventListener('click', () => lightbox.close());
            prevButton?.addEventListener('click', () => showPhoto(currentIndex - 1));
            nextButton?.addEventListener('click', () => showPhoto(currentIndex + 1));
            lightbox.addEventListener('click', (event) => {
                if (event.target === lightbox) lightbox.close();
            });
            lightbox.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') showPhoto(currentIndex - 1);
                if (event.key === 'ArrowRight') showPhoto(currentIndex + 1);
            });
        })();

        @if($canEditOrder)
        (() => {
            const dialog = document.querySelector('[data-customer-order-assemble-dialog]');
            const openButton = document.querySelector('[data-customer-order-assemble-np]');

            if (!dialog || !openButton) return;

            openButton.addEventListener('click', () => {
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', 'open');
                }
                dialog.querySelector('[name="nova_poshta_weight"]')?.focus();
            });
            dialog.querySelectorAll('[data-customer-order-assemble-close]').forEach((button) => {
                button.addEventListener('click', () => button.closest('dialog')?.close());
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        })();

        (() => {
            const form = document.querySelector('[data-nova-poshta-delivery-form]');
            if (!form) return;

            const dialog = document.querySelector('[data-customer-order-delivery-dialog]');
            const openButton = document.querySelector('[data-customer-order-delivery-edit]');
            const deliveryMethodInput = form.querySelector('[name="delivery_method"]');
            const cityInput = form.querySelector('[data-nova-poshta-city-input]');
            const cityRefInput = form.querySelector('[data-nova-poshta-city-ref]');
            const citySuggestions = form.querySelector('[data-nova-poshta-city-suggestions]');
            const warehouseInput = form.querySelector('[data-nova-poshta-warehouse-input]');
            const warehouseRefInput = form.querySelector('[data-nova-poshta-warehouse-ref]');
            const warehouseSuggestions = form.querySelector('[data-nova-poshta-warehouse-suggestions]');
            const citiesUrl = form.dataset.citiesUrl || '';
            const warehousesUrl = form.dataset.warehousesUrl || '';
            const isNovaPoshta = () => deliveryMethodInput?.value === 'nova_poshta';
            const novaPoshtaNodes = () => [
                cityInput?.closest('label'),
                citySuggestions,
                warehouseInput?.closest('label'),
                warehouseSuggestions,
            ].filter(Boolean);
            const text = {
                city: @json("\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{0433}\u{043E}\u{0440}\u{043E}\u{0434} \u{0438}\u{0437} \u{043F}\u{043E}\u{0434}\u{0441}\u{043A}\u{0430}\u{0437}\u{043A}\u{0438} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B}."),
                warehouse: @json("\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{0438}\u{043B}\u{0438} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442} \u{0438}\u{0437} \u{043F}\u{043E}\u{0434}\u{0441}\u{043A}\u{0430}\u{0437}\u{043A}\u{0438} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B}."),
            };

            const hide = (node) => {
                if (!node) return;
                node.hidden = true;
                node.innerHTML = '';
            };
            const render = (node, items, onChoose) => {
                if (!node) return;
                node.innerHTML = '';
                items.forEach((item) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'customer-order-np-suggestion';
                    button.innerHTML = '<strong></strong><span></span>';
                    button.querySelector('strong').textContent = item.description || '';
                    button.querySelector('span').textContent = [item.settlement_type, item.area, item.number ? `№${item.number}` : ''].filter(Boolean).join(' · ');
                    button.addEventListener('click', () => onChoose(item));
                    node.appendChild(button);
                });
                node.hidden = items.length === 0;
            };
            const attach = ({ input, refInput, suggestions, url, minLength, buildParams, onInput, onChoose }) => {
                if (!input || !refInput || !suggestions || !url) return;
                let timer = null;
                input.addEventListener('input', () => {
                    refInput.value = '';
                    input.setCustomValidity('');
                    onInput?.();
                    window.clearTimeout(timer);
                    timer = window.setTimeout(async () => {
                        const query = input.value.trim();
                        if (query.length < minLength) {
                            hide(suggestions);
                            return;
                        }
                        const requestUrl = new URL(url, window.location.origin);
                        requestUrl.searchParams.set('query', query);
                        Object.entries(buildParams?.() || {}).forEach(([key, value]) => requestUrl.searchParams.set(key, value));
                        try {
                            const response = await fetch(requestUrl, { headers: { Accept: 'application/json' } });
                            render(suggestions, response.ok ? await response.json() : [], (item) => {
                                input.value = item.description || '';
                                refInput.value = item.ref || '';
                                input.setCustomValidity('');
                                hide(suggestions);
                                onChoose?.(item);
                            });
                        } catch (error) {
                            hide(suggestions);
                        }
                    }, 300);
                });
            };
            const sync = () => {
                const required = isNovaPoshta();
                novaPoshtaNodes().forEach((node) => {
                    node.hidden = !required;
                });
                [cityInput, warehouseInput].forEach((input) => {
                    if (!input) return;
                    input.required = required;
                    input.disabled = !required;
                    input.setCustomValidity('');
                });
                if (warehouseInput && required && !cityRefInput?.value && !warehouseRefInput?.value) {
                    warehouseInput.disabled = true;
                }
            };

            openButton?.addEventListener('click', () => {
                if (typeof dialog?.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog?.setAttribute('open', 'open');
                }
                sync();
                deliveryMethodInput?.focus();
            });
            form.closest('dialog')?.querySelectorAll('[data-customer-order-delivery-close]').forEach((button) => {
                button.addEventListener('click', () => button.closest('dialog')?.close());
            });
            attach({
                input: cityInput,
                refInput: cityRefInput,
                suggestions: citySuggestions,
                url: citiesUrl,
                minLength: 2,
                onInput: () => {
                    if (warehouseInput) {
                        warehouseInput.value = '';
                        warehouseInput.disabled = isNovaPoshta();
                    }
                    if (warehouseRefInput) warehouseRefInput.value = '';
                    hide(warehouseSuggestions);
                },
                onChoose: () => {
                    if (warehouseInput) {
                        warehouseInput.disabled = false;
                        warehouseInput.focus();
                    }
                },
            });
            attach({
                input: warehouseInput,
                refInput: warehouseRefInput,
                suggestions: warehouseSuggestions,
                url: warehousesUrl,
                minLength: 1,
                buildParams: () => ({ city_ref: cityRefInput?.value || '' }),
            });

            deliveryMethodInput?.addEventListener('change', sync);
            form.addEventListener('submit', (event) => {
                if (!isNovaPoshta()) return;
                if (cityInput?.value.trim() && !cityRefInput?.value && !warehouseRefInput?.value) {
                    event.preventDefault();
                    cityInput.setCustomValidity(text.city);
                    cityInput.reportValidity();
                    cityInput.focus();
                    return;
                }
                if (warehouseInput?.value.trim() && !warehouseRefInput?.value) {
                    event.preventDefault();
                    warehouseInput.setCustomValidity(text.warehouse);
                    warehouseInput.reportValidity();
                    warehouseInput.focus();
                }
            });
            document.addEventListener('click', (event) => {
                if (! (event.target instanceof Node)) return;
                if (!citySuggestions?.contains(event.target) && event.target !== cityInput) hide(citySuggestions);
                if (!warehouseSuggestions?.contains(event.target) && event.target !== warehouseInput) hide(warehouseSuggestions);
            });
            sync();
        })();

        @if($canEditOrderItems)
        (() => {
            const summaryNode = document.querySelector('[data-customer-order-summary]');
            const rows = Array.from(document.querySelectorAll('[data-customer-order-item-row]'));
            const usdRate = Number(summaryNode?.dataset.usdRate || 0);

            if (!summaryNode || !rows.length || usdRate <= 0) return;

            const totalUahNode = summaryNode.querySelector('[data-customer-order-total-uah]');
            const totalUsdNode = summaryNode.querySelector('[data-customer-order-total-usd]');
            const formatUah = (value) => `${Math.round(value).toLocaleString('ru-RU').replace(/\u00a0/g, ' ')} грн`;
            const formatUsd = (value) => `${value.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).replace(/,/g, ' ')} USD`;

            const setHelpValue = (node, value) => {
                if (!node) return;

                node.textContent = formatUsd(value);
                node.style.display = '';
                node.classList.toggle('customer-order-zero-usd-price', Number(value) === 0);
            };

            const refreshTotals = () => {
                let totalUah = 0;
                let totalUsd = 0;

                rows.forEach((row) => {
                    const quantity = Number(row.dataset.quantity || 0);
                    const unitPriceInput = row.querySelector('[data-customer-order-unit-price]');
                    const unitPriceUah = Number.parseFloat(unitPriceInput?.value || '0');
                    const lineTotalUah = Number.isFinite(unitPriceUah) ? quantity * unitPriceUah : 0;
                    const unitPriceUsd = lineTotalUah > 0 ? Math.ceil(unitPriceUah / usdRate) : 0;
                    const lineTotalUsd = lineTotalUah > 0 ? quantity * unitPriceUsd : 0;

                    totalUah += lineTotalUah;
                    totalUsd += lineTotalUsd;

                    const lineUahNode = row.querySelector('[data-customer-order-line-uah]');
                    if (lineUahNode) lineUahNode.textContent = formatUah(lineTotalUah);

                    setHelpValue(row.querySelector('[data-customer-order-unit-usd]'), unitPriceUsd);
                    setHelpValue(row.querySelector('[data-customer-order-line-usd]'), lineTotalUsd);
                });

                if (totalUahNode) totalUahNode.textContent = formatUah(totalUah);
                setHelpValue(totalUsdNode, totalUsd);
            };

            rows.forEach((row) => {
                row.querySelector('[data-customer-order-unit-price]')?.addEventListener('input', refreshTotals);
            });

        })();

        (() => {
            const dialog = document.querySelector('[data-customer-order-item-dialog]');
            const openButton = document.querySelector('[data-customer-order-add-item]');
            const closeButton = document.querySelector('[data-customer-order-item-close]');
            const searchInput = document.querySelector('[data-customer-order-item-search]');
            const statusNode = document.querySelector('[data-customer-order-item-status]');
            const resultsNode = document.querySelector('[data-customer-order-item-results]');
            const searchUrl = @json(route('admin.customer-orders.items.catalog-search', $order));
            const storeUrl = @json(route('admin.customer-orders.items.store', $order));
            const csrfToken = @json(csrf_token());
            let searchTimer = null;

            if (!dialog || !openButton || !searchInput || !resultsNode || !statusNode) return;

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const renderResults = (items) => {
                resultsNode.innerHTML = '';

                if (!items.length) {
                    statusNode.textContent = 'Ничего не найдено.';
                    return;
                }

                statusNode.textContent = `Найдено: ${items.length}`;

                items.forEach((item) => {
                    const row = document.createElement('div');
                    row.className = 'panel';
                    row.style.padding = '12px';
                    row.innerHTML = `
                        <form method="POST" action="${escapeHtml(storeUrl)}" style="display:grid; gap:10px;">
                            <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="product_id" value="${escapeHtml(item.product_id || item.id)}">
                            ${item.part_catalog_item_id ? `<input type="hidden" name="part_catalog_item_id" value="${escapeHtml(item.part_catalog_item_id)}">` : ''}
                            <input type="hidden" name="quantity" value="1">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                                <div>
                                    <strong>${escapeHtml(item.name || '-')}</strong>
                                    <div class="help">${escapeHtml(item.part_number || '-')}</div>
                                    ${item.code ? `<div class="help">Код: ${escapeHtml(item.code)}</div>` : ''}
                                    ${item.donor_vin ? `<div class="help">VIN: ${escapeHtml(item.donor_vin)}</div>` : ''}
                                    ${item.category ? `<div class="help">${escapeHtml(item.category)}</div>` : ''}
                                </div>
                                <div style="text-align:right; min-width:120px;">
                                    <strong>${escapeHtml(item.unit_price_uah_text || '-')}</strong>
                                    ${item.unit_price_usd_text ? `<div class="help">${escapeHtml(item.unit_price_usd_text)}</div>` : ''}
                                </div>
                            </div>
                            <div class="actions">
                                <a class="btn btn-small btn-secondary" href="${escapeHtml(item.url)}" target="_blank" rel="noopener">Открыть</a>
                                <button type="submit" class="btn btn-small">Добавить</button>
                            </div>
                        </form>
                    `;
                    resultsNode.appendChild(row);
                });
            };

            const search = async () => {
                const query = searchInput.value.trim();
                if (query.length < 2) {
                    resultsNode.innerHTML = '';
                    statusNode.textContent = 'Введите минимум 2 символа.';
                    return;
                }

                statusNode.textContent = 'Ищу...';

                try {
                    const url = new URL(searchUrl, window.location.origin);
                    url.searchParams.set('q', query);
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    renderResults(response.ok ? await response.json() : []);
                } catch (error) {
                    console.error(error);
                    statusNode.textContent = 'Ошибка поиска.';
                }
            };

            openButton.addEventListener('click', () => {
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', 'open');
                }

                searchInput.focus();
            });

            closeButton?.addEventListener('click', () => dialog.close());
            searchInput.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(search, 250);
            });
        })();
        @endif
        @endif
    </script>
@endsection
