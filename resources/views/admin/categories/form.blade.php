@extends('layouts.admin', ['heading' => $category->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="panel">
        @csrf
        @if($category->exists) @method('PUT') @endif
        <div class="form-grid">
            <div><label>Название</label><input name="name" value="{{ old('name', $category->name) }}" required></div>
            <div><label>Slug</label><input name="slug" value="{{ old('slug', $category->slug) }}" required></div>
            <div><label>Порядок сортировки</label><input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}"></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $category->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активна</label></div>
            <div class="full"><label>Описание</label><textarea name="description">{{ old('description', $category->description) }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>
@endsection
