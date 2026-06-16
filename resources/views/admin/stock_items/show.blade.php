@extends('layouts.admin', ['heading' => $stockItem->product->name.' @ '.$stockItem->location->full_code])

@section('content')
    <div class="grid grid-2">
        <div class="panel">
            <div class="help">Физически {{ $stockItem->quantity }} · В резерве {{ $stockItem->reserved_quantity }} · Доступно {{ $stockItem->available_quantity }}</div>
            <p>Склад: {{ $stockItem->warehouse->name }}</p>
            <p>Ячейка: {{ $stockItem->location->full_code }}</p>
            <p>Проверка: {{ $stockItem->testing_status === 'tested' ? 'Проверен' : 'Не проверен' }}</p>
        </div>
        <div class="panel">
            <h2 style="margin-top:0;"></h2>
            <table>
                <thead><tr><th>Статус</th><th>Кол-во</th><th>Заказ</th></tr></thead>
                <tbody>
                @forelse($stockItem->reservations as $reservation)
                    <tr><td>{{ $reservation->status }}</td><td>{{ $reservation->quantity }}</td><td>{{ $reservation->customer_order_id ?: '—' }}</td></tr>
                @empty
                    <tr><td colspan="3" class="empty">Для этого остатка резервов нет.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
