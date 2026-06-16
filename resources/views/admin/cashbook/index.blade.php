@extends('layouts.admin', [
    'heading' => 'Касса и работы',
    'subheading' => 'Операции, фильтры, сводки и быстрое добавление.',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $selectedLabels = $filters['label'] ?? [];
    $labelNames = [
        ' ' => ' ()',
        '  ' => ' ()',
    ];
    $sort = $filters['sort'] ?? 'operation_date';
    $direction = $filters['direction'] ?? 'desc';
    $defaultDirection = fn ($field) => in_array($field, ['operation_date', 'income', 'expense'], true) ? 'desc' : 'asc';
    $sortUrl = function ($field) use ($sort, $direction, $defaultDirection) {
        $query = request()->query();
        unset($query['page']);
        $query['sort'] = $field;
        $query['direction'] = $sort === $field ? ($direction === 'asc' ? 'desc' : 'asc') : $defaultDirection($field);

        return route('admin.cashbook.index', $query);
    };
    $sortMark = fn ($field) => $sort === $field ? ($direction === 'asc' ? ' ^' : ' v') : '';
    $labelTypes = $labelTypes ?? collect();
    $labelParents = $labelParents ?? collect();
    $parentLabels = $parentLabels ?? collect();
    $hiddenCreateLabels = collect(['Отменена инкассация Валера']);
    $createLabels = $labels->reject(fn ($label) => $hiddenCreateLabels->contains($label))->values();
    $cashbookExchangeRate = function ($transaction): ?float {
        $uahAmount = abs($transaction->totalIncomeUah()) + abs($transaction->totalExpenseUah());
        $usdAmount = abs((float) $transaction->income_cash_usd) + abs((float) $transaction->expense_cash_usd);

        return $uahAmount > 0 && $usdAmount > 0 ? $uahAmount / $usdAmount : null;
    };
@endphp

@section('content')
    <style>
        .cashbook-filters {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cashbook-filters > div {
            flex: 1 1 150px;
            min-width: 150px;
        }

        .cashbook-filters .search-field {
            flex: 1.6 1 240px;
        }

        .cashbook-filters .filter-actions {
            flex: 0 1 auto;
            min-width: max-content;
        }

        .cashbook-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 18px;
        }

        .cashbook-summary-value {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.25;
        }

        .cashbook-amount-positive {
            color: #0f766e;
            font-weight: 700;
        }

        .cashbook-amount-negative {
            color: #9f2d2d;
            font-weight: 700;
        }

        .cashbook-sort-link {
            color: inherit;
            white-space: nowrap;
        }

        .cashbook-create-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-start;
            position: relative;
        }

        .cashbook-create-dropdown {
            position: relative;
        }

        .cashbook-create-dropdown summary {
            list-style: none;
        }

        .cashbook-create-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .cashbook-create-menu {
            position: absolute;
            right: 0;
            z-index: 40;
            display: grid;
            width: min(360px, calc(100vw - 56px));
            max-height: 520px;
            margin-top: 6px;
            padding: 8px;
            overflow: auto;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.14);
        }

        .cashbook-create-menu-title {
            padding: 6px 8px 8px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .cashbook-create-menu-item {
            justify-content: flex-start;
            width: 100%;
            border-radius: 8px;
            background: transparent;
            color: #111827;
            text-align: left;
            white-space: normal;
        }

        .cashbook-create-menu-item:hover {
            background: #f3f4f6;
        }

        .cashbook-create-form {
            display: grid;
            gap: 16px;
        }

        .cashbook-create-section[hidden],
        [data-parts-purchase-section][hidden] {
            display: none;
        }

        #cashbook-create-modal {
            width: min(1120px, calc(100vw - 32px));
        }

        .checkbox-dropdown {
            position: relative;
        }

        .checkbox-dropdown summary {
            width: 100%;
            min-height: 42px;
            padding: 10px 36px 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #111827;
            cursor: pointer;
            list-style: none;
            line-height: 1.25;
            position: relative;
        }

        .checkbox-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .checkbox-dropdown summary::after {
            content: 'v';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }

        .checkbox-dropdown[open] summary::after {
            transform: translateY(-50%) rotate(180deg);
        }

        .checkbox-dropdown-panel {
            position: absolute;
            z-index: 30;
            left: 0;
            width: min(560px, calc(100vw - 56px));
            min-width: 100%;
            max-height: 560px;
            margin-top: 6px;
            padding: 6px;
            overflow: auto;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.14);
        }

        .checkbox-dropdown-option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            color: #111827;
            cursor: pointer;
            font-weight: 400;
            line-height: 1.2;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .checkbox-dropdown-option-child {
            padding-left: 28px;
        }

        .checkbox-dropdown-option input {
            flex: 0 0 auto;
            width: auto;
            margin-top: 2px;
        }

        .checkbox-dropdown-option span {
            min-width: 0;
        }

        .cashbook-payment-method {
            color: #6b7280;
            font-size: 12px;
            font-weight: 500;
        }

        @media (max-width: 980px) {
            .cashbook-filters > div,
            .cashbook-filters .search-field,
            .cashbook-filters .filter-actions {
                flex: 1 1 100%;
                min-width: 0;
            }

            .cashbook-summary {
                grid-template-columns: 1fr;
            }

            .checkbox-dropdown-panel {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <div class="panel">
        <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h2 style="margin:0;">Фильтры</h2>
        </div>
        <form method="GET" class="cashbook-filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <div>
                <label for="from">С даты</label>
                <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div>
                <label for="to">По дату</label>
                <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div>
                <label for="operation_type">Тип операции</label>
                <select id="operation_type" name="operation_type">
                    <option value="">Все типы</option>
                    <option value="income" @selected(($filters['operation_type'] ?? '') === 'income')>Приход</option>
                    <option value="expense" @selected(($filters['operation_type'] ?? '') === 'expense')>Расход</option>
                    <option value="exchange" @selected(($filters['operation_type'] ?? '') === 'exchange')>Обмен</option>
                </select>
            </div>
            <div>
                <label>Метка</label>
                <details class="checkbox-dropdown">
                    <summary>{{ count($selectedLabels) > 0 ? 'Выбрано: '.count($selectedLabels) : 'Все метки' }}</summary>
                    <div class="checkbox-dropdown-panel">
                        <label class="checkbox-dropdown-option">
                            <input type="checkbox" name="label[]" value="__without_label__" @checked(in_array('__without_label__', $selectedLabels, true))>
                            <span>Без метки</span>
                        </label>
                        @foreach ($labels as $label)
                            <label @class(['checkbox-dropdown-option', 'checkbox-dropdown-option-child' => $labelParents->has($label)])>
                                <input
                                    type="checkbox"
                                    name="label[]"
                                    value="{{ $label }}"
                                    data-cashbook-label-type="{{ $labelTypes[$label] ?? 'income' }}"
                                    data-cashbook-parent-label="{{ $labelParents[$label] ?? '' }}"
                                    data-cashbook-has-children="{{ $parentLabels->contains($label) ? '1' : '0' }}"
                                    @checked(in_array($label, $selectedLabels, true))
                                >
                                <span>{{ $labelParents->has($label) ? $labelParents[$label].' / ' : '' }}{{ $labelNames[$label] ?? $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            </div>
            <div>
                <label for="employee">Сотрудник</label>
                <select id="employee" name="employee">
                    <option value="">Все сотрудники</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee }}" @selected(($filters['employee'] ?? '') === $employee)>{{ $employee }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="source_sheet">Месяц</label>
                <select id="source_sheet" name="source_sheet">
                    <option value="">Все месяцы</option>
                    @foreach ($sourceSheets as $sourceSheet)
                        <option value="{{ $sourceSheet }}" @selected(($filters['source_sheet'] ?? '') === $sourceSheet)>{{ $sourceSheet }}</option>
                    @endforeach
                </select>
            </div>
            <div class="search-field">
                <label for="search">Поиск</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Комментарий, VIN, метка или фамилия">
            </div>
            <div>
                <label for="usd_rate">Курс $</label>
                <input id="usd_rate" type="number" step="0.01" min="0" name="usd_rate" value="{{ $filters['usd_rate'] ?? 43 }}">
            </div>
            <div>
                <label for="per_page">Операций</label>
                <select id="per_page" name="per_page">
                    <option value="25" @selected(($filters['per_page'] ?? '') === '25')>25</option>
                    <option value="50" @selected(($filters['per_page'] ?? '') === '50')>50</option>
                    <option value="100" @selected(($filters['per_page'] ?? '100') === '100')>100</option>
                    <option value="500" @selected(($filters['per_page'] ?? '') === '500')>500</option>
                    <option value="all" @selected(($filters['per_page'] ?? '') === 'all')>Все</option>
                </select>
            </div>
            <div class="filter-actions actions">
                <button type="submit">Показать</button>
                <a class="btn btn-secondary" href="{{ route('admin.cashbook.index', ['clear_filters' => 1]) }}">Сбросить</a>
            </div>
        </form>
    </div>

    <div class="cashbook-summary">
        <div class="panel">
            <div class="help">Приход</div>
            <div class="cashbook-summary-value">Нал: {{ $money($summary->income_cash_uah) }} грн</div>
            <div class="cashbook-summary-value">Безнал: {{ $money($summary->income_bank_uah) }} грн</div>
            <div class="cashbook-summary-value">{{ $money($summary->income_cash_usd) }} $</div>
        </div>
        <div class="panel">
            <div class="help">Расход</div>
            <div class="cashbook-summary-value">Нал: {{ $money($summary->expense_cash_uah) }} грн</div>
            <div class="cashbook-summary-value">Безнал: {{ $money($summary->expense_bank_uah) }} грн</div>
            <div class="cashbook-summary-value">{{ $money($summary->expense_cash_usd) }} $</div>
        </div>
        <div class="panel">
            <div class="help">Сумма по фильтру</div>
            <div class="cashbook-summary-value">Нал: {{ $money((float) $summary->income_cash_uah - (float) $summary->expense_cash_uah) }} грн</div>
            <div class="cashbook-summary-value">Безнал: {{ $money((float) $summary->income_bank_uah - (float) $summary->expense_bank_uah) }} грн</div>
            <div class="cashbook-summary-value">{{ $money((float) $summary->income_cash_usd - (float) $summary->expense_cash_usd) }} $</div>
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Операции</h2>
                <div class="help" style="margin-top:6px;">
                    Показано: {{ $transactions->count() }}{{ method_exists($transactions, 'total') ? ' из '.$transactions->total() : '' }}
                </div>
            </div>
            <div class="cashbook-create-actions">
                <button type="button" class="btn" data-open-cashbook-create="income">Приход</button>
                <details class="cashbook-create-dropdown">
                    <summary class="btn btn-danger">Расход</summary>
                    <div class="cashbook-create-menu">
                        <div class="cashbook-create-menu-title">Метки расхода</div>
                        @foreach ($createLabels as $label)
                            @continue(($labelTypes[$label] ?? 'income') !== 'expense' || $labelParents->has($label))
                            <button
                                type="button"
                                class="cashbook-create-menu-item"
                                data-open-cashbook-create="expense"
                                data-cashbook-label="{{ $label }}"
                                data-cashbook-parent-label=""
                                data-cashbook-has-children="{{ $parentLabels->contains($label) ? '1' : '0' }}"
                                @if ($label === ($partsPurchaseLabel ?? 'Закупка ЗЧК')) data-cashbook-redirect-url="{{ route('admin.purchases.create') }}" @endif
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </details>
                <button type="button" class="btn btn-secondary" data-open-cashbook-create="exchange">Обмен</button>
                <span hidden>
                    @foreach ($createLabels as $label)
                        <button
                            type="button"
                            data-open-cashbook-create="{{ $labelTypes[$label] ?? 'income' }}"
                            data-cashbook-label="{{ $label }}"
                            data-cashbook-parent-label="{{ $labelParents[$label] ?? '' }}"
                            data-cashbook-has-children="{{ $parentLabels->contains($label) ? '1' : '0' }}"
                            @if ($label === ($partsPurchaseLabel ?? 'Закупка ЗЧК')) data-cashbook-redirect-url="{{ route('admin.purchases.create') }}" @endif
                        >{{ $label }}</button>
                    @endforeach
                </span>
            </div>
        </div>
        <table>
            <thead>
            <tr>
                <th><a class="cashbook-sort-link" href="{{ $sortUrl('operation_date') }}">Дата{{ $sortMark('operation_date') }}</a></th>
                <th><a class="cashbook-sort-link" href="{{ $sortUrl('income') }}">Приход{{ $sortMark('income') }}</a></th>
                <th><a class="cashbook-sort-link" href="{{ $sortUrl('expense') }}">Расход{{ $sortMark('expense') }}</a></th>
                <th><a class="cashbook-sort-link" href="{{ $sortUrl('label') }}">Метка{{ $sortMark('label') }}</a></th>
                <th>Фамилия</th>
                <th><a class="cashbook-sort-link" href="{{ $sortUrl('details') }}">VIN / комментарий{{ $sortMark('details') }}</a></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $transaction)
                @php($transactionLabelType = $labelTypes[$transaction->label] ?? null)
                @php($transactionExchangeRate = $transactionLabelType === 'exchange' ? $cashbookExchangeRate($transaction) : null)
                @php($workEmployee = $workOrderEmployeesByCashTransaction[$transaction->id] ?? null)
                <tr>
                    <td>{{ $transaction->operation_date?->format('d.m.Y') }}</td>
                    <td class="cashbook-amount-positive">
                        @if ($transaction->totalIncomeUah() > 0)
                            <div>{{ $money($transaction->totalIncomeUah()) }} грн</div>
                            @if ($transaction->incomeUahPaymentLabel())
                                <div class="cashbook-payment-method">{{ $transaction->incomeUahPaymentLabel() }}</div>
                            @endif
                        @endif
                        @if ((float) $transaction->income_cash_usd > 0)<div>{{ $money($transaction->income_cash_usd) }} $</div>@endif
                    </td>
                    <td class="cashbook-amount-negative">
                        @if ($transaction->totalExpenseUah() > 0)
                            <div>{{ $money($transaction->totalExpenseUah()) }} грн</div>
                            @if ($transaction->expenseUahPaymentLabel())
                                <div class="cashbook-payment-method">{{ $transaction->expenseUahPaymentLabel() }}</div>
                            @endif
                        @endif
                        @if ((float) $transaction->expense_cash_usd > 0)<div>{{ $money($transaction->expense_cash_usd) }} $</div>@endif
                    </td>
                    <td>
                        <span @class([
                            'tag',
                            'tag-danger' => $transaction->totalExpenseUah() > 0 || (float) $transaction->expense_cash_usd > 0,
                            'tag-exchange' => $transactionLabelType === 'exchange',
                            'tag-exchange-with-rate' => $transactionExchangeRate !== null,
                        ])>
                            <span>{{ $labelParents->has($transaction->label) ? $labelParents[$transaction->label].' / ' : '' }}{{ $transaction->label ?: 'без метки' }}</span>
                            @if ($transactionExchangeRate !== null)
                                <span class="tag-exchange-rate">Курс: {{ $money($transactionExchangeRate) }}</span>
                            @endif
                        </span>
                    </td>
                    <td>{{ $workEmployee ?: ($transaction->employee ?: '—') }}</td>
                    <td>
                        @if ($transaction->vehicle_vin)<div class="help">{{ $transaction->vehicle_vin }}</div>@endif
                        {{ $transaction->detailsText() ?: '—' }}
                    </td>
                    <td class="actions">
                        <a class="btn btn-small btn-secondary" href="{{ route('admin.cashbook.show', $transaction) }}">Открыть</a>
                        @if (! $transaction->isStoWorkOrderPayment() && ! $transaction->isCancelled() && ! $transaction->hasConfirmedValeraCashbookTransfer() && $transaction->canBeEdited())
                            <a class="btn btn-small" href="{{ route('admin.cashbook.edit', $transaction) }}">Править</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Операций пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if (method_exists($transactions, 'links'))
            <div style="margin-top:14px;">{{ $transactions->links() }}</div>
        @endif
    </div>

    @include('admin.reports._monthly_blocks')

    <dialog id="cashbook-create-modal" class="modal">
        <div class="modal-header">
            <h2 id="cashbook-create-modal-title">Новая операция</h2>
            <button type="button" class="btn btn-secondary btn-small" data-close-cashbook-create>Закрыть</button>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" style="margin-bottom:14px;">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.cashbook.store') }}" class="cashbook-create-form">
            @csrf
            <input id="cashbook_transaction_type" type="hidden" name="transaction_type" value="{{ old('transaction_type', 'expense') }}">
            <input type="hidden" name="source" value="{{ old('source', $newTransaction->source ?: 'manual') }}">
            <input type="hidden" name="source_sheet" value="{{ old('source_sheet', $newTransaction->source_sheet) }}">
            <input type="hidden" name="exchange_rate" value="{{ old('exchange_rate', $newTransaction->exchange_rate) }}">

            @include('admin.cashbook._form_fields', [
                'formPrefix' => 'modal_',
                'transaction' => $newTransaction,
                'labels' => $createLabels,
            ])

            <div class="actions" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" data-close-cashbook-create>Отмена</button>
                <button type="submit">Сохранить</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const modal = document.getElementById('cashbook-create-modal');
            const title = document.getElementById('cashbook-create-modal-title');
            const typeInput = document.getElementById('cashbook_transaction_type');
            const modalLabelSelect = document.getElementById('modal_label');
            const modalEmployeeSelect = document.getElementById('modal_employee');
            const vinSelect = document.getElementById('modal_vehicle_vin');
            const donorExpenseField = document.querySelector('[data-cashbook-donor-expense-field]');
            const donorExpenseSelect = document.querySelector('[data-cashbook-donor-expense-select]');
            const incomeFields = document.querySelectorAll('[data-cashbook-income-field]');
            const expenseFields = document.querySelectorAll('[data-cashbook-expense-field]');
            const openButtons = document.querySelectorAll('[data-open-cashbook-create]');
            const closeButtons = document.querySelectorAll('[data-close-cashbook-create]');
            const titles = {
                income: 'Новый приход',
                expense: 'Новый расход',
                exchange: 'Новый обмен',
            };

            if (! modal || ! typeInput || ! modalLabelSelect) {
                return;
            }

            const applyDonorExpenseOptions = () => {
                const selectedVinOption = vinSelect?.selectedOptions[0];
                let filledTypes = [];

                if (selectedVinOption?.dataset.donorFilledExpenseTypes) {
                    try {
                        filledTypes = JSON.parse(selectedVinOption.dataset.donorFilledExpenseTypes);
                    } catch {
                        filledTypes = [];
                    }
                }

                if (!donorExpenseSelect) {
                    return;
                }

                Array.from(donorExpenseSelect.options).forEach((option) => {
                    option.hidden = Boolean(option.value) && filledTypes.includes(option.value);
                });

                if (donorExpenseSelect.selectedOptions[0]?.hidden) {
                    donorExpenseSelect.value = '';
                }
            };

            const applyFormState = () => {
                const selectedLabelOption = modalLabelSelect.selectedOptions[0];
                const mode = typeInput.value;
                const parentLabel = selectedLabelOption?.dataset.cashbookParentLabel || modalLabelSelect.dataset.cashbookParentMode || '';
                const isRepairMechanic = modalLabelSelect.value === '';
                const isDonor = modalLabelSelect.value === 'Донор';
                const shouldHide = selectedLabelOption?.dataset.cashbookHideEmployee === '1';

                if (selectedLabelOption?.dataset.cashbookRedirectUrl) {
                    window.location.href = selectedLabelOption.dataset.cashbookRedirectUrl;
                    return;
                }

                title.textContent = `${titles[mode] ?? titles.income} ${parentLabel}`.trim();

                if (modalEmployeeSelect) {
                    Array.from(modalEmployeeSelect.options).forEach((option) => {
                        option.hidden = isRepairMechanic && option.value && option.dataset.cashbookMechanic !== '1';
                    });

                    if (modalEmployeeSelect.selectedOptions[0]?.hidden || shouldHide) {
                        modalEmployeeSelect.value = '';
                    }

                    modalEmployeeSelect.disabled = shouldHide;
                    modalEmployeeSelect.closest('[data-cashbook-employee-field]').hidden = shouldHide;
                }

                if (vinSelect) {
                    vinSelect.required = isDonor;
                }

                if (donorExpenseField) {
                    donorExpenseField.hidden = !isDonor;
                }

                if (donorExpenseSelect) {
                    donorExpenseSelect.required = isDonor;
                    donorExpenseSelect.disabled = !isDonor;

                    if (!isDonor) {
                        donorExpenseSelect.value = '';
                    }
                }

                applyDonorExpenseOptions();
            };

            const setMode = (mode, parentLabel = '') => {
                const normalizedMode = ['income', 'expense', 'exchange'].includes(mode) ? mode : 'expense';
                typeInput.value = normalizedMode;
                modalLabelSelect.dataset.cashbookParentMode = parentLabel;
                title.textContent = `${titles[normalizedMode] ?? titles.income} ${parentLabel}`.trim();

                incomeFields.forEach((field) => {
                    field.hidden = normalizedMode !== 'income' && normalizedMode !== 'exchange';
                });

                expenseFields.forEach((field) => {
                    field.hidden = normalizedMode !== 'expense' && normalizedMode !== 'exchange';
                });

                Array.from(modalLabelSelect.options).forEach((option) => {
                    if (! option.value) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    const isChildOfParent = parentLabel && option.dataset.cashbookParentLabel === parentLabel;
                    const matchesMode = option.dataset.cashbookLabelType === normalizedMode;
                    option.hidden = parentLabel ? !isChildOfParent : !matchesMode || option.dataset.cashbookParentLabel;
                    option.disabled = false;

                    if (option.value === 'Закупка ЗЧК') {
                        option.dataset.cashbookRedirectUrl = '{{ route('admin.purchases.create') }}';
                    }
                });

                if (modalLabelSelect.selectedOptions[0]?.hidden || modalLabelSelect.selectedOptions[0]?.disabled) {
                    modalLabelSelect.value = '';
                }

                applyFormState();
            };

            openButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const mode = button.dataset.openCashbookCreate || 'expense';
                    const label = button.dataset.cashbookLabel || '';
                    const parentLabel = button.dataset.cashbookParentLabel || '';

                    button.closest('details')?.removeAttribute('open');

                    if (button.dataset.cashbookRedirectUrl) {
                        window.location.href = button.dataset.cashbookRedirectUrl;
                        return;
                    }

                    if (button.dataset.cashbookHasChildren === '1' && label) {
                        setMode(mode, label);
                        modalLabelSelect.value = '';

                        if (typeof modal.showModal === 'function') {
                            modal.showModal();
                        } else {
                            modal.setAttribute('open', 'open');
                        }

                        return;
                    }

                    setMode(mode, parentLabel);

                    if (label) {
                        modalLabelSelect.value = label;
                        applyFormState();
                    }

                    if (typeof modal.showModal === 'function') {
                        modal.showModal();
                    } else {
                        modal.setAttribute('open', 'open');
                    }
                });
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', () => modal.close());
            });

            vinSelect?.addEventListener('change', () => {
                if (vinSelect.value === '__add_donor__' && vinSelect.dataset.addDonorUrl) {
                    window.location.href = vinSelect.dataset.addDonorUrl;
                }

                applyDonorExpenseOptions();
            });

            modalLabelSelect.addEventListener('change', () => {
                const selectedLabelOption = modalLabelSelect.selectedOptions[0];

                if (selectedLabelOption?.dataset.cashbookHasChildren === '1') {
                    setMode(typeInput.value, selectedLabelOption.value);
                    modalLabelSelect.value = '';

                    return;
                }

                applyFormState();
            });
            setMode(typeInput.value || 'expense');
        })();
    </script>
@endsection
