@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
  $catalogUrl = $isRu ? '/ru/parts/' : '/parts/';
  $images = collect($product['images'] ?? [])->filter()->values();
  if ($images->isEmpty() && !empty($product['image_url'])) $images = collect([$product['image_url']]);
@endphp
<main class="product-page">
  <div class="parts-container">
    <nav class="product-breadcrumbs">
      <a href="{{ $catalogUrl }}">{{ $isRu ? 'Каталог' : 'Каталог' }}</a>
      <span>›</span>
      <a href="{{ $catalogUrl.($product['model_slug'] ?? '').'/' }}">{{ $product['model'] ?? '' }}</a>
      @if(!empty($product['category_slug']))
        <span>›</span>
        <a href="{{ $catalogUrl.($product['model_slug'] ?? '').'/'.$product['category_slug'].'/' }}">{{ $product['category'] ?? '' }}</a>
      @endif
      <span>›</span>
      <span>{{ $product['name'] }}</span>
    </nav>

    <div class="product-detail">
      <section class="product-gallery">
        <div class="product-main-image {{ $images->isEmpty() ? 'no-image' : '' }}">
          @if($images->isNotEmpty())
            <img src="{{ $images->first() }}" alt="{{ $product['name'] }}" data-product-main-image>
          @else
            <span>NIKOLACARS</span>
          @endif
        </div>
        @if($images->count() > 1)
          <div class="product-thumbnails">
            @foreach($images as $image)
              <button type="button" class="{{ $loop->first ? 'active' : '' }}" data-product-image="{{ $image }}"><img src="{{ $image }}" alt=""></button>
            @endforeach
          </div>
        @endif
      </section>

      <section class="product-info">
        <div class="product-model">{{ $product['model'] ?? '' }}</div>
        <h1>{{ $product['name'] }}</h1>
        <div class="product-code-line">{{ collect([$product['part_number'] ?? null, $product['sku'] ?? null, $product['vin'] ?? null])->filter()->implode(' · ') }}</div>
        <div class="product-category-label">{{ $product['category_path'] ?? '' }}</div>

        <div class="product-buy-box">
          <div class="product-detail-price">{{ number_format((float) $product['price_uah'], 0, '.', ' ') }} грн</div>
          <div class="product-detail-stock">{{ $isRu ? 'В наличии' : 'В наявності' }}: {{ $product['quantity'] }}</div>
          <button type="button" data-product-add>{{ $isRu ? 'Добавить в корзину' : 'Додати в кошик' }}</button>
        </div>

        <dl class="product-specs">
          @if(!empty($product['part_number']))<div><dt>{{ $isRu ? 'Артикул' : 'Артикул' }}</dt><dd>{{ $product['part_number'] }}</dd></div>@endif
          @if(!empty($product['sku']))<div><dt>{{ $isRu ? 'Код склада' : 'Код складу' }}</dt><dd>{{ $product['sku'] }}</dd></div>@endif
          @if(!empty($product['vin']))<div><dt>VIN</dt><dd>{{ $product['vin'] }}</dd></div>@endif
          @if(!empty($product['color']))<div><dt>{{ $isRu ? 'Цвет' : 'Колір' }}</dt><dd>{{ $product['color'] }}</dd></div>@endif
          @if(!empty($product['compatibility']))<div><dt>{{ $isRu ? 'Совместимость' : 'Сумісність' }}</dt><dd>{{ $product['compatibility'] }}</dd></div>@endif
        </dl>

        @if(!empty($product['description']))
          <div class="product-description"><h2>{{ $isRu ? 'Описание' : 'Опис' }}</h2><p>{{ $product['description'] }}</p></div>
        @endif
      </section>
    </div>
  </div>
</main>

<script type="application/json" id="productData">@json($product)</script>
@endsection

@push('scripts')
<script>window.productPageConfig = @json(['catalogUrl' => $catalogUrl]);</script>
<script src="{{ asset('assets/js/parts-product.js') }}?v=1" defer></script>
@endpush
