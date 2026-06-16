@extends('layouts.admin', ['heading' => 'Закупка #'.$purchase->id, 'subheading' => 'Связанный расход и размещение позиций на складе'])

@php($money = fn ($value) => number_format((float) $value, 2, ',', ' '))

@section('content')
    <div class="grid grid-2">
        <div class="panel">
            <h2 style="margin-top:0;">Документ</h2>
            <p><strong>Дата:</strong> {{ $purchase->purchase_date?->format('d.m.Y') }}</p>
            <p><strong>Поставщик:</strong> {{ $purchase->counterparty->name ?? '—' }}</p>
            <p><strong>Склад:</strong> {{ $purchase->warehouse->name ?? '—' }}</p>
            <p><strong>Номер:</strong> {{ $purchase->document_number ?: '—' }}</p>
            <p><strong>Сумма:</strong> {{ $money($purchase->total_amount) }} {{ $purchase->currency }}</p>
            <p><strong>Статус:</strong> <span class="tag">{{ $purchase->status }}</span></p>
            <div class="actions" style="margin-top:16px;">
                <form method="POST" action="{{ route('admin.purchases.destroy', $purchase) }}" class="inline-form" onsubmit='return confirm(@json("Удалить закупку #{$purchase->id}? Будет удалена связанная операция кассы, а товар будет снят с остатков. Действие нельзя отменить."));'>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">Удалить закупку</button>
                </form>
            </div>
        </div>
        <div class="panel">
            <h2 style="margin-top:0;">База операций</h2>
            @if ($purchase->cashTransaction)
                <p><strong>Метка:</strong> {{ $purchase->cashTransaction->label }}</p>
                <p><strong>Комментарий:</strong> {{ $purchase->cashTransaction->comment }}</p>
                <a class="btn btn-secondary" href="{{ route('admin.cashbook.show', $purchase->cashTransaction) }}">Открыть операцию</a>
            @else
                <div class="empty">Связанная операция не найдена.</div>
            @endif
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">Позиции</h2>
        <table>
            <thead>
            <tr>
                <th>Товар</th>
                <th>Склад</th>
                <th>Ячейка</th>
                <th>Приход</th>
                <th>Закуп</th>
                <th>Продажа</th>
                <th>Остаток</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($purchase->items as $item)
                <tr>
                    <td><a href="{{ route('admin.products.show', $item->product) }}">{{ $item->product->name }}</a></td>
                    <td>{{ $item->warehouse->name ?? '—' }}</td>
                    <td>{{ $item->location->full_code ?? '—' }}</td>
                    <td>{{ $item->quantity }} шт</td>
                    <td>{{ $money($item->purchase_price) }} {{ $item->currency }}</td>
                    <td>{{ $money($item->selling_price) }} {{ $item->currency }}</td>
                    <td>
                        @if ($item->stockItem)
                            <a href="{{ route('admin.stock-items.show', $item->stockItem) }}">
                                доступно {{ $item->stockItem->available_quantity }} / всего {{ $item->stockItem->quantity }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
