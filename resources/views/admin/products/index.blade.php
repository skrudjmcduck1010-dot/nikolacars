@extends('layouts.admin', ['heading' => 'Товары'])

@section('content')
    <div class="panel">
        <form method="GET" action="{{ route('admin.products.index') }}" class="form-grid" style="margin-bottom:16px;">
            <div>
                <label for="product-search">Поиск</label>
                <input
                    id="product-search"
                    type="search"
                    name="search"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="Название или артикул"
                    list="product-search-suggestions"
                >
                <datalist id="product-search-suggestions">
                    @foreach($searchSuggestions as $suggestion)
                        <option value="{{ $suggestion['value'] }}" label="{{ $suggestion['label'] }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label for="product-source">Источник</label>
                <select id="product-source" name="source">
                    <option value="" @selected(($filters['source'] ?? '') === '')>Все</option>
                    <option value="donor" @selected(($filters['source'] ?? '') === 'donor')>С донора</option>
                    <option value="purchase" @selected(($filters['source'] ?? '') === 'purchase')>Закупки</option>
                </select>
            </div>
            <div class="full actions">
                <button type="submit">Показать</button>
                <a class="btn btn-secondary" href="{{ route('admin.products.index') }}">Сбросить</a>
                <a class="btn btn-secondary" href="{{ route('admin.deleted-parts.index') }}">Удаленные запчасти</a>
                <a class="btn" href="{{ route('admin.products.create') }}">Добавить товар</a>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Фото</th>
                    <th>Код</th>
                    <th>Артикул товара</th>
                    <th>Название</th>
                    <th>Категория</th>
                    <th>Тип</th>
                    <th>Источник</th>
                    <th>Кол-во на складе</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($products as $product)
                @php
                    $stockQuantity = (int) ($product->stock_quantity ?? 0);
                    $storageStatusLabel = $product->storage_status_label;
                    $storageStatusClass = '';

                    if ($product->storage_status === \App\Models\Product::STORAGE_STATUS_IN_STOCK && $stockQuantity <= 0) {
                        $storageStatusLabel = \App\Models\Product::STORAGE_STATUSES[\App\Models\Product::STORAGE_STATUS_SOLD];
                        $storageStatusClass = 'tag-danger';
                    }

                    $isTeslaOfficialProduct = $product->isTeslaOfficialGenerated();
                @endphp
                <tr>
                    <td>
                        @php($preview = \App\Support\ProductPhotoNormalizer::productPhotos($product)->first())
                        @if($preview)
                            <img class="table-preview" src="{{ \App\Support\PublicStorageUrl::url($preview) }}" alt="Превью {{ $product->name }}">
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                    <td>{{ $product->sku }}</td>
                    <td>{{ $product->external_sku ?: '—' }}</td>
                    <td>
                        <a href="{{ route('admin.products.show', $product) }}">{{ $product->name }}</a>
                        @if($isTeslaOfficialProduct)
                            <div style="margin-top:6px;"><span class="tag" style="background:#111827;color:#fff;">tesla.com</span></div>
                        @endif
                    </td>
                    <td>{{ $product->category->name ?? '—' }}</td>
                    <td>{{ $product->part_origin_label ?: '—' }}</td>
                    <td>
                        @if($product->donorCar)
                            <a href="{{ route('admin.donor-cars.show', $product->donorCar) }}">
                                С донора
                            </a>
                            <div class="help">{{ $product->donorCar->vin }}</div>
                        @elseif($product->has_purchase_items)
                            Закупки
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $stockQuantity }}</td>
                    <td>
                        <span class="{{ trim('tag '.$storageStatusClass) }}">{{ $storageStatusLabel }}</span>
                        @unless($product->is_active)
                            <div style="margin-top:6px;"><span class="tag tag-archived">Неактивен</span></div>
                        @endunless
                    </td>
                    <td class="actions">
                        @if($isTeslaOfficialProduct)
                            <a class="btn btn-secondary" href="{{ route('admin.products.edit', $product) }}">Изменить</a>
                        @endif
                        @if($isTeslaOfficialProduct)
                            <span class="tag" style="background:#111827;color:#fff;">tesla.com</span>
                        @else
                        <a class="btn btn-secondary" href="{{ route('admin.products.edit', $product) }}">Изменить</a>
                        <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="inline-form" onsubmit='return confirm(@json("Удалить товар {$product->name}?"));'>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">Удалить</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="empty">Товары не найдены.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $products->links() }}
        </div>
    </div>
@endsection
