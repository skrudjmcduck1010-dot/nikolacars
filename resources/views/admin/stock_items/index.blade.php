@extends('layouts.admin', ['heading' => 'Остатки'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.stock-items.create') }}">Добавить остаток</a>
            <a class="btn btn-secondary" href="{{ route('admin.actions.create', 'intake') }}">Приемка</a>
        </div>
        <table>
            <thead><tr><th>Товар</th><th>Склад</th><th>Ячейка</th><th>Кол-во</th><th>В резерве</th><th>Доступно</th><th></th></tr></thead>
            <tbody>
            @forelse($stockItems as $stockItem)
                <tr>
                    <td><a href="{{ route('admin.stock-items.show', $stockItem) }}">{{ $stockItem->product->name }}</a></td>
                    <td>{{ $stockItem->warehouse->name }}</td>
                    <td>{{ $stockItem->location->full_code }}</td>
                    <td>{{ $stockItem->quantity }}</td>
                    <td>{{ $stockItem->reserved_quantity }}</td>
                    <td>{{ $stockItem->available_quantity }}</td>
                    <td><a class="btn btn-secondary" href="{{ route('admin.stock-items.edit', $stockItem) }}">Изменить</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Остатки пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
