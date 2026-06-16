@extends('layouts.admin', ['heading' => 'Лог', 'subheading' => 'Журнал действий в админке'])

@section('content')
    <div class="panel">
        @if ($logs->isEmpty())
            <div class="empty">Записей пока нет.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Пользователь</th>
                        <th>Действие</th>
                        <th>Маршрут</th>
                        <th>Статус</th>
                        <th>Данные</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td>{{ $log->created_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i:s') }}</td>
                            <td>
                                {{ $log->user?->name ?? 'Система' }}
                                @if ($log->user?->email)
                                    <div class="help">{{ $log->user->email }}</div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $log->action }}</strong>
                                <div class="help">{{ $log->method }} · {{ $log->ip_address }}</div>
                            </td>
                            <td>
                                {{ $log->route_name ?? '—' }}
                                <div class="help">{{ $log->url }}</div>
                            </td>
                            <td>{{ $log->status_code }}</td>
                            <td>
                                @if (! empty($log->payload))
                                    <details>
                                        <summary>Показать</summary>
                                        <pre style="white-space:pre-wrap;max-width:420px;">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span class="help">Нет данных</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="margin-top:16px;">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
@endsection
