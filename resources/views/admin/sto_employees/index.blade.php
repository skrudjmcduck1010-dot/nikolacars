@extends('layouts.admin', [
    'heading' => 'Сотрудники СТО',
    'subheading' => 'Все сотрудники, которые появлялись в кассе с меткой ЗП.',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $defaultDirection = fn ($field) => in_array($field, ['rate', 'salary_uah', 'salary_usd', 'transactions_count', 'latest_operation_date'], true) ? 'desc' : 'asc';
    $sortUrl = fn ($field) => route('admin.sto-employees.index', [
        'sort' => $field,
        'direction' => $sort === $field ? ($direction === 'asc' ? 'desc' : 'asc') : $defaultDirection($field),
        'status' => $status,
    ]);
    $sortMark = fn ($field) => $sort === $field ? ($direction === 'asc' ? ' ^' : ' v') : '';
    [$founderEmployees, $regularEmployees] = $employees->partition(fn ($employee) => mb_strtolower(trim((string) $employee->position)) === 'основатель');
@endphp

@section('content')
    <div class="panel">
        <div class="actions" style="justify-content:space-between;margin-bottom:18px;">
            <div></div>
            <a class="btn" href="{{ route('admin.sto-employees.create') }}">Добавить сотрудника</a>
        </div>

        <form method="GET" class="form-grid" style="margin-bottom:18px;">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <div>
                <label for="status">Статус</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="active" @selected($status === 'active')>Работает</option>
                    <option value="inactive" @selected($status === 'inactive')>Не работает</option>
                    <option value="all" @selected($status === 'all')>Все</option>
                </select>
            </div>
            <div style="display:flex;align-items:end;">
                <button type="submit">Показать</button>
            </div>
        </form>

        <h2 style="margin:0 0 12px;">Сотрудники</h2>
        @include('admin.sto_employees._employees_table', [
            'tableEmployees' => $regularEmployees,
            'emptyMessage' => 'Сотрудников с меткой ЗП пока нет.',
            'showRowNumbers' => true,
        ])

        @if ($founderEmployees->isNotEmpty())
            <div style="margin-top:24px;">
                <h2 style="margin:0 0 12px;">Основатели</h2>
                @include('admin.sto_employees._employees_table', [
                    'tableEmployees' => $founderEmployees,
                    'emptyMessage' => 'Основателей с выбранным статусом нет.',
                    'showRowNumbers' => false,
                ])
            </div>
        @endif
    </div>
@endsection
