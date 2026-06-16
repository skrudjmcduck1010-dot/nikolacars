@extends('layouts.admin', [
    'heading' => 'Заказ-наряды СТО',
    'subheading' => 'Записи, активные работы и история заказ-нарядов по клиентам и автомобилям.',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $statusClass = [
        'appointment' => 'tag-warning',
        'in_work' => '',
        'waiting_parts' => 'tag-warning',
        'paused' => 'tag-warning',
        'completed' => '',
        'paid' => 'tag-paid',
        'cancelled' => 'tag-danger',
        'archived' => 'tag-archived',
    ];
    $isStatusChecked = fn (string $value) => in_array($value, $statuses, true);
    $weekDayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $appointmentWord = function (int $count): string {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        return $mod10 === 1 && $mod100 !== 11
            ? 'запись'
            : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? 'записи' : 'записей');
    };
    $calendarStats = [
        ['label' => 'Всего', 'value' => $totalCount],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['in_work'], 'value' => $statusCounts->get('in_work', 0)],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['appointment'], 'value' => $statusCounts->get('appointment', 0)],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['waiting_parts'], 'value' => $statusCounts->get('waiting_parts', 0)],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['paused'], 'value' => $statusCounts->get('paused', 0)],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['completed'], 'value' => $statusCounts->get('completed', 0)],
        ['label' => \App\Models\StoWorkOrder::STATUS_LABELS['archived'], 'value' => $archivedCount, 'class' => 'is-archived'],
    ];
@endphp

