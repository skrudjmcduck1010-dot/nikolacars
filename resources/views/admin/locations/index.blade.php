@extends('layouts.admin', ['heading' => 'Ячейки'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;"><a class="btn" href="{{ route('admin.locations.create') }}">Добавить ячейку</a></div>
        <table>
            <thead><tr><th>Полный код</th><th>Склад</th><th>Этаж</th><th>Зона</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            @forelse($locations as $location)
                <tr>
                    <td><a href="{{ route('admin.locations.show', $location) }}">{{ $location->full_code }}</a></td>
                    <td>{{ $location->warehouse->name }}</td>
                    <td>{{ $location->floorLabel() }}</td>
                    <td>{{ $location->zone ?? '—' }}</td>
                    <td>{{ $location->is_active ? 'Активна' : 'Отключена' }}</td>
                    <td class="actions">
                        <a class="btn btn-secondary" href="{{ route('admin.locations.edit', $location) }}">Изменить</a>
                        <form method="POST" action="{{ route('admin.locations.destroy', $location) }}" class="inline-form" onsubmit="return confirm('Удалить ячейку {{ $location->full_code }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Ячейки пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
