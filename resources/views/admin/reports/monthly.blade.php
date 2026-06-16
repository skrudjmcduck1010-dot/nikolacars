@extends('layouts.admin', [
    'heading' => 'По месяцам',
    'subheading' => 'Помесячная аналитика по кассе, прибыли, расходам и зарплатам СТО.',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $selectedLabels = $filters['label'] ?? [];
    $labelNames = [
        ' ' => ' ()',
        '  ' => ' ()',
    ];
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
            flex: 1 1 140px;
            min-width: 140px;
        }

        .cashbook-filters .search-field {
            flex: 1.6 1 220px;
        }

        .cashbook-filters .filter-actions {
            flex: 0 1 auto;
            min-width: max-content;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .cashbook-filters > div:nth-of-type(1),
        .cashbook-filters > div:nth-of-type(2),
        .cashbook-filters > div:nth-of-type(4) {
            display: none;
        }

        #source_sheet option[value=""] {
            display: none;
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
            content: '▾';
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
            width: 100%;
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
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 6px;
            color: #111827;
            cursor: pointer;
            font-weight: 400;
            line-height: 1.1;
        }

        .checkbox-dropdown-option:hover {
            background: #f3f4f6;
        }

        .checkbox-dropdown-option input {
            width: auto;
            margin: 0;
        }

        @media (max-width: 980px) {
            .cashbook-filters > div,
            .cashbook-filters .search-field,
            .cashbook-filters .filter-actions {
                flex: 1 1 100%;
                min-width: 0;
            }
        }
    </style>

    <div class="panel" style="margin-bottom:18px;">
        <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h2 style="margin:0;">Фильтры</h2>
            <a class="btn btn-secondary" href="{{ route('admin.cashbook.index') }}">Касса и работы</a>
        </div>
        <form method="GET" class="cashbook-filters">
            <div>
                <label>Метка</label>
                <details class="checkbox-dropdown">
                    <summary>{{ count($selectedLabels) > 0 ? 'Выбрано: '.count($selectedLabels) : 'Все метки' }}</summary>
                    <div class="checkbox-dropdown-panel">
                        @foreach ($labels as $label)
                            <label class="checkbox-dropdown-option">
                                <input type="checkbox" name="label[]" value="{{ $label }}" @checked(in_array($label, $selectedLabels, true))>
                                <span>{{ $labelNames[$label] ?? $label }}</span>
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
                        <option
                            value="{{ $sourceSheet }}"
                            @selected(($filters['source_sheet'] ?? '') === $sourceSheet)
                        >{{ $sourceSheet }}</option>
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
            <div class="filter-actions">
                <button type="submit">Показать</button>
                <a class="btn btn-secondary" href="{{ route('admin.reports.monthly') }}">Сбросить</a>
            </div>
        </form>
    </div>

    @include('admin.reports._monthly_blocks')

    <div class="panel" style="margin-top:18px;">
        <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Операции</h2>
                <div class="help" style="margin-top:6px;">
                    Показано: {{ $transactions->count() }}{{ method_exists($transactions, 'total') ? ' из '.$transactions->total() : '' }}
                </div>
            </div>
            <a class="btn" href="{{ route('admin.cashbook.create') }}">Добавить</a>
        </div>
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Приход</th>
                <th></th>
                <th>Метка</th>
                <th>Фамилия</th>
                <th>Месяц</th>
                <th>VIN / комментарий</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $transaction)
                @php($isExpense = $transaction->totalExpenseUah() > 0 || (float) $transaction->expense_cash_usd > 0)
                <tr>
                    <td>{{ $transaction->operation_date?->format('d.m.Y') }}</td>
                    <td>
                        @if ($transaction->totalIncomeUah() > 0)<div>{{ $money($transaction->totalIncomeUah()) }} грн</div>@endif
                        @if ((float) $transaction->income_cash_usd > 0)<div>{{ $money($transaction->income_cash_usd) }} $</div>@endif
                    </td>
                    <td>
                        @if ($transaction->totalExpenseUah() > 0)<div>{{ $money($transaction->totalExpenseUah()) }} грн</div>@endif
                        @if ((float) $transaction->expense_cash_usd > 0)<div>{{ $money($transaction->expense_cash_usd) }} $</div>@endif
                    </td>
                    <td><span class="tag {{ $isExpense ? 'tag-danger' : '' }}">{{ $transaction->label ?: 'без метки' }}</span></td>
                    <td>{{ $transaction->employee ?: '—' }}</td>
                    <td>{{ $transaction->source_sheet ?: '—' }}</td>
                    <td>
                        @if ($transaction->vehicle_vin)<div class="help">{{ $transaction->vehicle_vin }}</div>@endif
                        {{ $transaction->comment }}
                    </td>
                    <td class="actions">
                        <a class="btn btn-small btn-secondary" href="{{ route('admin.cashbook.show', $transaction) }}">Открыть</a>
                        <a class="btn btn-small" href="{{ route('admin.cashbook.edit', $transaction) }}">Править</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Операций пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if (method_exists($transactions, 'links'))
            <div style="margin-top:14px;">{{ $transactions->links() }}</div>
        @endif
    </div>
@endsection
