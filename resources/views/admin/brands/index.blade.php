@extends('layouts.admin', ['heading' => 'Бренды'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;"><a class="btn" href="{{ route('admin.brands.create') }}">Добавить бренд</a></div>
        <table>
            <thead><tr><th>Название</th><th>Slug</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            @forelse($brands as $brand)
                <tr>
                    <td><a href="{{ route('admin.brands.show', $brand) }}">{{ $brand->name }}</a></td>
                    <td>{{ $brand->slug }}</td>
                    <td>{{ $brand->is_active ? 'Активен' : 'Отключен' }}</td>
                    <td><a class="btn btn-secondary" href="{{ route('admin.brands.edit', $brand) }}">Изменить</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Бренды пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
