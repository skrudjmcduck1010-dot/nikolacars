@extends('layouts.admin', ['heading' => $brand->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $brand->exists ? route('admin.brands.update', $brand) : route('admin.brands.store') }}" class="panel">
        @csrf
        @if($brand->exists) @method('PUT') @endif
        <div class="form-grid">
            <div><label>Название</label><input name="name" value="{{ old('name', $brand->name) }}" required></div>
            <div><label>Slug</label><input name="slug" value="{{ old('slug', $brand->slug) }}" required></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $brand->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активен</label></div>
            <div class="full"><label>Описание</label><textarea name="description">{{ old('description', $brand->description) }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>
@endsection
