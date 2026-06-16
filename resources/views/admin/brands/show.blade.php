@extends('layouts.admin', ['heading' => $brand->name])

@section('content')
    <div class="panel">
        <div class="help">{{ $brand->description }}</div>
        <h2>Товары</h2>
        <table>
            <thead><tr><th>SKU</th><th>Название</th></tr></thead>
            <tbody>
            @forelse($brand->products as $product)
                <tr><td>{{ $product->sku }}</td><td>{{ $product->name }}</td></tr>
            @empty
                <tr><td colspan="2" class="empty">Для этого бренда товаров пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
