@php
    $trackingNumber = (string) ($shipment?->tracking_number ?? '');
    $trackingLabel = $trackingLabel ?? "\u{0422}\u{0422}\u{041D}";
    $trackingId = 'customer-order-ttn-'.$order->id.'-'.substr(md5((string) spl_object_id($order).$trackingNumber), 0, 8);
    $canEditTrackingNumber = $canEditTrackingNumber ?? $order->canUpdateNovaPoshtaTrackingNumber();
    $canAddTrackingNumber = $canAddTrackingNumber ?? $order->canAddNovaPoshtaTrackingNumber();
    $addTrackingItems = $order->items
        ->map(fn ($item): array => [
            'id' => $item->id,
            'label' => trim(collect([
                $item->name,
                $item->part_number ?: $item->code,
            ])->filter()->implode(' · ')),
        ])
        ->values();
    $afterpaymentAmount = round((float) ($shipment?->afterpayment_amount ?? 0), 2);
    $orderAfterpaymentAmount = $order->relationLoaded('novaPoshtaShipments')
        ? round($order->novaPoshtaShipments->sum(fn ($orderShipment): float => (float) $orderShipment->afterpayment_amount), 2)
        : round((float) $order->novaPoshtaShipments()->sum('afterpayment_amount'), 2);
    $afterpaymentWarning = $orderAfterpaymentAmount > 0
        && round($orderAfterpaymentAmount + (float) $order->paid_amount_uah, 2) < round((float) $order->total_amount, 2);
    $afterpaymentText = $afterpaymentAmount > 0
        ? number_format($afterpaymentAmount, 0, '.', ' ').' грн'
        : null;
    $labelRouteParameters = [$order];
    if ($shipment?->id) {
        $labelRouteParameters['shipment'] = $shipment->id;
    }
    $labelUrl = route('admin.customer-orders.nova-poshta.label', $labelRouteParameters);
    $canPrintLabel = filled($shipment?->np_ref);
    $manualLabelTooltip = "\u{0422}\u{0422}\u{041D} \u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}\u{0430} \u{0432}\u{0440}\u{0443}\u{0447}\u{043D}\u{0443}\u{044E} \u{0438}\u{043B}\u{0438} \u{0432} \u{0434}\u{0440}\u{0443}\u{0433}\u{043E}\u{043C} \u{043A}\u{0430}\u{0431}\u{0438}\u{043D}\u{0435}\u{0442}\u{0435} \u{041D}\u{041F}, \u{0435}\u{0435} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0440}\u{0430}\u{0441}\u{043F}\u{0435}\u{0447}\u{0430}\u{0442}\u{0430}\u{0442}\u{044C} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0447}\u{0435}\u{0440}\u{0435}\u{0437} \u{041A}\u{0430}\u{0431}\u{0438}\u{043D}\u{0435}\u{0442} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}";
@endphp

@once
    <style>
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
        .customer-order-ttn-add-modal {
            width: min(560px, calc(100vw - 32px));
        }
        .customer-order-ttn-add-items {
            display: grid;
            gap: 8px;
            max-height: 260px;
            overflow: auto;
            margin-top: 8px;
            padding-right: 4px;
        }
        .customer-order-ttn-add-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            margin: 0;
            font-weight: 500;
        }
        .customer-order-ttn-add-item input {
            width: auto;
            margin-top: 3px;
        }
    </style>
    <dialog class="modal customer-order-ttn-add-modal" data-customer-order-ttn-add-modal>
        <div class="modal-header">
            <h2>{{ "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}" }}</h2>
            <button type="button" class="btn btn-small btn-secondary" data-customer-order-ttn-add-close>{{ "\u{00D7}" }}</button>
        </div>
        <form data-customer-order-ttn-add-form>
            <label>
                {{ "\u{041D}\u{043E}\u{043C}\u{0435}\u{0440} \u{0422}\u{0422}\u{041D}" }}
                <input type="text" name="tracking_number" maxlength="64" inputmode="numeric" autocomplete="off" data-customer-order-ttn-add-input>
            </label>
            <div style="margin-top:12px;">
                <div class="help">{{ "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{0432} \u{044D}\u{0442}\u{043E}\u{0439} \u{0422}\u{0422}\u{041D}" }}</div>
                <div class="customer-order-ttn-add-items" data-customer-order-ttn-add-items></div>
            </div>
            <div class="error" data-customer-order-ttn-add-error hidden style="margin-top:10px;"></div>
            <div class="actions" style="margin-top:16px;">
                <button type="submit" class="btn btn-small">{{ "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C}" }}</button>
                <button type="button" class="btn btn-small btn-secondary" data-customer-order-ttn-add-cancel>{{ "\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0430}" }}</button>
            </div>
        </form>
    </dialog>
@endonce

