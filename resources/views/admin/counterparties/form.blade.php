@extends('layouts.admin', ['heading' => $counterparty->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $counterparty->exists ? route('admin.counterparties.update', $counterparty) : route('admin.counterparties.store') }}" class="panel">
        @csrf
        @if($counterparty->exists) @method('PUT') @endif
        <div class="form-grid">
            <div>
                <label>Тип</label>
                <select name="type" data-counterparty-type>
                    @foreach(\App\Models\Counterparty::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('type', $counterparty->type ?: 'supplier') === $type)>{{ \App\Models\Counterparty::TYPE_LABELS[$type] }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Имя + Фамилия / Название</label><input name="name" value="{{ old('name', $counterparty->name) }}" required></div>
            <div><label>Номер телефона</label><input name="phone" value="{{ old('phone', $counterparty->phone) }}" data-sto-client-required></div>
            <div><label>E-mail</label><input name="email" value="{{ old('email', $counterparty->email) }}"></div>
            <div>
                <label>Модель машины</label>
                @php($selectedModel = old('car_model', $counterparty->car_model))
                <select name="car_model" data-sto-client-required>
                    <option value="">—</option>
                    @foreach($models as $model)
                        <option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>
                    @endforeach
                    @if($selectedModel && ! in_array($selectedModel, $models, true))
                        <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                    @endif
                </select>
            </div>
            <div><label>Год</label><input type="number" name="car_year" min="1990" max="{{ now()->year + 1 }}" value="{{ old('car_year', $counterparty->car_year) }}" data-sto-client-required></div>
            <div>
                <label>Привод</label>
                <select name="drive_type" data-sto-client-required>
                    <option value="">—</option>
                    @foreach(\App\Models\Counterparty::DRIVE_TYPES as $driveType)
                        <option value="{{ $driveType }}" @selected(old('drive_type', $counterparty->drive_type) === $driveType)>{{ \App\Models\Counterparty::DRIVE_TYPE_LABELS[$driveType] }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>VIN</label><input name="vin" value="{{ old('vin', $counterparty->vin) }}" data-sto-client-required></div>
            <div><label>ГосНомер</label><input name="license_plate" value="{{ old('license_plate', $counterparty->license_plate) }}" data-sto-client-required></div>
            <div class="full"><label>Адрес</label><input name="address" value="{{ old('address', $counterparty->address) }}"></div>
            <div class="full"><label>Примечания</label><textarea name="notes">{{ old('notes', $counterparty->notes) }}</textarea></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $counterparty->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активен</label></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>

    <script>
        const typeSelect = document.querySelector('[data-counterparty-type]');
        const stoClientFields = document.querySelectorAll('[data-sto-client-required]');
        const syncStoClientFields = () => {
            const isStoClient = ['customer', 'both'].includes(typeSelect.value);

            stoClientFields.forEach((field) => {
                field.required = isStoClient;
            });
        };

        typeSelect.addEventListener('change', syncStoClientFields);
        syncStoClientFields();
    </script>
@endsection
