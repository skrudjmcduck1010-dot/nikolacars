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
        \App\Models\CustomerOrder::STATUS_CANCELLED => 'tag-danger',
        \App\Models\CustomerOrder::STATUS_SHIPPED => 'tag-warning',
        \App\Models\CustomerOrder::STATUS_COMPLETED => 'tag-paid',
        \App\Models\CustomerOrder::STATUS_PAID => 'tag-paid',
        default => '',
    };
    $canEditOrder = $order->canBeEdited();
    $orderIsFullyPaid = round((float) $order->paid_amount_uah) >= round((float) $orderTotalAmountUah);
    $orderCanBeMarkedAsCompleted = $order->canBeMarkedAsCompleted() && ($order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO || $orderIsFullyPaid);
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
    $paymentParts = $prepaymentPartsFor($order);
    $prepaymentSummary = $prepaymentSummaryFor($paymentParts);
    $paymentSummary = $paymentParts
        ->map(fn (array $part): string => "{$part['label']}: {$part['amount_text']}")
        ->join(' + ');
    $paymentIsFull = $orderIsFullyPaid && (float) $order->paid_amount_uah > 0;
    $paymentDueUsdFor = function (float $paymentDueUah) use ($order, $paymentUsdRate, $usdRate): ?float {
        $rate = (float) (($paymentUsdRate ?? $usdRate)['rate'] ?? 0);

        if ($order->total_amount_usd_hint === null) {
            return $rate > 0 ? round($paymentDueUah / $rate, 2) : null;
        }

        $paidNonUsdUah = (float) $order->paid_cash_uah
            + (float) $order->paid_bank_tov_uah
            + (float) $order->paid_bank_fop_uah;
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
                    <th>Способ получения</th>
                    <td>
                        @if($canEditOrder)
                        <form method="POST" action="{{ route('admin.customer-orders.delivery-method.update', $order) }}" style="display:flex; gap:8px; align-items:center; max-width:360px;">
                            @csrf
                            @method('PATCH')
                            <select name="delivery_method" required aria-label="Способ получения">
                                @foreach(\App\Models\CustomerOrder::DELIVERY_METHOD_LABELS as $method => $label)
                                    <option value="{{ $method }}" @selected(old('delivery_method', $order->delivery_method) === $method)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-small">Сохранить</button>
                        </form>
                        @else
                            {{ $order->delivery_method_label ?: '-' }}
                        @endif
                        @error('delivery_method')
                            <div class="error">{{ $message }}</div>
                        @enderror
                        @error('order')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </td>
                </tr>
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
            <h2 class="section-title" style="margin-top:0;">Заказ</h2>
            <table>
                <tr><th>Номер</th><td>{{ $order->number }}</td></tr>
                <tr>
                    <th>Статус</th>
                    <td>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <div>
                                <span class="tag {{ $statusClass }}">{{ $order->status_label }}</span>
                                @if($order->isIssuedToClient() && $order->delivery_method === \App\Models\CustomerOrder::DELIVERY_METHOD_STO)
                                    <div class="help" style="font-size:11px; margin-top:4px;">
                                        {{ "\u{0411}\u{0435}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}" }}
                                    </div>
                                @endif
                            </div>
                            @if($order->canBeMarkedAsAssembled())
                                <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_ASSEMBLED }}">
                                    <button type="submit" class="btn btn-small">Собран</button>
                                </form>
                            @endif
                            @if($order->canBeMarkedAsShipped())
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
                            @if($order->canConfirmPayment() && ! $orderIsFullyPaid)
                                <button type="button" class="btn btn-small" onclick="document.getElementById('customer-order-payment')?.showModal()">Подтвердить оплату</button>
                            @endif
                            @if($order->canBeCancelled() && ! $orderIsFullyPaid)
                                <form method="POST" action="{{ route('admin.customer-orders.status.update', $order) }}" class="inline-form" onsubmit='return confirm(@json("Отменить заказ {$order->number}? Товары будут сняты с резерва."));'>
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ \App\Models\CustomerOrder::STATUS_CANCELLED }}">
                                    <button type="submit" class="btn btn-small btn-danger">Отменить</button>
                                </form>
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
                    </td>
                </tr>
                <tr><th>Создан</th><td>{{ $order->created_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</td></tr>
                <tr>
                    <th>Сумма</th>
                    <td data-customer-order-summary data-usd-rate="{{ (float) ($usdRate['rate'] ?? 0) }}">
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <strong data-customer-order-total-uah>{{ $money($orderTotalAmountUah, 'UAH') }}</strong>
                            @if($order->canAcceptPrepayment() && ! $orderIsFullyPaid)
                                <button type="button" class="btn btn-small btn-secondary" onclick="document.getElementById('customer-order-prepayment')?.showModal()">{{ "\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}" }}</button>
                            @endif
                        </div>
                        <div class="help" data-customer-order-total-usd @if($order->total_amount_usd_hint === null) style="display:none;" @endif>
                            @if($order->total_amount_usd_hint !== null)
                                {{ $money($order->total_amount_usd_hint, 'USD') }}
                            @endif
                        </div>
                        @if(! $paymentIsFull && (float) $order->paid_cash_usd > 0 && $prepaymentSummary)
                            <div class="help">{{ $prepaymentSummary }}</div>
                        @endif
                        @if(! $paymentIsFull && (float) $order->paid_amount_uah > 0 && (float) $order->paid_cash_usd <= 0)
                            <div class="help">
                                {{ "\u{0412}\u{043D}\u{0435}\u{0441}\u{0435}\u{043D}\u{043E}: " }}{{ $money($order->paid_amount_uah, 'UAH') }}
                            </div>
                        @endif
                    </td>
                </tr>
                @if($paymentIsFull)
                    <tr>
                        <th>Оплата</th>
                        <td>
                            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                <strong>{{ $paymentSummary ?: $money($order->paid_amount_uah, 'UAH') }}</strong>
                                <span class="customer-order-paid-badge">{{ "\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}" }}</span>
                            </div>
                            @if($order->payment_confirmed_at)
                                <div class="help">{{ $order->payment_confirmed_at?->timezone('Europe/Kiev')->format('d.m.Y H:i') }}</div>
                            @endif
                        </td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Товары</h2>
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
                        @if($canEditOrder)
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
                    <td class="actions">
                        @if($canEditOrder)
                        <form method="POST" action="{{ route('admin.customer-orders.items.destroy', [$order, $item]) }}" class="inline-form" onsubmit="return confirm('Удалить товар из заказа?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-small btn-danger">Удалить</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($canEditOrder)
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
            'paymentSubmitLabel' => "\u{0421}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{044C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}",
            'paymentDefaultAmount' => '',
            'paymentAutofill' => false,
            'paymentRequiresFullAmount' => false,
        ])
    @endif

    @if($order->canConfirmPayment() && ! $orderIsFullyPaid)
        @php($paymentDueUah = max(0, (float) $orderTotalAmountUah - (float) $order->paid_amount_uah))
        @php($paymentDueUsd = $paymentDueUsdFor($paymentDueUah))
        @include('admin.customer_orders._payment_modal', [
            'paymentDialogId' => 'customer-order-payment',
            'paymentDueUah' => $paymentDueUah,
            'paymentDueUsd' => $paymentDueUsd,
            'paymentUsdRate' => $paymentUsdRateFor($paymentDueUah, $paymentDueUsd),
        ])
    @endif

    @if($canEditOrder)
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
    </script>
@endsection
