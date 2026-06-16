@extends('layouts.admin', ['heading' => 'История движений', 'subheading' => 'Журнал изменений остатков без удаления записей'])

@section('content')
    <div class="panel">
        <table>
            <thead><tr><th>Дата</th><th>Тип</th><th>Товар</th><th>Откуда</th><th>Куда</th><th>Кол-во</th><th>Причина</th></tr></thead>
            <tbody>
            @forelse($movements as $movement)
                <tr>
                    <td><a href="{{ route('admin.movements.show', $movement) }}">{{ optional($movement->created_at)->format('Y-m-d H:i') }}</a></td>
                    <td>{{ [
                        'intake' => 'приемка',
                        'move' => 'перемещение',
                        'reserve' => 'резерв',
                        'unreserve' => 'снятие резерва',
                        'sale' => 'продажа',
                        'writeoff' => 'списание',
                        'adjustment' => 'корректировка',
                    ][$movement->type] ?? $movement->type }}</td>
                    <td>{{ $movement->product->name }}</td>
                    <td>{{ $movement->fromLocation->full_code ?? '—' }}</td>
                    <td>{{ $movement->toLocation->full_code ?? '—' }}</td>
                    <td>{{ $movement->quantity }}</td>
                    <td>{{ $movement->reason ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Движений пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
