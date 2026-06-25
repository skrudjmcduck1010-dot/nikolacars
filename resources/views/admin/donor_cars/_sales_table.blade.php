@php
    $donorPartPresenter = $donorPartPresenter ?? app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
    $saleProductsById = $saleProductsById ?? collect();
    $saleProductsByCatalogItem = $saleProductsByCatalogItem ?? collect();
    $saleSort = $saleSort ?? 'sold_at';
    $saleDirection = $saleDirection ?? 'desc';
    $saleSortUrl = $saleSortUrl ?? fn (string $field): string => route('admin.donor-cars.show', [
        'donorCar' => $donorCar,
        'sale_sort' => $field,
        'sale_direction' => $saleSort === $field && $saleDirection === 'asc' ? 'desc' : 'asc',
    ]).'#sold';
    $saleSortMark = $saleSortMark ?? fn (string $field): string => $saleSort === $field ? ($saleDirection === 'asc' ? ' ^' : ' v') : '';
    $donorProductCategoryOption = fn ($catalogItem = null, ?string $categoryPath = null, ?string $fallbackText = null): array => $donorPartPresenter->desktopCategoryOption($donorCar, $catalogItem, $categoryPath, $fallbackText);
    $donorProductCategoryKey = $donorProductCategoryKey ?? function ($catalogItem = null, ?string $productCategorySlug = null, ?string $categoryPath = null, ?string $fallbackText = null) use ($donorProductCategoryOption): string {
        return $donorProductCategoryOption($catalogItem, $categoryPath, $fallbackText)['key'];
    };
    $readableCategoryPath = $readableCategoryPath ?? fn (?string $value, bool $stripNumericPrefixes = false): string => $donorPartPresenter->readableCategoryPath($value, $stripNumericPrefixes);
@endphp

<div class="help">
    Кол-во: {{ $soldPartsQuantity ?: '0' }}
    @if($soldPartsTotals)
        · Сумма: {{ $soldPartsTotals }}
    @endif
</div>

<table class="donor-products-table donor-sales-table">
    <thead>
    <tr>
        <th><a href="{{ $saleSortUrl('sold_at') }}">Дата{{ $saleSortMark('sold_at') }}</a></th>
        <th><a href="{{ $saleSortUrl('part_number') }}">Артикул{{ $saleSortMark('part_number') }}</a></th>
        <th><a href="{{ $saleSortUrl('name') }}">Название{{ $saleSortMark('name') }}</a></th>
        <th>Категория</th>
        <th><a href="{{ $saleSortUrl('quantity') }}">Кол-во{{ $saleSortMark('quantity') }}</a></th>
        <th><a href="{{ $saleSortUrl('total_amount') }}">Сумма{{ $saleSortMark('total_amount') }}</a></th>
        <th><a href="{{ $saleSortUrl('document_number') }}">Документ{{ $saleSortMark('document_number') }}</a></th>
        <th><a href="{{ $saleSortUrl('counterparty') }}">Контрагент{{ $saleSortMark('counterparty') }}</a></th>
    </tr>
    </thead>
    <tbody>
    @forelse($partSales as $sale)
        @php
            $saleProduct = $donorPartPresenter->resolveSaleProduct($sale, $saleProductsById, $saleProductsByCatalogItem);
            $saleCatalogItem = $saleProduct?->sourcePartCatalogItem ?: $sale->partCatalogItem;
            $categoryOption = $donorProductCategoryOption(
                $saleCatalogItem,
                $saleCatalogItem ? null : $sale->category_path,
                $sale->name
            );
            $categoryKey = $donorProductCategoryKey(
                $saleCatalogItem,
                null,
                $saleCatalogItem ? null : $sale->category_path,
                $sale->name
            );
            $categoryLabel = $categoryOption['label'] ?: $donorPartPresenter->undefinedCategoryLabel();
            $saleName = trim((string) ($sale->name ?: $saleCatalogItem?->name ?: $saleProduct?->name));
            $partNumber = trim((string) ($donorPartPresenter->originalPartNumber($sale) ?: $sale->part_number ?: $saleCatalogItem?->part_number ?: $saleProduct?->external_sku));
            $quantity = $donorPartPresenter->quantity($sale->quantity);
            $amount = $sale->total_amount !== null
                ? $donorPartPresenter->money($sale->total_amount, $sale->currency)
                : $donorPartPresenter->money(((float) $sale->unit_price) * ((float) $sale->quantity), $sale->currency);
        @endphp
        <tr data-donor-product-row data-donor-product-category="{{ $categoryKey }}">
            <td>{{ $sale->sold_at?->format('d.m.Y') ?: '-' }}</td>
            <td>{{ $partNumber ?: '-' }}</td>
            <td>
                @if($saleProduct)
                    <a href="{{ route('admin.products.show', $saleProduct) }}">{{ $saleName ?: $saleProduct->name }}</a>
                @else
                    {{ $saleName ?: '-' }}
                @endif
            </td>
            <td>{{ $readableCategoryPath($categoryLabel, true) ?: $categoryLabel }}</td>
            <td>{{ $quantity ?: '0' }}</td>
            <td>{{ $amount }}</td>
            <td>{{ $sale->document_number ?: '-' }}</td>
            <td>{{ $sale->counterparty ?: '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="empty">Проданных запчастей пока нет.</td></tr>
    @endforelse
    </tbody>
</table>
