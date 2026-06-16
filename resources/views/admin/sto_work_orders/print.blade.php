<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Заказ-наряд {{ $order->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; color: #111827; font-family: Arial, sans-serif; font-size: 14px; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 18px; }
        .toolbar button, .toolbar a { display: inline-flex; align-items: center; min-height: 34px; padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; font: inherit; cursor: pointer; }
        h1 { margin: 0 0 18px; font-size: 22px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .meta th { width: 210px; text-align: left; color: #475569; font-weight: 600; }
        .meta th, .meta td { padding: 5px 0; vertical-align: top; }
        .section-title { margin: 22px 0 8px; font-size: 16px; font-weight: 700; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th, .items td { border: 1px solid #cbd5e1; padding: 8px; vertical-align: top; }
        .items th { background: #f8fafc; text-align: left; }
        .num { width: 42px; text-align: center; }
        .qty, .unit, .money { width: 90px; text-align: right; }
        .line-note { margin-top: 3px; color: #64748b; font-size: 12px; }
        .summary { display: grid; justify-content: end; gap: 4px; margin-top: 14px; font-weight: 700; }
        .signature { margin-top: 28px; font-weight: 700; }
        @media print { body { padding: 0; } .toolbar { display: none; } }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
        $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
        $openedAt = $order->opened_at;
        $createdAt = $order->created_at?->format('d.m.Y H:i');
        $workStartedAt = $order->work_started_at?->format('d.m.Y H:i');
        $closedAt = $order->completed_at?->format('d.m.Y H:i');
        $worksTotal = (float) $order->works->sum('price_uah');
        $partsTotal = (float) $order->parts->sum('total_price_uah');
        $itemsCount = $order->works->count() + $order->parts->count();
        $openedDate = $openedAt ? $openedAt->format('d.m.Y') : '';
    @endphp

    <div class="toolbar"><button type="button" onclick="window.print()">Печать</button><a href="{{ route('admin.sto-work-orders.show', $order) }}">Назад</a></div>

    <h1>Заказ-наряд № {{ $order->number }} от {{ $openedDate }}</h1>
    <table class="meta">
        <tr><th>Заказчик:</th><td>{{ $order->client_name }}</td></tr>
        @if ($order->client_phone)<tr><th>Телефон:</th><td>{{ $order->client_phone }}</td></tr>@endif
        @if ($order->car_title)<tr><th>Авто:</th><td>{{ $order->car_title }}</td></tr>@endif
        @if ($order->vin || $order->license_plate)<tr><th>VIN / госномер:</th><td>{{ collect([$order->vin, $order->license_plate])->filter()->join(' / ') }}</td></tr>@endif
        <tr><th>Дата создания документа:</th><td>{{ $createdAt ?: '—' }}</td></tr>
        <tr><th>Дата начала работ:</th><td>{{ $workStartedAt ?: '—' }}</td></tr>
        <tr><th>Дата завершения работ:</th><td>{{ $closedAt ?: '—' }}</td></tr>
    </table>

    <div class="section-title">Работы</div>
    <table class="items">
        <thead><tr><th class="num">№</th><th>Работа</th><th class="qty">Кол-во</th><th class="unit">Ед.</th><th class="money">Цена</th><th class="money">Сумма</th></tr></thead>
        <tbody>
            @forelse ($order->works as $work)
                <tr><td class="num">{{ $loop->iteration }}</td><td>{{ $work->name }}@if($work->note)<div class="line-note">{{ $work->note }}</div>@endif</td><td class="qty">1</td><td class="unit">шт</td><td class="money">{{ $money($work->price_uah) }}</td><td class="money">{{ $money($work->price_uah) }}</td></tr>
            @empty
                <tr><td colspan="6">Работы не добавлены.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Запчасти</div>
    <table class="items">
        <thead><tr><th class="num">№</th><th>Запчасть</th><th class="qty">Кол-во</th><th class="unit">Ед.</th><th class="money">Цена</th><th class="money">Сумма</th></tr></thead>
        <tbody>
            @forelse ($order->parts as $part)
                <tr><td class="num">{{ $loop->iteration }}</td><td>{{ $part->name }}@if($part->product || $part->note)<div class="line-note">{{ collect([$part->product?->sku, $part->product?->external_sku, $part->note])->filter()->join(' · ') }}</div>@endif</td><td class="qty">{{ $quantity($part->quantity) }}</td><td class="unit">шт</td><td class="money">{{ $money($part->unit_price_uah) }}</td><td class="money">{{ $money($part->total_price_uah) }}</td></tr>
            @empty
                <tr><td colspan="6">Запчасти не добавлены.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        <div>Позиций: {{ $itemsCount }}, на сумму {{ $money($worksTotal + $partsTotal) }} грн</div>
        @if ((float) $order->discount_uah > 0)<div>Скидка: {{ $money($order->discount_uah) }} грн</div>@endif
        <div>К оплате: {{ $money($order->total_cost_uah ?: ($worksTotal + $partsTotal)) }} грн</div>
    </div>

    <div class="signature">К оплате: {{ $money($order->total_cost_uah ?: ($worksTotal + $partsTotal)) }} грн</div>
    <p>Срок выполнения зависит от наличия деталей и состояния автомобиля. Клиент подтверждает получение работ и деталей по заказ-наряду.</p>
</body>
</html>
