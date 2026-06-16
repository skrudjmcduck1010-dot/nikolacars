@extends('layouts.admin', [
    'heading' => 'Операция кассы',
    'subheading' => $transaction->operation_date?->format('d.m.Y'),
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
@endphp

@section('content')
    <div class="panel">
        <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
            <h2 style="margin:0;">Детали</h2>
            <div class="actions">
                @if (! $transaction->isStoWorkOrderPayment() && ! $transaction->isCancelled() && ! $transaction->hasConfirmedValeraCashbookTransfer() && $transaction->canBeEdited())
                    <a class="btn" href="{{ route('admin.cashbook.edit', $transaction) }}">Править</a>
                @endif
                <a class="btn btn-secondary" href="{{ route('admin.cashbook.index') }}">Назад</a>
            </div>
        </div>

        <table>
            <tbody>
            <tr><th>Дата</th><td>{{ $transaction->operation_date?->format('d.m.Y') }}</td></tr>
            <tr><th>Метка</th><td>{{ $transaction->label ?: 'без метки' }}</td></tr>
            <tr><th>Ответственный</th><td>{{ $transaction->employee ?: '—' }}</td></tr>
            <tr><th>VIN</th><td>{{ $transaction->vehicle_vin ?: '—' }}</td></tr>
            <tr>
                <th>Приход</th>
                <td>
                    @if ($transaction->totalIncomeUah() > 0)<div>{{ $money($transaction->totalIncomeUah()) }} грн</div>@endif
                    @if ((float) $transaction->income_cash_usd > 0)<div>{{ $money($transaction->income_cash_usd) }} $</div>@endif
                    @if ($transaction->totalIncomeUah() <= 0 && (float) $transaction->income_cash_usd <= 0)—@endif
                </td>
            </tr>
            <tr>
                <th>Расход</th>
                <td>
                    @if ($transaction->totalExpenseUah() > 0)<div>{{ $money($transaction->totalExpenseUah()) }} грн</div>@endif
                    @if ((float) $transaction->expense_cash_usd > 0)<div>{{ $money($transaction->expense_cash_usd) }} $</div>@endif
                    @if ($transaction->totalExpenseUah() <= 0 && (float) $transaction->expense_cash_usd <= 0)—@endif
                </td>
            </tr>
            <tr><th>Комментарий</th><td>{{ $transaction->detailsText() ?: '—' }}</td></tr>
            <tr><th>Месяц</th><td>{{ $transaction->source_sheet ?: '—' }}</td></tr>
            <tr><th>Источник</th><td>{{ $transaction->source ?: 'manual' }}</td></tr>
            </tbody>
        </table>
    </div>

    @if ($transaction->purchase)
        <div class="panel" style="margin-top:18px;">
            <h2 style="margin-top:0;">Закупка</h2>
            <table>
                <thead>
                <tr>
                    <th>Товар</th>
                    <th>Кол-во</th>
                    <th>Цена</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($transaction->purchase->items as $item)
                    <tr>
                        <td>{{ $item->product?->name ?: $item->product?->sku }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $money($item->unit_price) }} {{ $item->currency }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
