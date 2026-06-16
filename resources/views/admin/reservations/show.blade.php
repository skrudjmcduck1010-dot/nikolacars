@extends('layouts.admin', ['heading' => ' #'.$reservation->id])

@section('content')
    <div class="panel">
        <p><strong>Товар:</strong> {{ $reservation->product->name }}</p>
        <p><strong>Ячейка:</strong> {{ $reservation->stockItem->location->full_code ?? '—' }}</p>
        <p><strong>Количество:</strong> {{ $reservation->quantity }}</p>
        <p><strong>Статус:</strong> {{ ['active' => 'Активен', 'released' => 'Снят', 'fulfilled' => 'Исполнен', 'cancelled' => 'Отменен'][$reservation->status] ?? $reservation->status }}</p>
        <p><strong>Заказ:</strong> {{ $reservation->customer_order_id ?: '—' }}</p>
        <p><strong>Комментарий:</strong> {{ $reservation->comment ?: '—' }}</p>
    </div>
@endsection
