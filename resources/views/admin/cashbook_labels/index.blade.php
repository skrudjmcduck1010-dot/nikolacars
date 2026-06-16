@extends('layouts.admin', [
    'heading' => 'Настройки меток',
    'subheading' => 'Справочник меток для кассы и месячных отчетов.',
])

@section('content')
    <div class="grid grid-2">
        <form method="POST" action="{{ route('admin.cashbook-labels.store') }}" class="panel">
            @csrf
            <h2 style="margin-top:0;">Добавить метку</h2>
            <div>
                <label for="name">Название</label>
                <input id="name" name="name" value="{{ old('name') }}" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div style="margin-top:14px;">
                <label for="operation_type">Операция</label>
                <select id="operation_type" name="operation_type" required>
                    @foreach ($operationTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('operation_type', 'income') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('operation_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div style="margin-top:14px;">
                <label for="parent_id"> </label>
                <select id="parent_id" name="parent_id">
                    <option value="">Без родителя</option>
                    @foreach ($parentOptions as $parentOption)
                        <option value="{{ $parentOption->id }}" @selected((string) old('parent_id') === (string) $parentOption->id)>{{ $parentOption->name }}</option>
                    @endforeach
                </select>
                @error('parent_id')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="actions" style="margin-top:16px;">
                <button type="submit">Добавить</button>
                <a class="btn btn-secondary" href="{{ route('admin.cashbook.index') }}">К кассе</a>
            </div>
        </form>

        <div class="panel">
            <h2 style="margin-top:0;">Как работает</h2>
            <div class="help">
                Переименование метки меняет ее во всех операциях кассы. Удаление убирает метку из списка выбора, операции при этом не удаляются.
            </div>
        </div>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">Текущие метки</h2>
        <table>
            <thead>
            <tr>
                <th>Название</th>
                <th>Операция</th>
                <th></th>
                <th>Операций</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($labels as $label)
                <tr>
                    <td>
                        <form id="label-form-{{ $label->id }}" method="POST" action="{{ route('admin.cashbook-labels.update', $label) }}">
                            @csrf
                            @method('PUT')
                            <input name="name" value="{{ $label->name }}" required>
                        </form>
                    </td>
                    <td>
                        <select name="operation_type" form="label-form-{{ $label->id }}" required>
                            @foreach ($operationTypes as $value => $typeLabel)
                                <option value="{{ $value }}" @selected($label->operation_type === $value)>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select name="parent_id" form="label-form-{{ $label->id }}">
                            <option value="">Без родителя</option>
                            @foreach ($parentOptions as $parentOption)
                                @continue($parentOption->id === $label->id)
                                <option value="{{ $parentOption->id }}" @selected($label->parent_id === $parentOption->id)>{{ $parentOption->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>{{ $usage[$label->name] ?? 0 }}</td>
                    <td class="actions">
                        <button type="submit" form="label-form-{{ $label->id }}" class="btn-small">Сохранить</button>
                        <form method="POST" action="{{ route('admin.cashbook-labels.destroy', $label) }}" class="inline-form" onsubmit="return confirm('Удалить метку из списка выбора?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-small btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Меток пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
