@extends('layouts.admin', ['heading' => $stockItem->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $stockItem->exists ? route('admin.stock-items.update', $stockItem) : route('admin.stock-items.store') }}" class="panel">
        @csrf
        @if($stockItem->exists) @method('PUT') @endif
        <div class="form-grid">
            <div><label>Товар</label><select name="product_id">@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id', $stockItem->product_id) == $product->id)>{{ $product->sku }} · {{ $product->name }}</option>@endforeach</select></div>
            <div><label>Склад</label><select name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $stockItem->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            <div><label>Ячейка</label><select name="location_id">@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id', $stockItem->location_id) == $location->id)>{{ $location->full_code }}</option>@endforeach</select></div>
            <div><label>Количество</label><input type="number" name="quantity" value="{{ old('quantity', $stockItem->quantity ?? 0) }}"></div>
            <div><label>Количество в резерве</label><input type="number" name="reserved_quantity" value="{{ old('reserved_quantity', $stockItem->reserved_quantity ?? 0) }}"></div>
            <div><label>Статус проверки</label><select name="testing_status">@foreach($testingStatuses as $status)<option value="{{ $status }}" @selected(old('testing_status', $stockItem->testing_status ?: 'not_tested') === $status)>{{ $status === 'tested' ? 'Проверен' : 'Не проверен' }}</option>@endforeach</select></div>
            <div><label>Дата приемки</label><input type="datetime-local" name="received_at" value="{{ old('received_at', optional($stockItem->received_at)->format('Y-m-d\TH:i')) }}"></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>
@endsection
