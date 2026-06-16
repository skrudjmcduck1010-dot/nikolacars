@extends('layouts.admin', ['heading' => 'Мелочевка '.$donorCar->display_vin])

@section('content')
    @php
        $donorPartPresenter = $donorPartPresenter ?? app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
        $categoryLabel = function ($product) use ($donorPartPresenter): string {
            $catalogCategory = $donorPartPresenter->categoryForDonor($product->donorCar, $product->sourcePartCatalogItem);
            $catalogPath = $donorPartPresenter->categoryPath($catalogCategory, 'preferred', true);
            $rawPath = $donorPartPresenter->catalogRawCategoryPath($product->sourcePartCatalogItem);

            return $catalogPath !== ''
                ? $catalogPath
                : ($rawPath !== '' ? $rawPath : ($product->category?->name ?: '-'));
        };
        $quantityText = function ($product): string {
            $quantity = round((float) $product->stockItems->sum('quantity'), 3);

            return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') ?: '0';
        };
        $officialTeslaCatalogPricesByProductId = $officialTeslaCatalogPricesByProductId ?? collect();
        $usdRate = $usdRate ?? app(\App\Services\ExchangeRateService::class)->displayUsdRate();
        $exchangeRateService = app(\App\Services\ExchangeRateService::class);
        $sort = $sort ?? 'external_sku';
        $direction = $direction ?? 'asc';
        $sortUrl = fn (string $field): string => route('admin.donor-cars.small-parts.index', [
            'donorCar' => $donorCar,
            'sort' => $field,
            'direction' => $sort === $field && $direction === 'asc' ? 'desc' : 'asc',
        ]);
        $sortMark = fn (string $field): string => $sort === $field ? ($direction === 'asc' ? ' ^' : ' v') : '';
        $teslaPriceText = function ($product) use ($officialTeslaCatalogPricesByProductId, $exchangeRateService, $usdRate): array {
            $row = $officialTeslaCatalogPricesByProductId->get((int) $product->id);
            $price = $row['price_amount'] ?? null;

            if ($price === null) {
                return ['usd' => '-', 'uah' => null];
            }

            $currency = $row['currency'] ?? 'USD';
            $uah = $exchangeRateService->productSellingPriceUahRoundedToTen((float) $price, $currency ?: 'USD', $usdRate);

            return [
                'usd' => number_format((float) $price, 2, '.', ' ').' '.($currency ?: 'USD'),
                'uah' => number_format($uah, 0, '.', ' ').' грн',
            ];
        };
    @endphp

    <div class="panel">
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <h2 class="section-title" style="margin-top:0;">Мелочевка {{ $donorCar->display_vin }}</h2>
                <div class="help">Всего позиций: {{ $products->count() }}</div>
            </div>
            <a class="btn btn-secondary" href="{{ route('admin.donor-cars.show', $donorCar) }}">Назад к донору</a>
        </div>

        <table style="margin-top:14px;">
            <thead>
            <tr>
                <th><a href="{{ $sortUrl('external_sku') }}">Артикул{{ $sortMark('external_sku') }}</a></th>
                <th><a href="{{ $sortUrl('name') }}">Запчасть{{ $sortMark('name') }}</a></th>
                <th><a href="{{ $sortUrl('category') }}">Категория{{ $sortMark('category') }}</a></th>
                <th><a href="{{ $sortUrl('tesla_price') }}">Цена tesla.com{{ $sortMark('tesla_price') }}</a></th>
                <th><a href="{{ $sortUrl('price') }}">Цена{{ $sortMark('price') }}</a></th>
                <th><a href="{{ $sortUrl('quantity') }}">Кол-во{{ $sortMark('quantity') }}</a></th>
                <th><a href="{{ $sortUrl('warehouse') }}">Склад{{ $sortMark('warehouse') }}</a></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($products as $product)
                @php($stockItem = $product->stockItems->first())
                @php($teslaPrice = $teslaPriceText($product))
                <tr>
                    <td>{{ $product->external_sku ?: '-' }}</td>
                    <td>
                        <a href="{{ route('admin.products.show', $product) }}">{{ $product->sourcePartCatalogItem?->name_ua ?: $product->sourcePartCatalogItem?->name_ru ?: $product->name }}</a>
                        @if($product->sourcePartCatalogItem?->name_en)
                            <div class="help">{{ $product->sourcePartCatalogItem->name_en }}</div>
                        @endif
                    </td>
                    <td>{{ $categoryLabel($product) }}</td>
                    <td>
                        {{ $teslaPrice['uah'] ?? $teslaPrice['usd'] }}
                        @if($teslaPrice['uah'])
                            <div class="help">{{ $teslaPrice['usd'] }}</div>
                        @endif
                    </td>
                    <td>{{ $product->selling_price !== null ? number_format((float) $product->selling_price, 2, '.', ' ').' '.($product->currency ?: 'USD') : '-' }}</td>
                    <td>{{ $quantityText($product) }}</td>
                    <td>{{ $stockItem?->warehouse?->name ?? $product->storage_status_label }}</td>
                    <td class="actions">
                        <form method="POST" action="{{ route('admin.donor-cars.products.small-part.destroy', [$donorCar, $product]) }}" class="inline-form">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="donor-product-small-part-icon donor-product-small-part-icon--remove" title="Убрать из мелочевки" aria-label="Убрать {{ $product->name }} из мелочевки">&minus;</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="empty">Мелочевка по этому донору пока не добавлена.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <style>
        .donor-product-small-part-icon {
            width: 28px;
            height: 28px;
            padding: 0;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 800;
            line-height: 1;
        }

        .donor-product-small-part-icon--remove {
            background: #fee2e2;
            color: #991b1b;
        }

        .donor-product-small-part-icon--remove:hover,
        .donor-product-small-part-icon--remove:focus {
            background: #fecaca;
            color: #7f1d1d;
        }
    </style>
@endsection
