@extends('layouts.admin', ['heading' => 'Категории'])

@section('content')
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Slug</th>
                    <th>Товаров</th>
                    <th>Порядок</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($categoryRows as $row)
                @php($category = $row['category'])
                <tr class="{{ $row['depth'] === 0 ? 'category-row-root' : 'category-row-child' }}">
                    <td>
                        <div class="category-tree-name" style="--category-depth: {{ $row['depth'] }};">
                            <a href="{{ route('admin.categories.show', $category) }}">{{ $row['title'] }}</a>
                        </div>
                        @if($category->description)
                            <div class="muted category-tree-description" style="--category-depth: {{ $row['depth'] }};">{{ $category->description }}</div>
                        @endif
                    </td>
                    <td>{{ $category->slug }}</td>
                    <td>{{ $category->products_count }}</td>
                    <td>{{ $category->sort_order }}</td>
                    <td>{{ $category->is_active ? 'Активна' : 'Отключена' }}</td>
                    <td><a class="btn btn-secondary" href="{{ route('admin.categories.edit', $category) }}">Изменить</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Категории пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <style>
        .category-tree-name,
        .category-tree-description {
            padding-left: calc(var(--category-depth) * 28px);
        }
        .category-tree-name {
            align-items: center;
            display: flex;
            min-height: 28px;
        }
        .category-row-root .category-tree-name a {
            font-weight: 700;
        }
        .category-row-child .category-tree-name::before {
            border-bottom: 1px solid var(--line);
            border-left: 1px solid var(--line);
            content: "";
            display: inline-block;
            height: 14px;
            margin-right: 10px;
            width: 14px;
        }
    </style>
@endsection
