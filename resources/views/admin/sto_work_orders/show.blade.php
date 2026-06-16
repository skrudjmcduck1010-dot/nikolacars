@extends('layouts.admin', [
    'heading' => 'Заказ-наряд '.$order->number,
    'subheading' => $order->client_name.' · '.$order->status_label,
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
    $appointmentTime = $order->appointment_time ? \Illuminate\Support\Str::of($order->appointment_time)->substr(0, 5) : null;
    $availableStatuses = collect(\App\Models\StoWorkOrder::STATUS_LABELS)
        ->reject(fn ($label, $status) => $status === $order->status)
        ->reject(fn ($label, $status) => in_array($status, ['paid', 'archived'], true))
        ->reject(fn ($label, $status) => $order->status === 'paid')
        ->reject(fn ($label, $status) => in_array($status, ['waiting_parts', 'paused'], true) && $order->status !== 'in_work')
        ->reject(fn ($label, $status) => $order->status === 'completed' && in_array($status, ['appointment', 'cancelled'], true));
    $statusClass = ['appointment' => 'tag-warning', 'waiting_parts' => 'tag-warning', 'paused' => 'tag-warning', 'paid' => 'tag-paid', 'cancelled' => 'tag-danger', 'archived' => 'tag-archived'][$order->status] ?? '';
    $paymentDue = max(0, (float) $order->total_cost_uah - (float) $order->paid_amount_uah);
@endphp

@section('content')
    <style>
        .order-items-form { grid-template-columns:minmax(180px,1fr) 110px 130px auto; align-items:end; gap:10px; }
        .order-work-form { grid-template-columns:minmax(190px,1fr) minmax(160px,.8fr) 140px auto; align-items:end; gap:10px; }
        .part-search { position:relative; }
        .part-search-results { position:absolute; z-index:40; top:calc(100% + 6px); left:0; right:0; max-height:320px; overflow:auto; border:1px solid var(--line); border-radius:8px; background:white; box-shadow:0 12px 30px rgba(15,23,42,.14); }
        .part-search-result { display:block; width:100%; border:0; border-radius:0; padding:10px 12px; background:white; color:var(--text); text-align:left; font-weight:500; }
        .part-search-result:hover, .part-search-result:focus { background:#f2f6f5; outline:none; }
        .status-dropdown { position:relative; display:inline-block; }
        .status-dropdown summary { display:inline-flex; align-items:center; gap:8px; list-style:none; cursor:pointer; }
        .status-dropdown summary::-webkit-details-marker { display:none; }
        .status-dropdown summary::after { content:'▾'; color:var(--muted); font-size:12px; }
        .status-dropdown[open] summary::after { content:'▴'; }
        .status-dropdown-menu { position:absolute; z-index:30; top:calc(100% + 8px); left:0; display:grid; gap:6px; min-width:210px; padding:8px; border:1px solid var(--line); border-radius:10px; background:white; box-shadow:0 12px 30px rgba(15,23,42,.14); }
        .status-dropdown-menu form { margin:0; }
        .status-dropdown-menu button { width:100%; justify-content:flex-start; border-radius:8px; background:white; color:var(--text); font-weight:600; }
        .work-order-page-actions { display:flex; justify-content:flex-end; margin-bottom:18px; }
        .print-order-btn { gap:8px; }
        .print-order-btn svg { width:18px; height:18px; flex:0 0 18px; }
        .payment-modal { width:min(520px, calc(100vw - 28px)); border:1px solid var(--line); border-radius:10px; padding:0; color:var(--text); }
        .payment-modal::backdrop { background:rgba(15,23,42,.35); }
        .payment-modal-body { padding:18px; }
        .payment-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
        .payment-modal-head h3 { margin:0; font-size:20px; }
        .payment-modal-close { border:0; background:transparent; color:var(--muted); font-size:24px; line-height:1; padding:0 4px; }
        @media (max-width:980px) { .order-items-form, .order-work-form { grid-template-columns:1fr; } .work-order-page-actions { justify-content:flex-start; } }
    </style>

    <div class="work-order-page-actions">
        <a class="btn btn-secondary print-order-btn" href="{{ route('admin.sto-work-orders.print', $order) }}" target="_blank" rel="noopener" aria-label="Печать заказ-наряда">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
            <span>Печать заказ-наряда</span>
        </a>
    </div>

    <div class="grid grid-2">
        <div class="panel">
            <h2 class="section-title">Клиент и авто</h2>
            <table style="margin-top:12px;">
                <tr><th>Клиент</th><td>{{ $order->client_name }}</td></tr>
                <tr><th>Телефон</th><td>{{ $order->client_phone ?: '—' }}</td></tr>
                <tr><th>Авто</th><td>{{ $order->car_title ?: '—' }}</td></tr>
                @unless ($order->status === 'appointment')<tr><th>VIN</th><td>{{ $order->vin ?: '—' }}</td></tr>@endunless
                <tr><th>Госномер</th><td>{{ $order->license_plate ?: '—' }}</td></tr>
                @unless ($order->status === 'appointment')<tr><th>Пробег</th><td>{{ $order->mileage ? number_format($order->mileage, 0, ',', ' ').' км' : '—' }}</td></tr>@endunless
            </table>
        </div>
        <div class="panel">
            <h2 class="section-title">Заказ</h2>
            <table style="margin-top:12px;">
                <tr><th>Статус</th><td><details class="status-dropdown"><summary><span class="tag {{ $statusClass }}">{{ $order->status_label }}</span></summary><div class="status-dropdown-menu">@foreach ($availableStatuses as $status => $label)<form method="POST" action="{{ route('admin.sto-work-orders.status.update', $order) }}">@csrf<input type="hidden" name="status" value="{{ $status }}"><button type="submit">{{ $label }}</button></form>@endforeach</div></details></td></tr>
                <tr><th>Дата записи</th><td>{{ $order->opened_at?->format('d.m.Y') }}@if($appointmentTime) {{ $appointmentTime }}@endif</td></tr>
                <tr><th>Дата начала работ</th><td>{{ $order->work_started_at?->format('d.m.Y H:i') ?: '—' }}</td></tr>
                <tr><th>План выдачи</th><td>{{ $order->planned_finished_at?->format('d.m.Y') ?: '—' }}</td></tr>
                <tr><th>Завершен</th><td>{{ $order->completed_at?->format('d.m.Y H:i') ?: '—' }}</td></tr>
                <tr><th>Работы</th><td>{{ $money($order->labor_cost_uah) }} грн</td></tr>
                <tr><th>Запчасти</th><td>{{ $money($order->parts_cost_uah) }} грн</td></tr>
                <tr><th>Скидка</th><td>{{ $money($order->discount_uah) }} грн</td></tr>
                <tr><th>Итого</th><td><strong>{{ $money($order->total_cost_uah) }} грн</strong></td></tr>
                <tr><th>Получено оплат</th><td>{{ $money($order->paid_amount_uah) }} грн</td></tr>
                <tr><th>Остаток</th><td>{{ $money($paymentDue) }} грн</td></tr>
            </table>
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 class="section-title">Описание</h2>
        <div class="grid grid-2" style="margin-top:12px;">
            <div><div class="help">Заявка клиента</div><p>{{ $order->customer_request ?: '—' }}</p></div>
            <div><div class="help">Общее описание работ</div><p>{{ $order->work_description ?: '—' }}</p></div>
            <div class="full"><div class="help">Общая заметка по запчастям</div><p>{{ $order->parts_note ?: '—' }}</p></div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:18px;">
        <div class="panel">
            <h2 class="section-title">Список запчастей</h2>
            @if ($order->canAddLineItems())
                <form method="POST" action="{{ route('admin.sto-work-orders.parts.store', $order) }}" class="form-grid order-items-form" style="margin-top:14px;">
                    @csrf
                    <div><label>Запчасть</label><input type="hidden" name="product_id" value="{{ old('product_id', $selectedPart['id'] ?? '') }}" data-part-id><div class="part-search"><input type="search" name="part_search" value="{{ old('part_search', $selectedPart ? trim(($selectedPart['sku'] ? $selectedPart['sku'].' · ' : '').$selectedPart['name']) : '') }}" placeholder="Введите номер, название или SKU" autocomplete="off" data-part-search data-search-url="{{ route('admin.sto-work-orders.parts.search') }}"><div class="part-search-results" data-part-results hidden></div></div><div class="help" data-exchange-rate-label>Курс для USD: {{ $exchangeRate['label'] }}</div>@error('product_id')<div class="error">{{ $message }}</div>@enderror</div>
                    <div><label>Кол-во</label><input type="number" name="quantity" min="0.001" step="0.001" value="{{ old('quantity', 1) }}" required>@error('quantity')<div class="error">{{ $message }}</div>@enderror</div>
                    <div><label>Цена, грн</label><input type="number" name="unit_price_uah" min="0.01" step="0.01" value="{{ old('unit_price_uah') }}" required></div>
                    <button type="submit">Добавить</button>
                    <div class="full"><label>Комментарий</label><input name="note" value="{{ old('note') }}" placeholder="Состояние, место, примечание"></div>
                </form>
            @endif
            <table style="margin-top:16px;"><thead><tr><th>Запчасть</th><th>Кол-во</th><th>Сумма</th><th></th></tr></thead><tbody>@forelse ($order->parts as $part)<tr><td><strong>{{ $part->name }}</strong>@if ($part->note)<div class="help">{{ $part->note }}</div>@endif<div class="help">{{ $money($part->unit_price_uah) }} грн за ед.</div></td><td>{{ $quantity($part->quantity) }}</td><td>{{ $money($part->total_price_uah) }} грн</td><td>@if ($order->canDeleteLineItems())<form method="POST" action="{{ route('admin.sto-work-orders.parts.destroy', [$order, $part]) }}" class="inline-form">@csrf @method('DELETE')<button type="submit" class="btn btn-small btn-danger">Удалить</button></form>@endif</td></tr>@empty<tr><td colspan="4" class="empty">Запчасти пока не добавлены.</td></tr>@endforelse</tbody></table>
        </div>

        <div class="panel">
            <h2 class="section-title">Список работ</h2>
            @if ($order->canAddLineItems())
                <form method="POST" action="{{ route('admin.sto-work-orders.works.store', $order) }}" class="form-grid order-work-form" style="margin-top:14px;">
                    @csrf
                    <div><label>Работа</label><div class="part-search"><input type="search" name="name" value="{{ old('name') }}" placeholder="Тип работы" autocomplete="off" data-work-search data-search-url="{{ route('admin.sto-work-orders.works.search') }}" required><div class="part-search-results" data-work-results hidden></div></div></div>
                    <div><label>Сотрудник</label><select name="sto_employee_id" required><option value="">—</option>@foreach ($activeEmployees as $employee)<option value="{{ $employee->id }}" @selected((int) old('sto_employee_id') === $employee->id)>{{ $employee->cash_employee_name }}</option>@endforeach</select></div>
                    <div><label>Стоимость, грн</label><input type="number" name="price_uah" min="0.01" step="0.01" value="{{ old('price_uah') }}" required></div>
                    <button type="submit">Добавить</button>
                    <div class="full"><label>Комментарий</label><input name="note" value="{{ old('note') }}" placeholder="Пояснение, этап, результат"></div>
                </form>
            @endif
            <table style="margin-top:16px;"><thead><tr><th>Работа</th><th>Сумма</th><th></th></tr></thead><tbody>@forelse ($order->works as $work)<tr><td><strong>{{ $work->name }}</strong><div class="help">Сотрудник: {{ $work->employee?->cash_employee_name ?: '—' }}</div>@if ($work->note)<div class="help">{{ $work->note }}</div>@endif</td><td>{{ $money($work->price_uah) }} грн</td><td>@if ($order->canDeleteLineItems())<form method="POST" action="{{ route('admin.sto-work-orders.works.destroy', [$order, $work]) }}" class="inline-form">@csrf @method('DELETE')<button type="submit" class="btn btn-small btn-danger">Удалить</button></form>@endif</td></tr>@empty<tr><td colspan="3" class="empty">Работы пока не добавлены.</td></tr>@endforelse</tbody></table>
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 class="section-title">Комментарий по СТО</h2>
        <form method="POST" action="{{ route('admin.sto-work-orders.sto-comment.update', $order) }}" class="form-grid" style="margin-top:14px;">@csrf<div class="full"><label>Комментарий по СТО</label><textarea name="sto_comment" rows="4" placeholder="Внутренний комментарий по заказ-наряду">{{ old('sto_comment', $order->sto_comment) }}</textarea>@error('sto_comment')<div class="error">{{ $message }}</div>@enderror</div><div class="actions full"><button type="submit">Сохранить</button></div></form>
    </div>

    <div class="panel" style="margin-top:18px;"><div class="actions"><a class="btn btn-secondary" href="{{ route('admin.sto-work-orders.index') }}">К списку</a>@if (! in_array($order->status, ['completed', 'paid', 'cancelled', 'archived'], true))<form method="POST" action="{{ route('admin.sto-work-orders.status.update', $order) }}" class="inline-form" onsubmit='return confirm(@json("Завершить заказ-наряд {$order->number}?"));'>@csrf<input type="hidden" name="status" value="completed"><button type="submit" class="btn">Завершить заказ-наряд</button></form><form method="POST" action="{{ route('admin.sto-work-orders.status.update', $order) }}" class="inline-form" onsubmit='return confirm(@json("Отменить заказ-наряд {$order->number}? Складские движения не меняются."));'>@csrf<input type="hidden" name="status" value="cancelled"><button type="submit" class="btn btn-danger">Отменить</button></form>@endif @if ($order->canConfirmPayment())<button type="button" class="btn" data-payment-modal-open>Подтвердить оплату</button>@endif @if ($order->canArchive())<form method="POST" action="{{ route('admin.sto-work-orders.archive', $order) }}" class="inline-form" onsubmit='return confirm(@json("Перенести заказ-наряд {$order->number} в архив?"));'>@csrf<button type="submit" class="btn btn-secondary">Архив</button></form>@endif</div></div>

    @if ($order->canConfirmPayment())
        <dialog class="payment-modal" data-payment-modal><form method="POST" action="{{ route('admin.sto-work-orders.payment.confirm', $order) }}" class="payment-modal-body">@csrf<div class="payment-modal-head"><div><h3>Подтвердить оплату</h3><div class="help">К оплате: {{ $money($paymentDue) }} грн из {{ $money($order->total_cost_uah) }} грн</div></div><button type="button" class="payment-modal-close" data-payment-modal-close aria-label="Закрыть">&times;</button></div><p>Укажите сумму, которую получили по заказу.</p><div class="form-grid"><div><label>Тип оплаты</label><select name="payments[0][payment_method]" required><option value="cash_uah" @selected(old('payment_method') === 'cash_uah')>Нал грн</option><option value="cash_usd" @selected(old('payment_method') === 'cash_usd')>Нал USD</option><option value="bank_uah" @selected(old('payment_method') === 'bank_uah')>Банк грн</option></select>@error('payment_method')<div class="error">{{ $message }}</div>@enderror</div><div><label>Полученная сумма</label><input type="number" name="payments[0][amount]" min="0.01" step="0.01" value="{{ old('amount', number_format($paymentDue, 2, '.', '')) }}" required>@error('amount')<div class="error">{{ $message }}</div>@enderror</div><div class="actions full"><button type="button" class="btn btn-secondary" data-payment-modal-close>Отмена</button><button type="submit" class="btn">Подтвердить оплату</button></div></div></form></dialog>
    @endif

    <script>
        (() => { const modal = document.querySelector('[data-payment-modal]'); const openButton = document.querySelector('[data-payment-modal-open]'); if (!modal || !openButton) return; const closeModal = () => typeof modal.close === 'function' ? modal.close() : modal.removeAttribute('open'); openButton.addEventListener('click', () => typeof modal.showModal === 'function' ? modal.showModal() : modal.setAttribute('open', 'open')); modal.querySelectorAll('[data-payment-modal-close]').forEach((button) => button.addEventListener('click', closeModal)); })();
        (() => {
            const setupSearch = (inputSelector, resultsSelector, selectItem, renderMeta) => {
                const searchInput = document.querySelector(inputSelector); const results = document.querySelector(resultsSelector); if (!searchInput || !results) return; let searchTimeout = null; let abortController = null; const hideResults = () => { results.hidden = true; results.innerHTML = ''; }; const renderResults = (items) => { results.innerHTML = ''; if (!items.length) { const empty = document.createElement('div'); empty.className = 'part-search-result'; empty.textContent = 'Ничего не найдено'; results.appendChild(empty); results.hidden = false; return; } items.forEach((item) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'part-search-result'; const title = document.createElement('div'); title.textContent = item.name || [item.sku, item.name].filter(Boolean).join(' · '); button.appendChild(title); const detailsText = renderMeta(item); if (detailsText) { const details = document.createElement('div'); details.className = 'help'; details.textContent = detailsText; button.appendChild(details); } button.addEventListener('click', () => { selectItem(item); hideResults(); }); results.appendChild(button); }); results.hidden = false; }; const search = () => { const query = searchInput.value.trim(); if (!query) { hideResults(); return; } abortController?.abort(); abortController = new AbortController(); fetch(searchInput.dataset.searchUrl + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' }, signal: abortController.signal }).then((response) => response.ok ? response.json() : []).then(renderResults).catch((error) => { if (error.name !== 'AbortError') hideResults(); }); }; searchInput.addEventListener('input', () => { window.clearTimeout(searchTimeout); searchTimeout = window.setTimeout(search, 180); }); document.addEventListener('click', (event) => { if (!event.target.closest('.part-search')) hideResults(); }); document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hideResults(); }); };
            setupSearch('[data-part-search]', '[data-part-results]', (part) => { document.querySelector('[data-part-id]').value = part.id || ''; document.querySelector('[data-part-search]').value = [part.sku, part.name].filter(Boolean).join(' · '); const priceInput = document.querySelector('input[name="unit_price_uah"]'); if (priceInput) priceInput.value = Number(part.unit_price_uah || 0).toFixed(2); }, (part) => [part.source_label, part.model, part.color, part.available_stock ? 'Остаток ' + part.available_stock : null, part.unit_price_uah ? Number(part.unit_price_uah).toFixed(2) + ' грн' : null].filter(Boolean).join(' · '));
            setupSearch('[data-work-search]', '[data-work-results]', (work) => { document.querySelector('[data-work-search]').value = work.name || ''; const priceInput = document.querySelector('input[name="price_uah"]'); if (priceInput) priceInput.value = Number(work.price_uah || 0).toFixed(2); }, (work) => Number(work.price_uah || 0) > 0 ? Number(work.price_uah).toFixed(2) + ' грн' : '');
        })();
    </script>
@endsection
