@extends('layouts.admin', ['heading' => 'Контрагенты'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.counterparties.create') }}">Добавить контрагента</a>
        </div>
        <table>
            <thead>
            <tr>
                <th>Название</th>
                <th>Тип</th>
                <th>Телефон</th>
                <th>Авто</th>
                <th>ГосНомер</th>
                <th>Статус</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($counterparties as $counterparty)
                <tr>
                    <td><a href="{{ route('admin.counterparties.show', $counterparty) }}">{{ $counterparty->name }}</a></td>
                    <td>{{ $counterparty->type_label }}</td>
                    <td>{{ $counterparty->phone }}</td>
                    <td>{{ trim(($counterparty->car_model ?? '').' '.($counterparty->car_year ?? '')) ?: '—' }}</td>
                    <td>{{ $counterparty->license_plate ?: '—' }}</td>
                    <td>{{ $counterparty->is_active ? 'Активен' : 'Отключен' }}</td>
                    <td class="actions">
                        <a class="btn btn-secondary" href="{{ route('admin.counterparties.edit', $counterparty) }}">Изменить</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Контрагенты пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:14px;">{{ $counterparties->links() }}</div>
    </div>
@endsection
