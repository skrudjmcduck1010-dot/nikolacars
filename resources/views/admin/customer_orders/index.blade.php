@extends('layouts.admin', [
    'heading' => 'Заказы',
    'subheading' => 'Заказы клиентов на продажу запчастей из корзины NikolaCars.',
])

@php
    $money = fn ($value, string $currency = 'UAH') => $currency === 'UAH'
        ? number_format((float) $value, 0, '.', ' ').' грн'
        : number_format((float) $value, 2, '.', ' ').' '.$currency;
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $ordersTabUrl = fn (?string $tab) => route('admin.customer-orders.index', array_filter([
        'q' => $query,
        'tab' => $tab,
    ], fn ($value) => $value !== null && $value !== ''));
@endphp

@section('content')
    <div class="panel" style="margin-bottom:14px;">
        <h2 class="section-title" style="margin-top:0;">Касса</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px;">
            <div>
                <div class="help">Нал, грн</div>
                <strong>{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_CASH_UAH] ?? 0, 'UAH') }}</strong>
            </div>
            <div>
                <div class="help">Нал USD</div>
                <strong>{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_CASH_USD] ?? 0, 'USD') }}</strong>
            </div>
            <div>
                <div class="help">БезНал ТОВ</div>
                <strong>{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_TOV] ?? 0, 'UAH') }}</strong>
            </div>
            <div>
                <div class="help">БезНал ФОП</div>
                <strong>{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP] ?? 0, 'UAH') }}</strong>
            </div>
        </div>
    </div>

    <div class="actions" style="margin-bottom:14px;">
        <a
            href="{{ $ordersTabUrl(null) }}"
            @class(['btn', 'btn-secondary' => $tab !== 'active'])
        >
            Активные
        </a>
        <a
            href="{{ $ordersTabUrl('cancelled') }}"
            @class(['btn', 'btn-secondary' => $tab !== 'cancelled'])
        >
            Отмененные заказы
        </a>
    </div>

    <div class="panel">
        @include('admin.customer_orders._table', [
            'orders' => $orders,
            'useFixedColumns' => true,
            'emptyText' => $tab === 'cancelled'
                ? 'Отмененных заказов пока нет.'
                : 'Заказы пока не созданы.',
        ])

        <div style="margin-top:16px;">
            {{ $orders->links() }}
        </div>
    </div>

    @if($tab === 'active')
        <div class="panel" style="margin-top:18px;">
            <h2 class="section-title" style="margin-top:0;">{{ "\u{041D}\u{043E}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0447}\u{0442}\u{0430}: \u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}" }}</h2>
            @include('admin.customer_orders._table', [
                'orders' => $shippedNovaPoshtaOrders,
                'useFixedColumns' => true,
                'emptyText' => "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044B}\u{0445} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{0439} \u{043F}\u{043E}\u{043A}\u{0430} \u{043D}\u{0435}\u{0442}.",
            ])
        </div>

        <div class="panel" style="margin-top:18px;">
            <h2 class="section-title" style="margin-top:0;">Выдан</h2>
            @include('admin.customer_orders._table', [
                'orders' => $completedOrders,
                'useFixedColumns' => true,
                'emptyText' => 'Выданных заказов пока нет.',
            ])
        </div>
    @endif

    <script>
        @include('admin.customer_orders._payment_modal_scripts')
    </script>
@endsection
