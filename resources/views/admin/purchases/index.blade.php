@extends('layouts.admin', ['heading' => 'Закупки', 'subheading' => 'Товары, купленные через форму "Сделать закупку"'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.purchases.create') }}">Сделать закупку</a>
        </div>
        <table>
            <thead>
            <tr>
                <th>Фото</th>
                <th>Дата</th>
                <th>Артикул</th>
                <th>Товар</th>
                <th>Tesla</th>
                <th>Склад</th>
                <th>Ячейка</th>
                <th>Приход</th>
                <th>Закуп</th>
                <th>Продажа</th>
                <th>Сумма</th>
                <th>Остаток</th>
                <th>Документ</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($purchaseItems as $item)
                <tr>
                    <td>
                        @php($preview = $item->product ? \App\Support\ProductPhotoNormalizer::productPhotos($item->product)->first() : null)
                        @if ($preview)
                            <img class="table-preview" src="{{ \App\Support\PublicStorageUrl::url($preview) }}" alt="Превью {{ $item->product->name }}">
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                    <td><a href="{{ route('admin.purchases.show', $item->purchase) }}">{{ $item->purchase->purchase_date?->format('d.m.Y') }}</a></td>
                    <td>{{ $item->product?->external_sku ?: ($item->product?->sku ?: '—') }}</td>
                    <td>
                        @if ($item->product)
                            <a href="{{ route('admin.products.show', $item->product) }}">{{ $item->product->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $item->product?->model ?: '—' }}</td>
                    <td>{{ $item->warehouse->name ?? '—' }}</td>
                    <td>{{ $item->location->full_code ?? '—' }}</td>
                    <td>{{ $item->quantity }} шт</td>
                    <td>{{ number_format((float) $item->purchase_price, 2, ',', ' ') }} {{ $item->currency }}</td>
                    <td>{{ number_format((float) $item->selling_price, 2, ',', ' ') }} USD</td>
                    <td>{{ number_format((float) $item->purchase_price * (int) $item->quantity, 2, ',', ' ') }} {{ $item->currency }}</td>
                    <td>
                        @if ($item->stockItem)
                            <a href="{{ route('admin.stock-items.show', $item->stockItem) }}">
                                доступно {{ $item->stockItem->available_quantity }} / всего {{ $item->stockItem->quantity }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <a class="btn btn-small btn-secondary" href="{{ route('admin.purchases.show', $item->purchase) }}">Открыть</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="13" class="empty">Товары через закупку пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:14px;">{{ $purchaseItems->links() }}</div>
    </div>
@endsection
