@extends('layouts.admin', ['heading' => $counterparty->name])

@section('content')
    <div class="panel" style="margin-bottom:18px;">
        <div class="help">{{ $counterparty->type_label }} · {{ $counterparty->phone }} · {{ $counterparty->email }}</div>
        <p>{{ $counterparty->address }}</p>
    </div>

    @php($hasPrimaryVehicle = $counterparty->car_model || $counterparty->car_year || $counterparty->drive_type || $counterparty->vin || $counterparty->license_plate)

    <div class="panel" style="margin-bottom:18px;">
        <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h2 style="margin:0;">Машины клиента</h2>
        </div>

        @if($hasPrimaryVehicle || $counterparty->vehicles->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th>Тип</th>
                        <th>Модель</th>
                        <th>Год</th>
                        <th>Привод</th>
                        <th>VIN</th>
                        <th>ГосНомер</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @if($hasPrimaryVehicle)
                        <tr>
                            <td><span class="tag">Основная</span></td>
                            <td>{{ $counterparty->car_model ?: '—' }}</td>
                            <td>{{ $counterparty->car_year ?: '—' }}</td>
                            <td>{{ $counterparty->drive_type_label ?: '—' }}</td>
                            <td>{{ $counterparty->vin ?: '—' }}</td>
                            <td>{{ $counterparty->license_plate ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.counterparties.vehicles.primary.destroy', $counterparty) }}" class="inline-form" onsubmit="return confirm('Удалить основную машину клиента?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-small btn-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @endif

                    @foreach($counterparty->vehicles as $vehicle)
                        <tr>
                            <td><span class="tag tag-warning">Дополнительная</span></td>
                            <td>{{ $vehicle->car_model }}</td>
                            <td>{{ $vehicle->car_year }}</td>
                            <td>{{ $vehicle->drive_type_label ?: '—' }}</td>
                            <td>{{ $vehicle->vin }}</td>
                            <td>{{ $vehicle->license_plate }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.counterparties.vehicles.destroy', [$counterparty, $vehicle]) }}" class="inline-form" onsubmit="return confirm('Удалить машину клиента?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-small btn-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">Машин пока нет.</div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.counterparties.vehicles.store', $counterparty) }}" class="panel">
        @csrf
        <h2 style="margin-top:0;">Добавить машину</h2>

        <div class="form-grid">
            <div>
                <label>Модель машины</label>
                @php($selectedModel = old('car_model'))
                <select name="car_model" required>
                    <option value="">—</option>
                    @foreach($models as $model)
                        <option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>
                    @endforeach
                    @if($selectedModel && ! in_array($selectedModel, $models, true))
                        <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                    @endif
                </select>
                @error('car_model')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Год</label>
                <input type="number" name="car_year" min="1990" max="{{ now()->year + 1 }}" value="{{ old('car_year') }}" required>
                @error('car_year')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Привод</label>
                <select name="drive_type" required>
                    <option value="">—</option>
                    @foreach(\App\Models\Counterparty::DRIVE_TYPES as $driveType)
                        <option value="{{ $driveType }}" @selected(old('drive_type') === $driveType)>{{ \App\Models\Counterparty::DRIVE_TYPE_LABELS[$driveType] }}</option>
                    @endforeach
                </select>
                @error('drive_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>VIN</label>
                <input name="vin" value="{{ old('vin') }}" required>
                @error('vin')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>ГосНомер</label>
                <input name="license_plate" value="{{ old('license_plate') }}" required>
                @error('license_plate')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="actions" style="margin-top:20px;">
            <button type="submit">Добавить машину</button>
        </div>
    </form>
@endsection
