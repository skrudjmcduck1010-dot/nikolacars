@extends('layouts.admin', ['heading' => 'Новый заказ-наряд'])

@section('content')
    @php
        $selectedAppointmentTime = old('appointment_time', $order->appointment_time ? \Illuminate\Support\Str::of($order->appointment_time)->substr(0, 5)->toString() : null);
        $kyivNow = now('Europe/Kyiv');
        $selectedOpenedAt = old('opened_at', $order->opened_at?->toDateString() ?? $kyivNow->toDateString());
        $appointmentTimeOptions = collect(range(0, 40))->map(fn (int $slot): string => \Illuminate\Support\Carbon::createFromTime(9, 0)->addMinutes($slot * 15)->format('H:i'));
    @endphp

    <style>
        .client-search { position: relative; }
        .client-search-results { position:absolute; z-index:40; top:calc(100% + 6px); left:0; right:0; max-height:320px; overflow:auto; border:1px solid var(--line); border-radius:12px; background:white; box-shadow:0 12px 30px rgba(15,23,42,.14); }
        .client-search-result { display:block; width:100%; border:0; border-radius:0; padding:10px 12px; background:white; color:var(--text); text-align:left; font-weight:500; }
        .client-search-result:hover, .client-search-result:focus { background:#f2f6f5; }
        .client-search-result .help { margin-top:3px; }
    </style>

    <form method="POST" action="{{ route('admin.sto-work-orders.store') }}" class="panel">
        @csrf
        <input type="hidden" name="counterparty_id" value="{{ old('counterparty_id') }}" data-client-id>
        <div class="form-grid">
            <div>
                <label>Статус</label>
                @if ($calendarAppointmentMode)
                    <input type="hidden" name="calendar_appointment" value="1">
                    <input type="hidden" name="status" value="{{ \App\Models\StoWorkOrder::STATUS_APPOINTMENT }}" data-order-status>
                    <input value="{{ \App\Models\StoWorkOrder::STATUS_LABELS[\App\Models\StoWorkOrder::STATUS_APPOINTMENT] }}" disabled>
                @else
                    <select name="status" data-order-status>
                        @foreach(\App\Models\StoWorkOrder::STATUS_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $order->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div><label>Дата записи</label><input type="date" name="opened_at" value="{{ $selectedOpenedAt }}" min="{{ $kyivNow->toDateString() }}" data-opened-at required></div>
            <div data-appointment-only><label>Время записи</label><select name="appointment_time" data-appointment-time data-current-date="{{ $kyivNow->toDateString() }}" data-current-time="{{ $kyivNow->format('H:i') }}"><option value="">—</option>@foreach ($appointmentTimeOptions as $appointmentTimeOption)<option value="{{ $appointmentTimeOption }}" @selected($selectedAppointmentTime === $appointmentTimeOption)>{{ $appointmentTimeOption }}</option>@endforeach @if ($selectedAppointmentTime && ! $appointmentTimeOptions->contains($selectedAppointmentTime))<option value="{{ $selectedAppointmentTime }}" selected disabled>{{ $selectedAppointmentTime }}</option>@endif</select></div>
            <div>
                <label>Поиск клиента</label>
                <div class="client-search">
                    <input type="search" data-client-search data-search-url="{{ route('admin.sto-work-orders.clients.search') }}" value="{{ optional($clients->firstWhere('id', (int) old('counterparty_id')))->name }}" placeholder="Введите имя, телефон, авто, VIN или номер" autocomplete="off">
                    <div class="client-search-results" data-client-results hidden></div>
                </div>
            </div>
            <div><label>Имя клиента</label><input name="client_name" value="{{ old('client_name', $order->client_name) }}" data-client-name required></div>
            <div><label>Телефон</label><input name="client_phone" value="{{ old('client_phone', $order->client_phone) }}" data-client-phone></div>
            <div><label>Модель авто</label><select name="car_model" data-car-model>@php($selectedModel = old('car_model', $order->car_model))<option value="">—</option>@foreach($models as $model)<option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>@endforeach @if($selectedModel && ! in_array($selectedModel, $models, true))<option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>@endif</select></div>
            <div><label>Год</label><input type="number" name="car_year" min="1990" max="{{ now()->year + 1 }}" value="{{ old('car_year', $order->car_year) }}" data-car-year></div>
            <div data-appointment-hidden><label>Привод</label><select name="drive_type" data-drive-type><option value="">—</option>@foreach(\App\Models\Counterparty::DRIVE_TYPES as $driveType)<option value="{{ $driveType }}" @selected(old('drive_type', $order->drive_type) === $driveType)>{{ \App\Models\Counterparty::DRIVE_TYPE_LABELS[$driveType] }}</option>@endforeach</select></div>
            <div data-appointment-hidden><label>VIN</label><input name="vin" value="{{ old('vin', $order->vin) }}" data-vin></div>
            <div><label>Госномер</label><input name="license_plate" value="{{ old('license_plate', $order->license_plate) }}" data-license-plate></div>
            <div data-appointment-hidden><label>Пробег</label><input type="number" name="mileage" min="0" value="{{ old('mileage', $order->mileage) }}"></div>
            <div><label>Плановая дата выдачи</label><input type="date" name="planned_finished_at" value="{{ old('planned_finished_at', $order->planned_finished_at?->toDateString()) }}"></div>
            <div class="full"><label>Заявка клиента</label><textarea name="customer_request" placeholder="Что нужно сделать">{{ old('customer_request', $order->customer_request) }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button><a class="btn btn-secondary" href="{{ route('admin.sto-work-orders.index') }}">Отмена</a></div>
    </form>

    <script>
        (() => {
            const searchInput = document.querySelector('[data-client-search]');
            const results = document.querySelector('[data-client-results]');
            const clientIdInput = document.querySelector('[data-client-id]');
            const statusInput = document.querySelector('[data-order-status]');
            const appointmentOnlyFields = document.querySelectorAll('[data-appointment-only]');
            const appointmentHiddenFields = document.querySelectorAll('[data-appointment-hidden]');
            const fields = { name: document.querySelector('[data-client-name]'), phone: document.querySelector('[data-client-phone]'), carModel: document.querySelector('[data-car-model]'), carYear: document.querySelector('[data-car-year]'), driveType: document.querySelector('[data-drive-type]'), vin: document.querySelector('[data-vin]'), licensePlate: document.querySelector('[data-license-plate]') };
            let searchTimeout = null;
            let abortController = null;
            const setSelectValue = (select, value) => { if (!select || value === null || value === undefined || value === '') return; if (!Array.from(select.options).some((option) => option.value === String(value))) select.add(new Option(String(value), String(value))); select.value = value; };
            const setAppointmentMode = () => { const isAppointment = statusInput?.value === 'appointment'; appointmentOnlyFields.forEach((el) => el.hidden = !isAppointment); appointmentHiddenFields.forEach((el) => el.hidden = isAppointment); };
            const hideResults = () => { results.hidden = true; results.innerHTML = ''; };
            const selectClient = (client) => { clientIdInput.value = client.id || ''; fields.name.value = client.name || ''; fields.phone.value = client.phone || ''; setSelectValue(fields.carModel, client.car_model); fields.carYear.value = client.car_year || ''; setSelectValue(fields.driveType, client.drive_type); fields.vin.value = client.vin || ''; fields.licensePlate.value = client.license_plate || ''; searchInput.value = client.name || ''; hideResults(); };
            const renderResults = (clients) => { results.innerHTML = ''; if (!clients.length) { const empty = document.createElement('div'); empty.className = 'client-search-result'; empty.textContent = 'Клиенты не найдены'; results.appendChild(empty); results.hidden = false; return; } clients.forEach((client) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'client-search-result'; const title = document.createElement('div'); title.textContent = client.name || ''; button.appendChild(title); const details = document.createElement('div'); details.className = 'help'; details.textContent = [client.phone, client.car_model, client.license_plate ? '№ ' + client.license_plate : null].filter(Boolean).join(' · '); button.appendChild(details); button.addEventListener('click', () => selectClient(client)); results.appendChild(button); }); results.hidden = false; };
            const searchClients = () => { const query = searchInput.value.trim(); clientIdInput.value = ''; if (!query) { hideResults(); return; } abortController?.abort(); abortController = new AbortController(); fetch(searchInput.dataset.searchUrl + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' }, signal: abortController.signal }).then((response) => response.ok ? response.json() : []).then(renderResults).catch((error) => { if (error.name !== 'AbortError') hideResults(); }); };
            searchInput?.addEventListener('input', () => { window.clearTimeout(searchTimeout); searchTimeout = window.setTimeout(searchClients, 180); });
            statusInput?.addEventListener('change', setAppointmentMode);
            document.addEventListener('click', (event) => { if (!event.target.closest('.client-search')) hideResults(); });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hideResults(); });
            setAppointmentMode();
        })();
    </script>
@endsection
