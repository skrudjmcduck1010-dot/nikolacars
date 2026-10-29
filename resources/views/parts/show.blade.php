@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
  $isSold = ($product['availability_status'] ?? '') === 'sold';
  $catalogUrl = $isRu ? '/ru/parts/' : '/parts/';
  $images = collect($product['images'] ?? [])->filter()->values();
  if ($images->isEmpty() && !empty($product['image_url'])) $images = collect([$product['image_url']]);
  $imageItems = collect($product['image_items'] ?? [])
    ->filter(fn ($item) => is_array($item) && !empty($item['url']))
    ->values();
  if ($imageItems->isEmpty()) {
    $imageItems = $images->map(fn ($image) => [
      'url' => $image,
      'thumbnail_url' => $image,
      'thumbnail_width' => 360,
      'thumbnail_height' => 300,
      'variants' => [],
    ]);
  }
  if ($isSold) {
    $images = $images->take(1);
    $imageItems = $imageItems->take(1);
    $product['images'] = $images->all();
    $product['image_items'] = $imageItems->all();
  }
  $mainImageItem = $imageItems->first() ?? [];
  $mainVariants = collect($mainImageItem['variants'] ?? [])->filter(fn ($variant) => !empty($variant['url']) && !empty($variant['width']));
  $mainDisplayVariant = $mainVariants->sortByDesc('width')->first();
  $mainDisplayUrl = $mainDisplayVariant['url'] ?? ($mainImageItem['url'] ?? $images->first());
  $mainSrcset = $mainVariants->map(fn ($variant) => $variant['url'].' '.$variant['width'].'w')->implode(', ');
  $similarProducts = collect($product['similar_products'] ?? []);
  $subcategoryProducts = collect($product['subcategory_products'] ?? []);
  $productModel = trim((string) ($product['model'] ?? ''));
  $productModel = $productModel !== '' && !str_starts_with(mb_strtolower($productModel), 'tesla ')
    ? 'Tesla '.$productModel
    : $productModel;
  $productHeading = collect([
    $product['name'] ?? '',
    $productModel,
    $product['part_number'] ?? '',
  ])->map(fn ($value) => trim((string) $value))->filter()->unique()->implode(' — ');
  $categoryBreadcrumbs = collect($product['category_breadcrumbs'] ?? [])->filter(fn ($breadcrumb) =>
    is_array($breadcrumb) && !empty($breadcrumb['label']) && !empty($breadcrumb['slug'])
  )->values();
  if ($categoryBreadcrumbs->isEmpty() && !empty($product['category_slug'])) {
    $categoryBreadcrumbs = collect([[
      'label' => $product['category'] ?? '',
      'slug' => $product['category_slug'],
    ]]);
  }
  $categoryBreadcrumbUrl = static function (array $breadcrumb, int $index) use ($catalogUrl, $product): string {
    $modelSlug = trim((string) ($product['model_slug'] ?? ''));
    $slug = trim((string) ($breadcrumb['slug'] ?? ''));
    $path = \App\Services\CatalogPath::preferred($slug, $modelSlug);
    if ($index === 0) {
      return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/'.$slug.'/' : $catalogUrl.$slug.'/';
    }
    return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/'.$path.'/' : $catalogUrl.$path.'/';
  };
  $categoryBreadcrumbs = $categoryBreadcrumbs
    ->unique(fn ($breadcrumb, $index) => $categoryBreadcrumbUrl($breadcrumb, $index))
    ->values();
