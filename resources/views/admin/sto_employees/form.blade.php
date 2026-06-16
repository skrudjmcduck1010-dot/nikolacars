@extends('layouts.admin', [
    'heading' => $employee->exists ? ' ' : ' ',
    'subheading' => $employee->cash_employee_name ?: 'Добавление сотрудника СТО',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
@endphp

@section('content')
    <form method="POST" action="{{ $employee->exists ? route('admin.sto-employees.update', $employee) : route('admin.sto-employees.store') }}" class="panel">
        @csrf
        @if ($employee->exists)
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="cash_employee_name">Имя в кассе</label>
                <input id="cash_employee_name" name="cash_employee_name" value="{{ old('cash_employee_name', $employee->cash_employee_name) }}" required>
                <div class="help" style="margin-top:6px;">При изменении имя обновится во всех операциях кассы.</div>
            </div>
            <div>
                <label for="position">Должность</label>
                <input id="position" name="position" value="{{ old('position', $employee->position) }}">
            </div>
            <div>
                <label for="user_id">Аккаунт доступа</label>
                <select id="user_id" name="user_id">
                    <option value="">Без аккаунта</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((int) old('user_id', $employee->user_id) === $user->id)>
                            {{ $user->name }} · {{ $user->email }}{{ $user->is_active ? '' : ' · отключен' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rate">Ставка</label>
                <input id="rate" type="number" step="0.01" min="0" name="rate" value="{{ old('rate', $employee->rate) }}">
            </div>
            <div>
                <label for="bonus_calculation"> </label>
                <select id="bonus_calculation" name="bonus_calculation">
                    <option value="">Без бонусной схемы</option>
                    @foreach ($bonusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('bonus_calculation', $employee->bonus_calculation) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="start_date">Дата начала работы</label>
                <input id="start_date" type="date" name="start_date" value="{{ old('start_date', optional($employee->start_date)->format('Y-m-d')) }}">
            </div>
            <div style="display:flex;align-items:end;">
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:0;">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $employee->is_active)) style="width:auto;">
                    Сейчас работает
                </label>
            </div>
        </div>

        @if ($employee->exists)
            <div class="grid grid-4" style="margin-top:18px;">
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;background:white;">
                    <div class="help">Всего ЗП грн</div>
                    <div class="stat">{{ $money($summary?->salary_uah ?? 0) }}</div>
                </div>
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;background:white;">
                    <div class="help">Всего ЗП $</div>
                    <div class="stat">{{ $money($summary?->salary_usd ?? 0) }}</div>
                </div>
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;background:white;">
                    <div class="help">Операций</div>
                    <div class="stat">{{ $summary?->transactions_count ?? 0 }}</div>
                </div>
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;background:white;">
                    <div class="help">Последняя ЗП</div>
                    <div class="stat" style="font-size:22px;">{{ $summary?->latest_operation_date ? \Illuminate\Support\Carbon::parse($summary->latest_operation_date)->format('d.m.Y') : '—' }}</div>
                </div>
            </div>
        @endif

        <div class="actions" style="margin-top:20px;">
            <button type="submit">Сохранить</button>
            <a class="btn btn-secondary" href="{{ route('admin.sto-employees.index') }}">Назад</a>
        </div>
    </form>
@endsection
