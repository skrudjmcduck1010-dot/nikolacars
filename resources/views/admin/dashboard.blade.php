@extends('layouts.admin', ['heading' => 'Панель управления', 'subheading' => 'Сводка по складу для Phase 1'])

@section('content')
    <div class="panel" style="margin-bottom:18px;color:#0f766e;">
        <div style="font-size:22px;font-weight:700;">Вылито</div>
        <div class="help" style="margin-top:6px;color:#0f766e;">Дата: 28.05.2026 19:38:23</div>
    </div>

    <div class="grid grid-4">
            <div class="panel"><div class="help">Склады</div><div class="stat">{{ $warehouseCount }}</div></div>
        <div class="panel"><div class="help">Товары</div><div class="stat">{{ $productCount }}</div></div>
        <div class="panel"><div class="help">Физический остаток</div><div class="stat">{{ $stockQuantity }}</div></div>
        <div class="panel"><div class="help">В резерве</div><div class="stat">{{ $reservedQuantity }}</div></div>
    </div>

    <div class="grid grid-2" style="margin-top:18px;">
        <div class="panel">
            <h2 style="margin-top:0;">Быстрые операции</h2>
            @if (auth()->user()?->hasPermission('stock_actions.manage'))
                <div class="actions">
                    @foreach (['intake', 'move', 'reserve', 'sale', 'writeoff', 'adjustment'] as $action)
                        <a class="btn" href="{{ route('admin.actions.create', $action) }}">
                            {{ [
                                'intake' => 'Приемка',
                                'move' => 'Перемещение',
                                'reserve' => '',
                                'sale' => 'Продажа',
                                'writeoff' => 'Списание',
                                'adjustment' => 'Корректировка',
                            ][$action] }}
                        </a>
                    @endforeach
                </div>
            @else
                <div class="empty">Нет доступа к быстрым складским операциям.</div>
            @endif
            <div class="help" style="margin-top:16px;">Активных резервов: {{ $activeReservationCount }}</div>
        </div>

        <div class="panel">
            <h2 style="margin-top:0;">Бизнес-правила</h2>
            <ul style="margin:0;padding-left:18px;line-height:1.7;">
                <li>Каждый остаток привязан к конкретной ячейке.</li>
                <li>     .</li>
                <li>История движений только дополняется, без удаления.</li>
                <li>Продажа и списание работают только с доступным остатком.</li>
            </ul>
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">Последние движения</h2>
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Тип</th>
                <th>Товар</th>
                <th>Маршрут</th>
                <th>Кол-во</th>
                <th>Пользователь</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($recentMovements as $movement)
                <tr>
                    <td>{{ optional($movement->created_at)->format('Y-m-d H:i') }}</td>
                    <td><span class="tag">{{ [
                        'intake' => 'приемка',
                        'move' => 'перемещение',
                        'reserve' => 'резерв',
                        'unreserve' => 'снятие резерва',
                        'sale' => 'продажа',
                        'writeoff' => 'списание',
                        'adjustment' => 'корректировка',
                    ][$movement->type] ?? $movement->type }}</span></td>
                    <td><a href="{{ route('admin.products.show', $movement->product) }}">{{ $movement->product->name }}</a></td>
                    <td>{{ $movement->fromLocation->full_code ?? '—' }} → {{ $movement->toLocation->full_code ?? '—' }}</td>
                    <td>{{ $movement->quantity }}</td>
                    <td>{{ $movement->user->name ?? 'Система' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">История движений пока пуста.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
