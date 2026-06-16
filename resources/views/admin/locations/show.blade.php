@extends('layouts.admin', ['heading' => $location->full_code])

@section('content')
    <div class="panel">
        <div class="help">Склад: {{ $location->warehouse->name }} · Этаж: {{ $location->floorLabel() }}</div>
        <h2>Остатки</h2>
        <table>
            <thead><tr><th>Товар</th><th>Кол-во</th><th>В резерве</th><th>Доступно</th></tr></thead>
            <tbody>
            @forelse($location->stockItems as $stockItem)
                <tr><td>{{ $stockItem->product->name }}</td><td>{{ $stockItem->quantity }}</td><td>{{ $stockItem->reserved_quantity }}</td><td>{{ $stockItem->available_quantity }}</td></tr>
            @empty
                <tr><td colspan="4" class="empty">В этой ячейке нет остатков.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
