@extends('layouts.admin', ['heading' => $warehouse->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $warehouse->exists ? route('admin.warehouses.update', $warehouse) : route('admin.warehouses.store') }}" class="panel">
        @csrf
        @if($warehouse->exists) @method('PUT') @endif
        <div class="form-grid">
            <div><label>Кол-во этажей</label><input type="number" name="floor_count" min="1" max="20" value="{{ old('floor_count', $warehouse->floor_count ?? 1) }}" required></div>
            <div><label>Название</label><input name="name" value="{{ old('name', $warehouse->name) }}" required></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $warehouse->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активен</label></div>
        </div>
        <div class="actions" style="margin-top:20px;">
            <button type="submit">Сохранить</button>
        </div>
    </form>
@endsection
