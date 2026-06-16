@extends('layouts.admin', ['heading' => $reservation->exists ? 'Редактировать резерв' : 'Новый резерв'])

@section('content')
    <form method="POST" action="{{ $reservation->exists ? route('admin.reservations.update', $reservation) : route('admin.reservations.store') }}" class="panel">
        @csrf
        @if($reservation->exists) @method('PUT') @endif
        <div class="form-grid">
            <div
                class="async-autocomplete"
                data-async-autocomplete
                data-reservation-autocomplete="product"
                data-search-url="{{ route('admin.reservations.products.search') }}"
            >
                <label>Товар</label>
                <input type="hidden" name="product_id" value="{{ old('product_id', $selectedProduct['id'] ?? '') }}" data-autocomplete-id>
                <input
                    type="search"
                    name="product_label"
                    value="{{ old('product_label', $selectedProduct['label'] ?? '') }}"
                    placeholder="Название, SKU или артикул"
                    autocomplete="off"
                    data-autocomplete-input
                >
                <div class="async-autocomplete-meta help" data-autocomplete-meta>{{ $selectedProduct['meta'] ?? '' }}</div>
                <div class="async-autocomplete-results" data-autocomplete-results hidden></div>
            </div>

            <div
                class="async-autocomplete"
                data-async-autocomplete
                data-reservation-autocomplete="stock"
                data-search-url="{{ route('admin.reservations.stock-items.search') }}"
            >
                <label>Остаток</label>
                <input type="hidden" name="stock_item_id" value="{{ old('stock_item_id', $selectedStockItem['id'] ?? '') }}" data-autocomplete-id>
                <input
                    type="search"
                    name="stock_item_label"
                    value="{{ old('stock_item_label', $selectedStockItem['label'] ?? '') }}"
                    placeholder="Название, SKU, артикул, склад или ячейка"
                    autocomplete="off"
                    data-autocomplete-input
                >
                <div class="async-autocomplete-meta help" data-autocomplete-meta>{{ $selectedStockItem['meta'] ?? '' }}</div>
                <div class="async-autocomplete-results" data-autocomplete-results hidden></div>
            </div>

            <div><label>Количество</label><input type="number" name="quantity" value="{{ old('quantity', $reservation->quantity) }}" required></div>
            <div><label>Статус</label><select name="status">@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $reservation->status ?: 'active') === $status)>{{ ['active' => 'Активен', 'released' => 'Снят', 'fulfilled' => 'Исполнен', 'cancelled' => 'Отменен'][$status] }}</option>@endforeach</select></div>
            <div><label>ID заказа клиента</label><input name="customer_order_id" value="{{ old('customer_order_id', $reservation->customer_order_id) }}"></div>
            <div><label>Действует до</label><input type="datetime-local" name="expires_at" value="{{ old('expires_at', optional($reservation->expires_at)->format('Y-m-d\TH:i')) }}"></div>
            <div class="full"><label>Комментарий</label><textarea name="comment">{{ old('comment', $reservation->comment) }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>

    @include('admin.shared.async_autocomplete')

    <script>
        (() => {
            const productRoot = document.querySelector('[data-reservation-autocomplete="product"]');

            const applyProductFromStock = (option) => {
                if (!productRoot || !option.product_id) return;

                productRoot.querySelector('[data-autocomplete-id]').value = option.product_id || '';
                productRoot.querySelector('[data-autocomplete-input]').value = option.product_label || '';
                productRoot.querySelector('[data-autocomplete-meta]').textContent = option.product_meta || '';
            };

            document.querySelector('[data-reservation-autocomplete="stock"]')?.addEventListener('async-autocomplete:selected', (event) => {
                applyProductFromStock(event.detail.option || {});
            });
        })();
    </script>
@endsection
