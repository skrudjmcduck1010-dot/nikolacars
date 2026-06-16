@extends('layouts.admin', ['heading' => $category->name])

@section('content')
    <div class="panel">
        <div class="help">{{ $category->description }}</div>
        <h2>Товары</h2>
        <table>
            <thead><tr><th>SKU</th><th>Название</th></tr></thead>
            <tbody>
            @forelse($category->products as $product)
                <tr><td>{{ $product->sku }}</td><td>{{ $product->name }}</td></tr>
            @empty
                <tr><td colspan="2" class="empty">В этой категории пока нет товаров.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
