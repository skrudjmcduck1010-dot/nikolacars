@php
    $useFixedColumns = $useFixedColumns ?? false;
    $statusClass = fn (\App\Models\CustomerOrder $order): string => match ($order->status) {
        \App\Models\CustomerOrder::STATUS_CANCELLED => 'tag-danger',
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
    $paymentDueUsdFor = function (\App\Models\CustomerOrder $order, float $paymentDueUah) use ($usdRate): ?float {
        $rate = (float) (($usdRate ?? [])['rate'] ?? 0);

        if ($order->total_amount_usd_hint === null) {
            return $rate > 0 ? round($paymentDueUah / $rate, 2) : null;
        }

        $paidNonUsdUah = (float) $order->paid_cash_uah
            + (float) $order->paid_bank_tov_uah
            + (float) $order->paid_bank_fop_uah;
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
</style>

<table @if($useFixedColumns) style="table-layout:fixed; overflow-wrap:anywhere;" @endif>
    @if($useFixedColumns)
        <colgroup>
            <col style="width:9%;">
            <col style="width:14%;">
            <col style="width:9%;">
            <col style="width:11%;">
            <col style="width:29%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:8%;">
        </colgroup>
    @endif
    <thead>
    <tr>
        <th>Номер</th>
        <th>Клиент</th>
        <th>{{ "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431}" }}<br>{{ "\u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}" }}</th>
        <th>Статус</th>
        <th>Товары</th>
        <th>Сумма</th>
        <th>Дата</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    @forelse($orders as $order)
        @php($rowTotalAmountUah = (float) ($orderTotalAmountUah[$order->id] ?? $order->total_amount))
        @php($rowIsFullyPaid = $orderIsFullyPaid($order, $rowTotalAmountUah))
        @php($rowCanBeMarkedAsCompleted = $order->canBeMarkedAsCompleted() && ($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO || $rowIsFullyPaid))
        <tr>
            <td class="customer-order-items-cell">
                <a href="{{ route('admin.customer-orders.show', $order) }}"><strong>{{ $order->number }}</strong></a>
                @if($order->creator)
                    <div class="help">Создал: {{ $order->creator->stoEmployee?->full_name ?: ($order->creator->name ?: $order->creator->email) }}</div>
                @endif
            </td>
            <td>
                {{ $clientNameFor($order) }}
                @if($order->client_phone)
                    <div class="help">{{ $order->client_phone }}</div>
                @endif
            </td>
            <td>{{ $order->delivery_method_label ?: '-' }}</td>
            <td>
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <div>
                        <span class="tag {{ $statusClass($order) }}">{{ $order->status_label }}</span>
                        @if($order->canBeMarkedAsAssembled())
                            <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" style="display:block; margin-top:6px;">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
                                <button type="submit" class="btn btn-small">{{ "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}" }}</button>
                            </form>
                        @endif
                        @if($order->isIssuedToClient() && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO)
                            <div class="help" style="font-size:11px; margin-top:4px;">
                                {{ "\u{0411}\u{0435}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}" }}
                            </div>
                        @endif
                    </div>
                    @if($order->canBeMarkedAsShipped())
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
                    <div class="customer-order-item-row">
                        <div class="customer-order-item-main">
                            <div class="customer-order-item-title">
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
                <strong>{{ $money($rowTotalAmountUah, 'UAH') }}</strong>
                @if($order->total_amount_usd_hint !== null)
                    <div class="help">{{ $money($order->total_amount_usd_hint, 'USD') }}</div>
                @endif
                @if((float) $order->paid_amount_uah > 0)
                    @php($rowPaymentParts = $prepaymentPartsFor($order))
                    @php($rowPaymentSummary = $rowPaymentParts->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")->join(' + '))
                    @php($rowPrepaymentSummary = $prepaymentSummaryFor($rowPaymentParts))
                    @if($rowIsFullyPaid)
                        <div class="help" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:12px;">
                            <strong>{{ $rowPaymentSummary ?: $money($order->paid_amount_uah, 'UAH') }}</strong>
                            <span class="customer-order-paid-badge">{{ "\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}" }}</span>
                        </div>
                    @elseif($rowPrepaymentSummary)
                        <div class="help" style="margin-top:12px;">{{ $rowPrepaymentSummary }}</div>
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
                    ])
                @endif
            </td>
            <td>{{ $order->created_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</td>
            <td>
                @if($order->canConfirmPayment() && ! $rowIsFullyPaid)
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
                @if($order->canBeCancelled() && ! $rowIsFullyPaid)
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
