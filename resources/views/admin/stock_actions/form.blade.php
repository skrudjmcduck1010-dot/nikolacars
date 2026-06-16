@extends('layouts.admin', ['heading' => [
    'intake' => 'Операция: приемка',
    'move' => 'Операция: перемещение',
    'reserve' => 'Операция: резерв',
    'unreserve' => 'Операция: снятие резерва',
    'sale' => 'Операция: продажа',
    'writeoff' => 'Операция: списание',
    'adjustment' => 'Операция: корректировка',
][$type], 'subheading' => 'Создает движение и безопасно обновляет остатки'])

@section('content')
    <form method="POST" action="{{ route('admin.actions.store') }}" class="panel">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <div class="form-grid">
            @if($type === 'intake')
                <div
                    class="full async-autocomplete"
                    data-async-autocomplete
                    data-search-url="{{ route('admin.actions.products.search') }}"
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
                <div><label>Склад</label><select name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
                <div><label>Ячейка</label><select name="location_id">@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>{{ $location->full_code }}</option>@endforeach</select></div>
                <div><label>Количество</label><input type="number" name="quantity" value="{{ old('quantity', 1) }}"></div>
            @else
                <div
                    class="full async-autocomplete"
                    data-async-autocomplete
                    data-search-url="{{ route('admin.stock-items.search') }}"
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
            @endif

            @if(in_array($type, ['move', 'reserve', 'unreserve', 'sale', 'writeoff'], true))
                <div><label>Количество</label><input type="number" name="quantity" value="{{ old('quantity', 1) }}"></div>
            @endif

            @if($type === 'move')
                <div><label>В ячейку</label><select name="to_location_id">@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('to_location_id') == $location->id)>{{ $location->full_code }}</option>@endforeach</select></div>
            @endif

            @if($type === 'adjustment')
                <div><label>Итоговое количество</label><input type="number" name="target_quantity" value="{{ old('target_quantity', 0) }}"></div>
            @endif

            <div><label>Контрагент</label><select name="counterparty_id"><option value="">-</option>@foreach($counterparties as $counterparty)<option value="{{ $counterparty->id }}" @selected(old('counterparty_id') == $counterparty->id)>{{ $counterparty->name }}</option>@endforeach</select></div>
            <div><label>Номер документа</label><input name="document_number" value="{{ old('document_number') }}"></div>
            <div><label>Причина</label><input name="reason" value="{{ old('reason') }}"></div>
            <div><label>ID заказа клиента</label><input name="customer_order_id" value="{{ old('customer_order_id') }}"></div>
            <div><label>Срок резерва</label><input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></div>
            <div class="full"><label>Комментарий</label><textarea name="comment">{{ old('comment') }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;">
            <button type="submit">Выполнить операцию</button>
        </div>
    </form>

    @include('admin.shared.async_autocomplete')
@endsection
