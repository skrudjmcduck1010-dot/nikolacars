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
                @if(($customerOrderCashSummary['bank_fop_afterpayment_commission_uah'] ?? 0) > 0)
                    <div class="help">
                        {{ "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{043A}\u{0430}: " }}{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT] ?? 0, 'UAH') }}
                        {{ "\u{2212} \u{043A}\u{043E}\u{043C}\u{0438}\u{0441}\u{0441}\u{0438}\u{044F} \u{041D}\u{041F} 0.5%: " }}{{ number_format((float) ($customerOrderCashSummary['bank_fop_afterpayment_commission_uah'] ?? 0), 2, '.', ' ') }} {{ "\u{0433}\u{0440}\u{043D}" }}
                    </div>
                @endif
            </div>
            <div>
                <div class="help">{{ "\u{041A}\u{0430}\u{0441}\u{0441}\u{0430} \u{0050}\u{0072}\u{006F}\u{006D}" }}</div>
                <strong>{{ $money($customerOrderCashSummary[\App\Models\CustomerOrder::PAYMENT_TYPE_PROM] ?? 0, 'UAH') }}</strong>
                <div class="help">{{ "\u{0028}\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{0437}\u{0430}\u{0447}\u{0438}\u{0441}\u{043B}\u{0435}\u{043D}\u{0438}\u{044F}: " }}{{ $money($customerOrderCashSummary['prom_pending_uah'] ?? 0, 'UAH') }}{{ "\u{0029}" }}</div>
            </div>
            <div>
                <div class="help">{{ "\u{0421}\u{0422}\u{041E}: \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}" }}</div>
                <strong>{{ $money($customerOrderCashSummary['sto_parts_uah'] ?? 0, 'UAH') }}</strong>
            </div>
        </div>
    </div>

    <div
        class="panel"
        style="margin-bottom:14px;"
        data-customer-order-available-ttns
        data-url="{{ route('admin.customer-orders.nova-poshta.tracking-number.suggestions.available') }}"
        hidden
    >
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <h2 class="section-title" style="margin:0;">{{ "\u{0421}\u{0432}\u{043E}\u{0431}\u{043E}\u{0434}\u{043D}\u{044B}\u{0435} \u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}" }}</h2>
            <button type="button" class="btn btn-small btn-secondary" data-customer-order-available-ttns-refresh>
                {{ "\u{041E}\u{0431}\u{043D}\u{043E}\u{0432}\u{0438}\u{0442}\u{044C}" }}
            </button>
        </div>
        <div class="help" style="margin-top:6px;">
            Из кабинета НП, кроме полученных, отказных и уже привязанных к заказам.
        </div>
        <div data-customer-order-available-ttns-status class="help" style="margin-top:10px;">{{ "\u{0417}\u{0430}\u{0433}\u{0440}\u{0443}\u{0436}\u{0430}\u{044E}..." }}</div>
        <div data-customer-order-available-ttns-list style="display:grid; gap:8px; margin-top:10px;"></div>
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
            <h2 class="section-title" style="margin-top:0;">{{ "\u{041D}\u{043E}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0447}\u{0442}\u{0430}: \u{041E}\u{0442}\u{043A}\u{0430}\u{0437}" }}</h2>
            @include('admin.customer_orders._table', [
                'orders' => $refusedNovaPoshtaOrders,
                'useFixedColumns' => true,
                'emptyText' => "\u{041E}\u{0442}\u{043A}\u{0430}\u{0437}\u{0430}\u{043D}\u{043D}\u{044B}\u{0445} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{0439} \u{043F}\u{043E}\u{043A}\u{0430} \u{043D}\u{0435}\u{0442}.",
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
        @include('admin.customer_orders._tracking_number_editor_scripts')

        (() => {
            const panel = document.querySelector('[data-customer-order-available-ttns]');
            if (!panel) return;

            const url = panel.dataset.url || '';
            const status = panel.querySelector('[data-customer-order-available-ttns-status]');
            const list = panel.querySelector('[data-customer-order-available-ttns-list]');
            const refresh = panel.querySelector('[data-customer-order-available-ttns-refresh]');

            const setStatus = (message = '') => {
                if (!status) return;
                status.textContent = message;
                status.toggleAttribute('hidden', message === '');
            };

            const render = (items) => {
                if (!list) return;
                list.replaceChildren();

                if (!items.length) {
                    panel.hidden = true;
                    return;
                }

                panel.hidden = false;
                setStatus('');
                items.forEach((item) => {
                    const row = document.createElement('div');
                    row.style.display = 'grid';
                    row.style.gridTemplateColumns = 'minmax(140px, auto) minmax(0, 1fr)';
                    row.style.gap = '8px 12px';
                    row.style.alignItems = 'baseline';
                    row.style.padding = '8px 0';
                    row.style.borderTop = '1px solid #e5e7eb';

                    const number = document.createElement('strong');
                    number.textContent = item.tracking_number || '';

                    const detail = document.createElement('div');
                    detail.className = 'help';
                    detail.textContent = [item.date, item.status, item.city, item.recipient].filter(Boolean).join(' · ');

                    row.append(number, detail);
                    list.appendChild(row);
                });
            };

            const load = async () => {
                if (!url) return;
                refresh?.setAttribute('disabled', 'disabled');
                setStatus(@json("\u{0417}\u{0430}\u{0433}\u{0440}\u{0443}\u{0436}\u{0430}\u{044E}..."));
                list?.replaceChildren();

                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const payload = await response.json().catch(() => ([]));

                    if (!response.ok) {
                        panel.hidden = true;
                        return;
                    }

                    render(Array.isArray(payload) ? payload : []);
                } catch (error) {
                    console.error(error);
                    panel.hidden = true;
                } finally {
                    refresh?.removeAttribute('disabled');
                }
            };

            refresh?.addEventListener('click', load);
            load();
        })();
    </script>
@endsection