@endphp
<main class="product-page">
  <div class="parts-container">
    <nav class="product-breadcrumbs">
      <a class="breadcrumb-desktop-only" href="{{ $catalogUrl }}">{{ $isRu ? 'Запчасти' : 'Запчастини' }}</a>
      <span class="breadcrumb-desktop-only">›</span>
      <a href="{{ $catalogUrl.($product['model_slug'] ?? '').'/' }}">{{ $product['model'] ?? '' }}</a>
      @foreach($categoryBreadcrumbs as $categoryBreadcrumb)
        <span @if(!$loop->first && !$loop->last) class="breadcrumb-desktop-only" @endif>›</span>
        <a @if(!$loop->first && !$loop->last) class="breadcrumb-desktop-only" @endif href="{{ $categoryBreadcrumbUrl($categoryBreadcrumb, $loop->index) }}">{{ $categoryBreadcrumb['label'] }}</a>
      @endforeach
      <span class="breadcrumb-desktop-only">›</span>
      <span class="breadcrumb-desktop-only">{{ $product['name'] }}</span>
    </nav>

    @include('parts._product_details')

    @if($isSold)
      @include('parts._sold_recommendations')
    @endif

    @if(!$isSold && $similarProducts->isNotEmpty())
      <section class="product-recommendations">
        <div class="product-recommendations-heading">
          <h2>{{ $isSold ? ($isRu ? 'Похожие запчасти в наличии' : 'Схожі запчастини в наявності') : ($isRu ? 'Похожие товары' : 'Схожі товари') }}</h2>
          @if($isSold)<p>{{ $isRu ? 'Совместимость с вашим автомобилем уточняйте по VIN.' : 'Сумісність із вашим автомобілем уточнюйте за VIN.' }}</p>@endif
        </div>
        @include('parts._recommendation_cards', ['products' => $similarProducts])
      </section>
    @endif

    @if($subcategoryProducts->isNotEmpty() && (!$isSold || $similarProducts->isNotEmpty()))
      <section class="product-recommendations">
        <div class="product-recommendations-heading">
          <h2>{{ $isSold ? ($isRu ? 'Другие запчасти в наличии' : 'Інші запчастини в наявності') : ($isRu ? 'Другие товары из подкатегории' : 'Інші товари з підкатегорії') }}</h2>
          <p>{{ $product['category_path'] ?? '' }}</p>
        </div>
        <div class="product-recommendations-carousel" data-recommendations-carousel>
          <button type="button" class="product-carousel-arrow product-carousel-prev" data-carousel-prev aria-label="{{ $isRu ? 'Предыдущие товары' : 'Попередні товари' }}" hidden>‹</button>
          @include('parts._recommendation_cards', ['products' => $subcategoryProducts, 'carousel' => true])
          <button type="button" class="product-carousel-arrow product-carousel-next" data-carousel-next aria-label="{{ $isRu ? 'Следующие товары' : 'Наступні товари' }}" hidden>›</button>
        </div>
      </section>
    @endif
    @if($isSold)
      <details class="product-sold-archive">
        <summary>{{ $isRu ? 'Описание, доставка и возврат' : 'Опис, доставка та повернення' }}</summary>
        @if(!empty($product['description']))
          <section class="product-description"><h2>{{ $isRu ? 'Описание' : 'Опис' }}</h2><p>{{ $product['description'] }}</p></section>
        @endif
        @include('parts._delivery_and_returns')
      </details>
    @endif
  </div>
</main>

@if($images->isNotEmpty())
  <div class="product-lightbox{{ $isSold ? ' is-sold' : '' }}" data-product-lightbox role="dialog" aria-modal="true" aria-label="{{ $isRu ? 'Галерея фотографий товара' : 'Галерея фотографій товару' }}" aria-hidden="true" hidden>
    <button type="button" class="product-lightbox-backdrop" data-lightbox-close tabindex="-1" aria-label="{{ $isRu ? 'Закрыть галерею' : 'Закрити галерею' }}"></button>
    <button type="button" class="product-lightbox-close" data-lightbox-close aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button>
    @if($images->count() > 1)
      <button type="button" class="product-lightbox-arrow product-lightbox-prev" data-lightbox-prev aria-label="{{ $isRu ? 'Предыдущее фото' : 'Попереднє фото' }}">‹</button>
      <button type="button" class="product-lightbox-arrow product-lightbox-next" data-lightbox-next aria-label="{{ $isRu ? 'Следующее фото' : 'Наступне фото' }}">›</button>
    @endif
    <div class="product-lightbox-stage" data-lightbox-stage>
      <img alt="{{ $product['name'] }}" decoding="async" data-lightbox-image>
      @if($images->count() > 1)<div class="product-lightbox-counter" data-lightbox-counter>1 / {{ $images->count() }}</div>@endif
    </div>
  </div>
@endif

<script type="application/json" id="productData">@json($product)</script>
@endsection

@push('scripts')
<script>window.productPageConfig = @json(['catalogUrl' => $catalogUrl]);</script>
<script src="{{ asset('assets/js/parts-product.js') }}?v=20261012-mobile-heading" defer></script>
@endpush