@section('content')
    <style>
        .status-dropdown { position: relative; min-width: 220px; }
        .status-dropdown summary { display:flex; align-items:center; justify-content:space-between; min-height:42px; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:white; cursor:pointer; font-weight:500; list-style:none; }
        .status-dropdown summary::-webkit-details-marker { display:none; }
        .status-dropdown summary::after { content:'▾'; color:var(--muted); font-size:12px; }
        .status-dropdown[open] summary::after { content:'▴'; }
        .status-dropdown-menu { position:absolute; z-index:30; top:calc(100% + 6px); left:0; min-width:260px; padding:10px; border:1px solid var(--line); border-radius:8px; background:white; box-shadow:0 12px 30px rgba(15,23,42,.14); }
        .status-dropdown-menu label { display:inline-flex; align-items:center; gap:6px; width:100%; padding:7px 6px; margin:0; color:var(--text); font-weight:500; }
        .status-dropdown-menu input { width:auto; }
        .sto-calendar-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:16px; }
        .sto-calendar-header h2 { margin:0; font-size:22px; line-height:1.2; }
        .sto-calendar-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; }
        .sto-calendar-side { display:grid; justify-items:end; gap:10px; }
        .sto-calendar-stats { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:6px 10px; max-width:560px; color:var(--muted); font-size:12px; line-height:1.25; text-align:right; }
        .sto-calendar-stat { display:inline-flex; align-items:baseline; gap:4px; white-space:nowrap; }
        .sto-calendar-stat-value { color:var(--text); font-weight:800; }
        .sto-calendar-stat.is-archived, .sto-calendar-stat.is-archived .sto-calendar-stat-value { color:#94a3b8; }
        .sto-week-grid { display:grid; grid-template-columns:repeat(7, minmax(140px, 1fr)); gap:10px; overflow-x:auto; padding-bottom:2px; }
        .sto-week-day { min-width:140px; min-height:190px; padding:12px; border:1px solid var(--line); border-radius:12px; background:white; }
        .sto-week-day.is-today { border-color:var(--accent); box-shadow:inset 0 0 0 1px var(--accent); }
        .sto-week-day-head { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:10px; }
        .sto-week-day-name { color:var(--muted); font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .sto-week-day-date { margin-top:2px; font-size:20px; font-weight:800; line-height:1; }
        .sto-week-day-date a { color:inherit; text-decoration:none; }
        .sto-week-count { flex:0 0 auto; min-width:28px; padding:4px 8px; border-radius:999px; background:var(--accent-soft); color:var(--accent); font-size:12px; font-weight:700; text-align:center; }
        .sto-calendar-list { display:grid; gap:8px; }
        .sto-calendar-order { display:grid; gap:3px; padding:9px; border:1px solid #e4ddcf; border-radius:8px; background:#fffdf8; color:var(--text); }
        .sto-calendar-time { color:var(--accent); font-size:12px; font-weight:800; }
        .sto-calendar-client { overflow-wrap:anywhere; font-weight:700; line-height:1.25; }
        .sto-calendar-car { color:var(--muted); font-size:12px; line-height:1.25; overflow-wrap:anywhere; }
        .sto-calendar-empty { display:grid; gap:8px; padding:10px; border:1px dashed var(--line); border-radius:8px; color:var(--muted); font-size:13px; }
        .payment-modal { width:min(520px, calc(100vw - 28px)); border:1px solid var(--line); border-radius:10px; padding:0; color:var(--text); }
        .payment-modal::backdrop { background:rgba(15,23,42,.35); }
        .payment-modal-body { padding:18px; }
        .payment-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
        .payment-modal-head h3 { margin:0; font-size:20px; }
        .payment-modal-close { border:0; background:transparent; color:var(--muted); font-size:24px; line-height:1; padding:0 4px; }
        .work-order-print-link { width:34px; height:34px; padding:7px; }
        .work-order-print-link svg { width:17px; height:17px; flex:0 0 17px; }
        @media (max-width:980px) { .sto-calendar-header { flex-direction:column; } .sto-calendar-actions { justify-content:flex-start; } .sto-calendar-side { justify-items:start; } .sto-calendar-stats { justify-content:flex-start; text-align:left; } }
    </style>

    <div class="panel" style="margin-bottom:18px;">
        <div class="sto-calendar-header">
            <div>
                <h2>Календарь записи на СТО</h2>
                <div class="help">Неделя: {{ $calendarStart->format('d.m.Y') }} &mdash; {{ $calendarEnd->format('d.m.Y') }}</div>
            </div>
            <div class="sto-calendar-side">
                <div class="sto-calendar-stats" aria-label="Статистика заказ-нарядов">
                    @foreach ($calendarStats as $stat)
                        <span class="sto-calendar-stat {{ $stat['class'] ?? '' }}"><span>{{ $stat['label'] }}</span><span class="sto-calendar-stat-value">{{ $stat['value'] }}</span></span>
                    @endforeach
                </div>
                <div class="sto-calendar-actions">
                    <a class="btn btn-secondary" href="{{ route('admin.sto-work-orders.index', array_merge(request()->except('page', 'week_start'), ['week_start' => $calendarPreviousWeekStart])) }}">Предыдущая неделя</a>
                    <a class="btn btn-secondary" href="{{ route('admin.sto-work-orders.index', array_merge(request()->except('page', 'week_start'), ['week_start' => $calendarCurrentWeekStart])) }}">Текущая неделя</a>
                    <a class="btn btn-secondary" href="{{ route('admin.sto-work-orders.index', array_merge(request()->except('page', 'week_start'), ['week_start' => $calendarNextWeekStart])) }}">Следующая неделя</a>
                    <a class="btn" href="{{ route('admin.sto-work-orders.create') }}">Создать запись</a>
                </div>
            </div>
        </div>

        <div class="sto-week-grid">
            @foreach ($calendarDays as $day)
                @php
                    $dateKey = $day->toDateString();
                    $dayAppointments = $calendarAppointments->get($dateKey, collect());
                    $kyivNow = \Illuminate\Support\Carbon::now('Europe/Kyiv');
                    $kyivToday = $kyivNow->toDateString();
                    $canCreateAppointment = $dateKey > $kyivToday || ($dateKey === $kyivToday && $kyivNow->format('H:i') <= '19:00');
                @endphp
                <section class="sto-week-day @if ($day->isToday()) is-today @endif">
                    <div class="sto-week-day-head">
                        <div>
                            <div class="sto-week-day-name">{{ $weekDayLabels[$loop->index] }}</div>
                            <div class="sto-week-day-date">
                                @if ($canCreateAppointment)
                                    <a href="{{ route('admin.sto-work-orders.create', ['opened_at' => $dateKey]) }}">{{ $day->format('d.m') }}</a>
                                @else
                                    {{ $day->format('d.m') }}
                                @endif
                            </div>
                            @if ($day->isToday())<div class="help">Сегодня</div>@endif
                        </div>
                        <div class="sto-week-count" title="{{ $dayAppointments->count() }} {{ $appointmentWord($dayAppointments->count()) }}">{{ $dayAppointments->count() }}</div>
                    </div>
                    <div class="sto-calendar-list">
                        @forelse ($dayAppointments as $appointment)
                            <a class="sto-calendar-order" href="{{ route('admin.sto-work-orders.show', $appointment) }}">
                                <span class="sto-calendar-time">{{ $appointment->appointment_time ? \Illuminate\Support\Str::of($appointment->appointment_time)->substr(0, 5) : 'Без времени' }}</span>
                                <span class="sto-calendar-client">{{ $appointment->client_name }}</span>
                                <span class="sto-calendar-car">{{ $appointment->car_title ?: $appointment->license_plate ?: $appointment->number }}</span>
                            </a>
                        @empty
                            <div class="sto-calendar-empty"><span>Записей нет</span>@if ($canCreateAppointment)<a class="btn btn-small" href="{{ route('admin.sto-work-orders.create', ['opened_at' => $dateKey]) }}">Добавить запись</a>@endif</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:18px;">
            <form method="GET" class="actions" style="align-items:end;">
                <input type="hidden" name="week_start" value="{{ $calendarStart->toDateString() }}">
                <div>
                    <label>Статусы</label>
                    <details class="status-dropdown">
                        <summary>@if ($isStatusChecked('all')) Все статусы @else {{ collect(\App\Models\StoWorkOrder::STATUS_LABELS)->only($statuses)->values()->join(', ') }} @endif</summary>
                        <div class="status-dropdown-menu">
                            @foreach(\App\Models\StoWorkOrder::STATUS_LABELS as $value => $label)
                                <label><input type="checkbox" name="statuses[]" value="{{ $value }}" @checked($isStatusChecked($value))><span>{{ $label }}</span></label>
                            @endforeach
                            <label><input type="checkbox" name="statuses[]" value="all" @checked($isStatusChecked('all'))><span>Все</span></label>
                        </div>
                    </details>
                </div>
                <div><label for="search">Поиск</label><input id="search" name="search" value="{{ $search }}" placeholder="Номер, клиент, VIN, госномер"></div>
                <button type="submit">Применить</button>
            </form>
            <a class="btn" href="{{ route('admin.sto-work-orders.create') }}">Создать заказ-наряд</a>
        </div>

        <table>
            <thead><tr><th>Номер</th><th>Статус</th><th>Даты</th><th>Клиент</th><th>Авто</th><th>Заявка</th><th>Сумма</th><th></th></tr></thead>
            <tbody>
            @forelse ($orders as $order)
                @php($paymentDue = max(0, (float) $order->total_cost_uah - (float) $order->paid_amount_uah))
                <tr>
                    <td><a href="{{ route('admin.sto-work-orders.show', $order) }}">{{ $order->number }}</a></td>
                    <td><span class="tag {{ $statusClass[$order->status] ?? '' }}">{{ $order->status_label }}</span></td>
                    <td>
                        @if ($order->status === \App\Models\StoWorkOrder::STATUS_APPOINTMENT)<div>Запись: {{ $order->opened_at?->format('d.m.Y') }}</div>@endif
                        <div class="help">Начало работ: {{ $order->work_started_at?->format('d.m.Y H:i') ?: '—' }}</div>
                        <div class="help">Завершен: {{ $order->completed_at?->format('d.m.Y H:i') ?: '—' }}</div>
                        @if ($order->planned_finished_at)<div class="help">План: {{ $order->planned_finished_at->format('d.m.Y') }}</div>@endif
                    </td>
                    <td><div>@if ($order->counterparty)<a href="{{ route('admin.counterparties.show', $order->counterparty) }}">{{ $order->client_name }}</a>@else {{ $order->client_name }} @endif</div>@if ($order->client_phone)<div class="help">{{ $order->client_phone }}</div>@endif</td>
                    <td><div>{{ $order->car_title ?: '—' }}</div>@if ($order->vin)<div class="help">VIN: {{ $order->vin }}</div>@endif @if ($order->license_plate)<div class="help">№ {{ $order->license_plate }}</div>@endif</td>
                    <td>{{ \Illuminate\Support\Str::limit($order->customer_request ?: $order->work_description ?: '—', 90) }}</td>
                    <td>{{ $money($order->total_cost_uah) }} грн</td>
                    <td>
                        <div class="actions">
                            @if (in_array($order->status, ['completed', 'paid'], true))
                                <a class="btn btn-small btn-secondary work-order-print-link" href="{{ route('admin.sto-work-orders.print', $order) }}" target="_blank" rel="noopener" aria-label="Печать заказ-наряда {{ $order->number }}" title="Печать">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
                                </a>
                            @endif
                            @if ($order->canConfirmPayment())<button type="button" class="btn btn-small" data-payment-modal-open="payment-modal-{{ $order->id }}">Подтвердить оплату</button>@endif
                            @if ($order->canArchive())<form method="POST" action="{{ route('admin.sto-work-orders.archive', $order) }}" class="inline-form" onsubmit='return confirm(@json("Перенести заказ-наряд {$order->number} в архив?"));'>@csrf<button type="submit" class="btn btn-small btn-secondary">Архив</button></form>@endif
                        </div>
                        @if ($order->canConfirmPayment())
                            <dialog id="payment-modal-{{ $order->id }}" class="payment-modal" data-payment-modal>
                                <form method="POST" action="{{ route('admin.sto-work-orders.payment.confirm', $order) }}" class="payment-modal-body">
                                    @csrf
                                    <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
                                    <div class="payment-modal-head"><div><h3>Подтвердить оплату</h3><div class="help">{{ $order->number }} · к оплате: {{ $money($paymentDue) }} грн из {{ $money($order->total_cost_uah) }} грн</div></div><button type="button" class="payment-modal-close" data-payment-modal-close aria-label="Закрыть">&times;</button></div>
                                    <p>Укажите сумму, которую получили по заказу.</p>
                                    <div class="form-grid">
                                        <div><label>Тип оплаты</label><select name="payments[0][payment_method]" required><option value="cash_uah">Нал грн</option><option value="cash_usd">Нал USD</option><option value="bank_uah">Банк грн</option></select></div>
                                        <div><label>Полученная сумма</label><input type="number" name="payments[0][amount]" min="0.01" step="0.01" value="{{ number_format($paymentDue, 2, '.', '') }}" required></div>
                                        <div class="actions full"><button type="button" class="btn btn-secondary" data-payment-modal-close>Отмена</button><button type="submit" class="btn">Подтвердить оплату</button></div>
                                    </div>
                                </form>
                            </dialog>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Заказ-нарядов пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:14px;">{{ $orders->links() }}</div>
    </div>

    <script>
        (() => {
            document.querySelectorAll('[data-payment-modal-open]').forEach((openButton) => {
                const modal = document.getElementById(openButton.dataset.paymentModalOpen);
                if (!modal) return;
                const closeModal = () => typeof modal.close === 'function' ? modal.close() : modal.removeAttribute('open');
                openButton.addEventListener('click', () => typeof modal.showModal === 'function' ? modal.showModal() : modal.setAttribute('open', 'open'));
                modal.querySelectorAll('[data-payment-modal-close]').forEach((button) => button.addEventListener('click', closeModal));
            });
        })();
    </script>
@endsection
