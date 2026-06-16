@extends('layouts.admin', ['heading' => ''])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.reservations.create') }}">Добавить резерв</a>
            <a class="btn btn-secondary" href="{{ route('admin.actions.create', 'reserve') }}">Быстрый резерв</a>
        </div>
        <table>
            <thead><tr><th>Товар</th><th>Остаток</th><th>Кол-во</th><th>Статус</th><th>Заказ</th><th></th></tr></thead>
            <tbody>
            @forelse($reservations as $reservation)
                <tr>
                    <td><a href="{{ route('admin.reservations.show', $reservation) }}">{{ $reservation->product->name }}</a></td>
                    <td>{{ $reservation->stockItem->location->full_code ?? '—' }}</td>
                    <td>{{ $reservation->quantity }}</td>
                    <td>{{ $reservation->status }}</td>
                    <td>{{ $reservation->customer_order_id ?: '—' }}</td>
                    <td><a class="btn btn-secondary" href="{{ route('admin.reservations.edit', $reservation) }}">Изменить</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">   .</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
