@extends('layouts.admin', [
    'heading' => 'Касса Валера',
    'subheading' => 'Отдельная касса из файла Тесла Касса 2.xlsx.',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $sort = $filters['sort'] ?? 'operation_date';
    $direction = $filters['direction'] ?? 'desc';
    $defaultDirection = fn ($field) => in_array($field, ['operation_date', 'income', 'expense'], true) ? 'desc' : 'asc';
    $sortUrl = function ($field) use ($sort, $direction, $defaultDirection) {
        $query = request()->query();
        unset($query['page']);
        $query['sort'] = $field;
        $query['direction'] = $sort === $field ? ($direction === 'asc' ? 'desc' : 'asc') : $defaultDirection($field);

        return route('admin.valera-cashbook.index', $query);
    };
    $sortMark = fn ($field) => $sort === $field ? ($direction === 'asc' ? ' ^' : ' v') : '';
    $labelTypes = $labelTypes ?? collect();
    $valeraExchangeRate = function ($transaction): ?float {
        $uahAmount = abs((float) $transaction->income_uah) + abs((float) $transaction->expense_uah);
        $usdAmount = abs((float) $transaction->income_usd) + abs((float) $transaction->expense_usd);

        return $uahAmount > 0 && $usdAmount > 0 ? $uahAmount / $usdAmount : null;
    };
@endphp

@section('content')
    <style>
        .valera-cashbook-filters {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .valera-cashbook-filters > div {
            flex: 1 1 150px;
            min-width: 150px;
        }

        .valera-cashbook-filters .search-field {
            flex: 1.6 1 240px;
        }

        .valera-cashbook-filters .filter-actions {
            flex: 0 1 auto;
            min-width: max-content;
        }

        .valera-cashbook-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-top: 18px;
        }

        .valera-cashbook-summary-value {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.2;
        }

        .valera-cashbook-amount-positive {
            color: #0f766e;
            font-weight: 700;
        }

        .valera-cashbook-amount-negative {
            color: #9f2d2d;
            font-weight: 700;
        }

        .valera-cashbook-sort-link {
            color: inherit;
            white-space: nowrap;
        }

        .valera-cashbook-create-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .valera-cashbook-create-form {
            display: grid;
            gap: 16px;
        }

        .valera-cashbook-create-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .valera-cashbook-create-form-grid .full {
            grid-column: 1 / -1;
        }

        .valera-cashbook-create-section[hidden],
        [data-valera-donor-expense-field][hidden] {
            display: none;
        }

        .valera-cashbook-table-wrap {
            overflow-x: auto;
        }

        .valera-cashbook-table {
            min-width: 880px;
        }

        .valera-cashbook-actions-cell {
            width: 1%;
            text-align: right;
            white-space: nowrap;
        }

        .valera-cashbook-actions-cell .inline-form {
            display: inline-flex;
        }

        .valera-cashbook-actions-cell .btn-small {
            white-space: nowrap;
        }

        #valera-cashbook-create-modal {
            width: min(720px, calc(100vw - 32px));
        }

        .valera-cashbook-unlabeled-row {
            background: #fff7ed;
        }

        .valera-cashbook-unlabeled-row td {
            border-bottom-color: #fed7aa;
        }

        @media (max-width: 980px) {
            .valera-cashbook-filters > div,
            .valera-cashbook-filters .search-field,
            .valera-cashbook-filters .filter-actions {
                flex: 1 1 100%;
                min-width: 0;
            }

            .valera-cashbook-summary {
                grid-template-columns: 1fr;
            }

            .valera-cashbook-create-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="panel">
        <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h2 style="margin:0;">Фильтры</h2>
        </div>
        <form method="GET" class="valera-cashbook-filters">
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
                <label for="operation_type">Тип</label>
                <select id="operation_type" name="operation_type">
                    <option value="">Все типы</option>
                    @foreach ($operationTypes as $operationType)
                        <option value="{{ $operationType }}" @selected(($filters['operation_type'] ?? '') === $operationType)>{{ $operationType }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="category">Категория</label>
                <select id="category" name="category">
                    <option value="">Все категории</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="label">Метка</label>
                <select id="label" name="label">
                    <option value="">Все метки</option>
                    @foreach ($selectedLabels as $label)
                        <option value="{{ $label }}" @selected(($filters['label'] ?? '') === $label)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="person">ФИО</label>
                <select id="person" name="person">
                    <option value="">Все</option>
                    @foreach ($people as $person)
                        <option value="{{ $person }}" @selected(($filters['person'] ?? '') === $person)>{{ $person }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="project">Проект</label>
                <select id="project" name="project">
                    <option value="">Все проекты</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project }}" @selected(($filters['project'] ?? '') === $project)>{{ $project }}</option>
                    @endforeach
                </select>
            </div>
            <div class="search-field">
                <label for="search">Поиск</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Назначение, проект, категория или ФИО">
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
                <a class="btn btn-secondary" href="{{ route('admin.valera-cashbook.index') }}">Сбросить</a>
            </div>
        </form>
    </div>

    <div class="valera-cashbook-summary">
        <div class="panel">
            <div class="help">Приход</div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->income_uah) }} грн</div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->income_usd) }} $</div>
        </div>
        <div class="panel">
            <div class="help"></div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->expense_uah) }} грн</div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->expense_usd) }} $</div>
        </div>
        <div class="panel">
            <div class="help">Сумма по фильтру</div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->net_uah) }} грн</div>
            <div class="valera-cashbook-summary-value">{{ $money($summary->net_usd) }} $</div>
        </div>
        <div class="panel">
            <div class="help">Остаток последней операции</div>
            <div class="valera-cashbook-summary-value">{{ $latestBalance ? $money($latestBalance->balance_uah) : $money(0) }} грн</div>
            <div class="valera-cashbook-summary-value">{{ $latestBalance ? $money($latestBalance->balance_usd) : $money(0) }} $</div>
        </div>
    </div>

    @if ($pendingTransfers->isNotEmpty())
        <div class="panel" style="margin-top:18px;">
            <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
                <div>
                    <h2 style="margin:0;">Ожидают подтверждения</h2>
                    <div class="help" style="margin-top:6px;">Переводы из Кассы и работ в Кассу Валера.</div>
                </div>
            </div>
            <table>
                <thead>
                <tr>
                    <th>Дата</th>
                    <th>Сумма</th>
                    <th>Комментарий</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($pendingTransfers as $transfer)
                    @php($cashTransaction = $transfer->cashTransaction)
                    <tr>
                        <td>{{ $cashTransaction->operation_date?->format('d.m.Y') }}</td>
                        <td>
                            @if ((float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah > 0)
                                <div>{{ $money((float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah) }} грн</div>
                            @endif
                            @if ((float) $cashTransaction->expense_cash_usd > 0)
                                <div>{{ $money($cashTransaction->expense_cash_usd) }} $</div>
                            @endif
                        </td>
                        <td>{{ $cashTransaction->comment }}</td>
                        <td class="actions">
                            <a class="btn btn-small btn-secondary" href="{{ route('admin.cashbook.show', $cashTransaction) }}">Открыть</a>
                            <form method="POST" action="{{ route('admin.valera-cashbook.transfers.confirm', $transfer) }}" class="inline-form">
                                @csrf
                                <button type="submit" class="btn-small">Подтвердить</button>
                            </form>
                            <form method="POST" action="{{ route('admin.valera-cashbook.transfers.cancel', $transfer) }}" class="inline-form" onsubmit="return confirm('Отменить эту инкассацию? Приход и расход будут обнулены.');">
                                @csrf
                                <button type="submit" class="btn-small btn-danger">Отмена</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="panel" style="margin-top:18px;">
        <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Операции</h2>
                <div class="help" style="margin-top:6px;">
                    Показано: {{ $transactions->count() }}{{ method_exists($transactions, 'total') ? ' из '.$transactions->total() : '' }}
                </div>
            </div>
            <div class="valera-cashbook-create-actions">
                <button type="button" class="btn btn-secondary" data-open-valera-cashbook-create="expense">Расход</button>
                <button type="button" class="btn btn-secondary" data-open-valera-cashbook-create="exchange">Обмен</button>
            </div>
        </div>
        <div class="valera-cashbook-table-wrap">
        <table class="valera-cashbook-table">
            <thead>
            <tr>
                <th><a class="valera-cashbook-sort-link" href="{{ $sortUrl('operation_date') }}">Дата{{ $sortMark('operation_date') }}</a></th>
                <th><a class="valera-cashbook-sort-link" href="{{ $sortUrl('income') }}">Приход{{ $sortMark('income') }}</a></th>
                <th><a class="valera-cashbook-sort-link" href="{{ $sortUrl('expense') }}">Расход{{ $sortMark('expense') }}</a></th>
                <th><a class="valera-cashbook-sort-link" href="{{ $sortUrl('label') }}">Метка{{ $sortMark('label') }}</a></th>
                <th><a class="valera-cashbook-sort-link" href="{{ $sortUrl('details') }}">VIN / комментарий{{ $sortMark('details') }}</a></th>
                <th class="valera-cashbook-actions-cell"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $transaction)
                @php($transactionLabelType = $labelTypes[$transaction->label] ?? null)
                @php($transactionExchangeRate = $transactionLabelType === 'exchange' ? $valeraExchangeRate($transaction) : null)
                <tr id="valera-cashbook-row-{{ $transaction->id }}" @class(['valera-cashbook-unlabeled-row' => blank($transaction->label)])>
                    <td>{{ $transaction->operation_date?->format('d.m.Y') }}</td>
                    <td class="valera-cashbook-amount-positive">
                        @if ((float) $transaction->income_uah > 0)<div>{{ $money($transaction->income_uah) }} грн</div>@endif
                        @if ((float) $transaction->income_usd > 0)<div>{{ $money($transaction->income_usd) }} $</div>@endif
                    </td>
                    <td class="valera-cashbook-amount-negative">
                        @if ((float) $transaction->expense_uah > 0)<div>{{ $money($transaction->expense_uah) }} грн</div>@endif
                        @if ((float) $transaction->expense_usd > 0)<div>{{ $money($transaction->expense_usd) }} $</div>@endif
                    </td>
                    <td>
                        <span @class([
                            'tag',
                            'tag-danger' => $transaction->isCancelled() || $transaction->isExpense(),
                            'tag-exchange' => ! $transaction->isCancelled() && $transactionLabelType === 'exchange',
                            'tag-exchange-with-rate' => $transactionExchangeRate !== null,
                        ])>
                            <span>{{ $transaction->label ?: 'без метки' }}</span>
                            @if ($transactionExchangeRate !== null)
                                <span class="tag-exchange-rate">Курс: {{ $money($transactionExchangeRate) }}</span>
                            @endif
                        </span>
                        @if ($transaction->isCancelled())
                            <div class="help" style="font-size:12px;margin-top:4px;">Отмена:
                                @if ((float) $transaction->cancelled_amount_uah > 0) {{ $money($transaction->cancelled_amount_uah) }} грн @endif
                                @if ((float) $transaction->cancelled_amount_usd > 0) {{ $money($transaction->cancelled_amount_usd) }} $ @endif
                            </div>
                        @endif
                        @if ($transaction->isDeletedFromCashbook())
                            <div class="help" style="font-size:12px;margin-top:4px;">Удалена из Кассы Валера</div>
                        @endif
                    </td>
                    <td>{{ $transaction->detailsText() ?: '—' }}</td>
                    <td class="actions valera-cashbook-actions-cell">
                        @unless ($transaction->confirmedTransfer?->status === 'confirmed' || $transaction->isDeletedFromCashbook() || ! $transaction->canBeDeleted())
                        <form method="POST" action="{{ route('admin.valera-cashbook.destroy', $transaction) }}" class="inline-form" onsubmit="return confirm('Удалить эту операцию? Действие нельзя отменить.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-small btn-danger">Удалить</button>
                        </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Операций пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @if (method_exists($transactions, 'links'))
            <div style="margin-top:14px;">{{ $transactions->links() }}</div>
        @endif
    </div>

    <dialog id="valera-cashbook-create-modal" class="modal">
        <div class="modal-header">
            <h2 id="valera-cashbook-create-modal-title">Новая операция</h2>
            <button type="button" class="btn btn-secondary btn-small" data-close-valera-cashbook-create>Закрыть</button>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" style="margin-bottom:14px;">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.valera-cashbook.store') }}" class="valera-cashbook-create-form">
            @csrf
            <input id="valera_cashbook_transaction_type" type="hidden" name="transaction_type" value="{{ old('transaction_type', 'expense') }}">

            <div class="valera-cashbook-create-form-grid">
                <div>
                    <label for="valera_cashbook_operation_date">Дата</label>
                    <input id="valera_cashbook_operation_date" type="date" name="operation_date" value="{{ old('operation_date', now()->toDateString()) }}" required>
                </div>
                <div>
                    <label for="valera_cashbook_label">Метка</label>
                    <select id="valera_cashbook_label" name="label" required>
                        <option value="">Выберите метку</option>
                        @foreach ($labels as $label)
                            <option value="{{ $label }}" data-valera-label-type="{{ $labelTypes[$label] ?? 'income' }}" @selected(old('label') === $label)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div data-valera-donor-expense-field hidden>
                    <label for="valera_cashbook_vehicle_vin">VIN авто</label>
                    <select
                        id="valera_cashbook_vehicle_vin"
                        name="vehicle_vin"
                        data-valera-vin-select
                        data-add-donor-url="{{ route('admin.donor-cars.create') }}"
                        disabled
                    >
                        <option value="">Выберите VIN донора</option>
                        @foreach (($donorCars ?? collect()) as $donorCar)
                            @php($donorModel = $donorCar->display_model)
                            @php($filledDonorExpenseTypes = collect([
                                'purchase_with_fees' => $donorCar->estimated_cost_usd,
                                'usa_delivery' => $donorCar->usa_delivery_price_usd,
                                'klaipeda_ukraine_delivery' => $donorCar->klaipeda_ukraine_delivery_price_usd,
                                'customs_clearance' => $donorCar->customs_clearance_price_usd,
                            ])->filter(fn ($value) => $value !== null)->keys()->values())
                            <option value="{{ $donorCar->vin }}" data-donor-filled-expense-types='@json($filledDonorExpenseTypes)' @selected(old('vehicle_vin') === $donorCar->vin)>
                                {{ collect([$donorCar->display_vin, $donorModel, $donorCar->purchase_date?->format('d.m.Y')])->filter()->implode(' - ') }}
                            </option>
                        @endforeach
                        @if (old('vehicle_vin') && ! ($donorCars ?? collect())->contains('vin', old('vehicle_vin')))
                            <option value="{{ old('vehicle_vin') }}" selected>{{ old('vehicle_vin') }}</option>
                        @endif
                        <option value="__add_donor__">Добавить Донора</option>
                    </select>
                </div>
                <div data-valera-donor-expense-field hidden>
                    <label for="valera_cashbook_donor_expense_type">Статья расхода донора</label>
                    <select id="valera_cashbook_donor_expense_type" name="donor_expense_type" data-valera-donor-expense-select disabled>
                        <option value="">Выберите статью</option>
                        <option value="purchase_with_fees" @selected(old('donor_expense_type') === 'purchase_with_fees')>Цена покупки(со сборами)</option>
                        <option value="usa_delivery" @selected(old('donor_expense_type') === 'usa_delivery')>Цена доставка США</option>
                        <option value="klaipeda_ukraine_delivery" @selected(old('donor_expense_type') === 'klaipeda_ukraine_delivery')>Цена Доставка Клайпеда-Украина</option>
                        <option value="customs_clearance" @selected(old('donor_expense_type') === 'customs_clearance')>Растаможка</option>
                    </select>
                </div>

                <div class="valera-cashbook-create-section" data-valera-create-section="income">
                    <label for="valera_cashbook_income_uah">Приход грн</label>
                    <input id="valera_cashbook_income_uah" type="number" step="0.01" min="0" name="income_uah" value="{{ old('income_uah') }}">
                </div>
                <div class="valera-cashbook-create-section" data-valera-create-section="income">
                    <label for="valera_cashbook_income_usd">Приход $</label>
                    <input id="valera_cashbook_income_usd" type="number" step="0.01" min="0" name="income_usd" value="{{ old('income_usd') }}">
                </div>
                <div class="valera-cashbook-create-section" data-valera-create-section="expense">
                    <label for="valera_cashbook_expense_uah">ГРН</label>
                    <input id="valera_cashbook_expense_uah" type="number" step="0.01" min="0" name="expense_uah" value="{{ old('expense_uah') }}">
                </div>
                <div class="valera-cashbook-create-section" data-valera-create-section="expense">
                    <label for="valera_cashbook_expense_usd"> $</label>
                    <input id="valera_cashbook_expense_usd" type="number" step="0.01" min="0" name="expense_usd" value="{{ old('expense_usd') }}">
                </div>

                <div class="full">
                    <label for="valera_cashbook_purpose">Назначение / комментарий</label>
                    <textarea id="valera_cashbook_purpose" name="purpose" rows="3" required>{{ old('purpose') }}</textarea>
                </div>
            </div>

            <div class="actions" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" data-close-valera-cashbook-create>Отмена</button>
                <button type="submit">Сохранить</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const modal = document.getElementById('valera-cashbook-create-modal');
            const title = document.getElementById('valera-cashbook-create-modal-title');
            const typeInput = document.getElementById('valera_cashbook_transaction_type');
            const labelSelect = document.getElementById('valera_cashbook_label');
            const openButtons = document.querySelectorAll('[data-open-valera-cashbook-create]');
            const closeButtons = document.querySelectorAll('[data-close-valera-cashbook-create]');
            const sections = document.querySelectorAll('[data-valera-create-section]');
            const donorExpenseFields = document.querySelectorAll('[data-valera-donor-expense-field]');
            const vinSelect = document.querySelector('[data-valera-vin-select]');
            const donorExpenseSelect = document.querySelector('[data-valera-donor-expense-select]');
            const titles = {
                expense: 'Новый расход',
                exchange: 'Новый обмен',
            };

            if (! modal || ! typeInput || ! labelSelect) {
                return;
            }

            const filledDonorExpenseTypes = () => {
                if (!vinSelect?.value || !vinSelect.selectedOptions[0]?.dataset.donorFilledExpenseTypes) {
                    return [];
                }

                try {
                    return JSON.parse(vinSelect.selectedOptions[0].dataset.donorFilledExpenseTypes);
                } catch {
                    return [];
                }
            };

            const applyDonorExpenseOptions = () => {
                if (!donorExpenseSelect) {
                    return;
                }

                const filledTypes = filledDonorExpenseTypes();

                Array.from(donorExpenseSelect.options).forEach((option) => {
                    option.hidden = Boolean(option.value) && filledTypes.includes(option.value);
                });

                if (donorExpenseSelect.selectedOptions[0]?.hidden) {
                    donorExpenseSelect.value = '';
                }
            };

            const applyDonorExpenseVisibility = () => {
                const isDonorExpense = typeInput.value === 'expense' && labelSelect.value === 'Донор';

                donorExpenseFields.forEach((field) => {
                    field.hidden = !isDonorExpense;
                });

                [vinSelect, donorExpenseSelect].forEach((select) => {
                    if (!select) {
                        return;
                    }

                    select.required = isDonorExpense;
                    select.disabled = !isDonorExpense;

                    if (!isDonorExpense) {
                        select.value = '';
                    }
                });

                applyDonorExpenseOptions();
            };

            const setMode = (mode) => {
                const normalizedMode = ['expense', 'exchange'].includes(mode) ? mode : 'expense';
                typeInput.value = normalizedMode;
                title.textContent = titles[normalizedMode] ?? titles.expense;

                sections.forEach((section) => {
                    const sectionType = section.dataset.valeraCreateSection;
                    section.hidden = normalizedMode !== 'exchange' && sectionType !== normalizedMode;
                });

                Array.from(labelSelect.options).forEach((option) => {
                    if (! option.value) {
                        option.hidden = false;
                        return;
                    }

                    option.hidden = option.dataset.valeraLabelType !== normalizedMode;
                });

                if (labelSelect.selectedOptions[0]?.hidden) {
                    labelSelect.value = '';
                }

                applyDonorExpenseVisibility();
            };

            labelSelect.addEventListener('change', applyDonorExpenseVisibility);

            vinSelect?.addEventListener('change', () => {
                if (vinSelect.value === '__add_donor__') {
                    const addDonorUrl = vinSelect.dataset.addDonorUrl;

                    if (addDonorUrl) {
                        window.location.href = addDonorUrl;
                    }

                    return;
                }

                applyDonorExpenseOptions();
            });

            openButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setMode(button.dataset.openValeraCashbookCreate);
                    modal.showModal();
                });
            });

            closeButtons.forEach((button) => button.addEventListener('click', () => modal.close()));

            modal.addEventListener('cancel', (event) => {
                event.preventDefault();
            });

            setMode(typeInput.value);

            @if ($errors->any())
                modal.showModal();
            @endif
        })();
    </script>

@endsection
