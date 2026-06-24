@php
    $paymentDueUah = max(0, (float) $paymentDueUah);
    $paymentUsdRate = (float) $paymentUsdRate;
    $paymentDueUsd = isset($paymentDueUsd) && $paymentDueUsd !== null
        ? max(0, round((float) $paymentDueUsd, 2))
        : ($paymentUsdRate > 0 ? round($paymentDueUah / $paymentUsdRate, 2) : null);
    $hasPaymentDialogTitle = isset($paymentDialogTitle);
    $hasPaymentDueLabel = isset($paymentDueLabel);
    $hasPaymentSubmitLabel = isset($paymentSubmitLabel);
    $paymentFormAction = $paymentFormAction ?? route('admin.customer-orders.payment.confirm', $order);
    $paymentDefaultAmount = $paymentDefaultAmount ?? number_format($paymentDueUah, 2, '.', '');
    $paymentDialogTitle = $hasPaymentDialogTitle ? $paymentDialogTitle : "\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}";
    $paymentDueLabel = $hasPaymentDueLabel ? $paymentDueLabel : "\u{041A} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0435}";
    $paymentSubmitLabel = $hasPaymentSubmitLabel ? $paymentSubmitLabel : "\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}";
    $paymentAutofill = $paymentAutofill ?? true;
    $paymentRequiresFullAmount = $paymentRequiresFullAmount ?? true;
    $paymentTypes = $paymentTypes ?? collect(\App\Models\CustomerOrder::PAYMENT_TYPE_LABELS)
        ->except([
            \App\Models\CustomerOrder::PAYMENT_TYPE_PROM,
            \App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT,
        ])
        ->all();
    $paymentFixedAmounts = $paymentFixedAmounts ?? [];
@endphp

<dialog class="modal" id="{{ $paymentDialogId }}">
    <div class="modal-header">
        <h2>Подтвердить оплату</h2>
        <button type="button" class="btn btn-secondary" data-customer-order-payment-close aria-label="Закрыть">&times;</button>
    </div>
    <form
        method="POST"
        action="{{ $paymentFormAction }}"
        style="display:grid; gap:12px;"
        data-customer-order-payment-form
        data-payment-due-uah="{{ $paymentDueUah }}"
        data-payment-due-usd="{{ $paymentDueUsd !== null ? $paymentDueUsd : '' }}"
        data-payment-usd-rate="{{ $paymentUsdRate }}"
        data-payment-requires-full-amount="{{ $paymentRequiresFullAmount ? 1 : 0 }}"
        data-payment-dialog-title="{{ $paymentDialogTitle }}"
        data-payment-submit-label="{{ $paymentSubmitLabel }}"
    >
        @csrf
        <style>
            .customer-order-payment-row {
                display: grid;
                grid-template-columns: minmax(130px, 1fr) minmax(120px, 1fr) auto;
                gap: 8px;
                align-items: start;
            }

            .customer-order-payment-actions {
                display: flex;
                gap: 6px;
                align-items: center;
                margin-top: 24px;
            }

            .customer-order-payment-icon {
                width: 32px;
                height: 32px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                border-radius: 8px;
                background: #ffffff;
            }

            .customer-order-payment-icon svg {
                width: 18px;
                height: 18px;
            }

            .customer-order-payment-icon--remove {
                color: #dc2626;
                border-color: #fecaca;
            }

            .customer-order-payment-icon--add {
                color: #16a34a;
                border-color: #bbf7d0;
            }
        </style>
        <div class="help">
            К оплате: {{ $money($paymentDueUah, 'UAH') }}
            @if($paymentDueUsd !== null)
                <div>{{ $money($paymentDueUsd, 'USD') }}</div>
            @endif
        </div>
        <div style="display:grid; gap:12px;" data-customer-order-payment-rows>
            <div class="customer-order-payment-row" data-customer-order-payment-row>
                <label>
                    Тип оплаты
                    <select name="payments[0][payment_type]" required data-payment-type>
                        @foreach($paymentTypes as $paymentType => $paymentLabel)
                            <option value="{{ $paymentType }}" data-fixed-amount="{{ $paymentFixedAmounts[$paymentType] ?? '' }}">{{ $paymentLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Полученная сумма
                    <input
                        type="text"
                        name="payments[0][received_amount]"
                        value="{{ old('payments.0.received_amount', old('received_amount', $paymentDefaultAmount)) }}"
                        inputmode="decimal"
                        pattern="[0-9]+([,.][0-9]{1,2})?"
                        required
                        data-payment-amount
                        data-payment-autofill="{{ $paymentAutofill ? 1 : 0 }}"
                    >
                    <div class="help" data-payment-remainder></div>
                </label>
                <div class="customer-order-payment-actions">
                    <button type="button" class="btn btn-small btn-secondary customer-order-payment-icon customer-order-payment-icon--remove" style="visibility:hidden;" data-payment-remove aria-label="Удалить часть оплаты" title="Удалить">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </button>
                    <button type="button" class="btn btn-small btn-secondary customer-order-payment-icon customer-order-payment-icon--add" data-payment-add aria-label="Добавить часть оплаты" title="Добавить">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14"></path>
                            <path d="M5 12h14"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <template data-customer-order-payment-row-template>
            <div class="customer-order-payment-row" data-customer-order-payment-row>
                <label>
                    Тип оплаты
                    <select required data-payment-type>
                        @foreach($paymentTypes as $paymentType => $paymentLabel)
                            <option value="{{ $paymentType }}" data-fixed-amount="{{ $paymentFixedAmounts[$paymentType] ?? '' }}">{{ $paymentLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Полученная сумма
                    <input type="text" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" required data-payment-amount>
                    <div class="help" data-payment-remainder></div>
                </label>
                <div class="customer-order-payment-actions">
                    <button type="button" class="btn btn-small btn-secondary customer-order-payment-icon customer-order-payment-icon--remove" data-payment-remove aria-label="Удалить часть оплаты" title="Удалить">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </button>
                    <button type="button" class="btn btn-small btn-secondary customer-order-payment-icon customer-order-payment-icon--add" data-payment-add aria-label="Добавить часть оплаты" title="Добавить">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14"></path>
                            <path d="M5 12h14"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </template>
        @error('payment_type')
            <div class="error">{{ $message }}</div>
        @enderror
        @error('received_amount')
            <div class="error">{{ $message }}</div>
        @enderror
        @error('payments')
            <div class="error">{{ $message }}</div>
        @enderror
        @error('payments.*.payment_type')
            <div class="error">{{ $message }}</div>
        @enderror
        @error('payments.*.received_amount')
            <div class="error">{{ $message }}</div>
        @enderror
        <div class="actions">
            <button type="button" class="btn btn-secondary" data-customer-order-payment-close>Отменить</button>
            <button type="submit" class="btn">Подтвердить оплату</button>
        </div>
    </form>
</dialog>