@if($trackingNumber !== '' || ($showAddTrackingButton ?? false))
    <div
        class="help customer-order-ttn-row"
        data-customer-order-ttn-editor
        data-customer-order-id="{{ $order->id }}"
        data-customer-order-shipment-id="{{ $shipment?->id }}"
        data-update-url="{{ route('admin.customer-orders.nova-poshta.tracking-number.update', $order) }}"
        @if($showAddTrackingButton ?? false) data-store-url="{{ route('admin.customer-orders.nova-poshta.tracking-number.store', $order) }}" @endif
        @if($showAddTrackingButton ?? false) data-add-items='{{ $addTrackingItems->toJson(JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}' @endif
        @if($rowStyle ?? null) style="{{ $rowStyle }}" @endif
    >
        <span data-customer-order-ttn-display>
            <span>{{ $trackingLabel }}{{ ": " }}</span><strong data-customer-order-ttn-value>{{ $trackingNumber !== '' ? $trackingNumber : '-' }}</strong>
        </span>
        @if($afterpaymentText)
            <span class="customer-order-ttn-afterpayment" data-customer-order-ttn-afterpayment>
                {{ "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}: " }}<strong>{{ $afterpaymentText }}</strong>
                @if($afterpaymentWarning)
                    <span
                        class="customer-order-ttn-warning"
                        title="{{ "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436} \u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{043C}\u{0435}\u{043D}\u{044C}\u{0448}\u{0435} \u{0441}\u{0443}\u{043C}\u{043C}\u{044B} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}" }}"
                        aria-label="{{ "\u{0412}\u{043D}\u{0438}\u{043C}\u{0430}\u{043D}\u{0438}\u{0435}: \u{043D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436} \u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{043C}\u{0435}\u{043D}\u{044C}\u{0448}\u{0435} \u{0441}\u{0443}\u{043C}\u{043C}\u{044B} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}" }}"
                        role="img"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path>
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                        </svg>
                    </span>
                @endif
            </span>
        @endif
        @if($canEditTrackingNumber && $trackingNumber !== '')
            <button
                type="button"
                class="btn btn-small btn-secondary customer-order-ttn-button"
                data-customer-order-ttn-edit
                aria-label="{{ "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}" }}"
                title="{{ "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}" }}"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                </svg>
            </button>
        @endif
        @if($trackingNumber !== '' && ($showLabelButton ?? true) && $shipment?->status !== \App\Models\CustomerOrderShipment::STATUS_CANCELLED)
            @if($canPrintLabel)
                <a
                    class="btn btn-small btn-secondary customer-order-ttn-button customer-order-ttn-button-muted"
                    href="{{ $labelUrl }}"
                    target="_blank"
                    rel="noopener"
                    data-customer-order-ttn-label-link
                    data-customer-order-id="{{ $order->id }}"
                    data-customer-order-shipment-id="{{ $shipment?->id }}"
                    aria-label="{{ "\u{041F}\u{0435}\u{0447}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}" }}"
                    title="{{ "\u{041F}\u{0435}\u{0447}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}" }}"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9V2h12v7"></path>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <path d="M6 14h12v8H6z"></path>
                    </svg>
                </a>
            @else
                <span
                    class="btn btn-small btn-secondary customer-order-ttn-button customer-order-ttn-button-muted"
                    data-customer-order-ttn-label-disabled
                    data-customer-order-id="{{ $order->id }}"
                    data-customer-order-shipment-id="{{ $shipment?->id }}"
                    aria-label="{{ $manualLabelTooltip }}"
                    title="{{ $manualLabelTooltip }}"
                    role="img"
                    style="opacity:.55; cursor:not-allowed;"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9V2h12v7"></path>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <path d="M6 14h12v8H6z"></path>
                    </svg>
                </span>
            @endif
        @endif
        @if($trackingNumber !== '' && ($showTrackingButton ?? false))
            <a
                class="btn btn-small btn-secondary customer-order-ttn-button customer-order-ttn-button-muted"
                href="{{ $shipment?->tracking_url }}"
                target="_blank"
                rel="noopener"
                data-customer-order-ttn-tracking-link
                data-customer-order-id="{{ $order->id }}"
                data-customer-order-shipment-id="{{ $shipment?->id }}"
                aria-label="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0422}\u{0422}\u{041D}" }}"
                title="{{ "\u{0422}\u{0440}\u{0435}\u{043A}\u{0438}\u{043D}\u{0433} \u{0422}\u{0422}\u{041D}" }}"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 0 1 18 0Z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
            </a>
        @endif
        @if(($showAddTrackingButton ?? false) && $canAddTrackingNumber)
            <button
                type="button"
                class="btn btn-small btn-secondary customer-order-ttn-button customer-order-ttn-button-muted"
                data-customer-order-ttn-add
                aria-label="{{ "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0432}\u{0442}\u{043E}\u{0440}\u{0443}\u{044E} \u{0422}\u{0422}\u{041D}" }}"
                title="{{ "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0432}\u{0442}\u{043E}\u{0440}\u{0443}\u{044E} \u{0422}\u{0422}\u{041D}" }}"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
            </button>
        @endif
        @if($canEditTrackingNumber && $trackingNumber !== '')
            <form class="customer-order-ttn-form" data-customer-order-ttn-form hidden>
                <label for="{{ $trackingId }}" class="sr-only">{{ "\u{041D}\u{043E}\u{043C}\u{0435}\u{0440} \u{0422}\u{0422}\u{041D}" }}</label>
                <input id="{{ $trackingId }}" type="text" name="tracking_number" value="{{ $trackingNumber }}" maxlength="64" inputmode="numeric" data-customer-order-ttn-input>
                <button type="submit" class="btn btn-small customer-order-ttn-save">{{ "\u{041E}\u{041A}" }}</button>
                <button type="button" class="btn btn-small btn-secondary customer-order-ttn-cancel" data-customer-order-ttn-cancel>{{ "\u{00D7}" }}</button>
            </form>
        @endif
        <span class="error customer-order-ttn-error" data-customer-order-ttn-error hidden></span>
    </div>
@endif
