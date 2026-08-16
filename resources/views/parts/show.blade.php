@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
  $catalogUrl = $isRu ? '/ru/parts/' : '/parts/';
  $images = collect($product['images'] ?? [])->filter()->values();
  if ($images->isEmpty() && !empty($product['image_url'])) $images = collect([$product['image_url']]);
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
    if ($index === 0) {
      return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/'.$slug.'/' : $catalogUrl.'category/'.$slug.'/';
    }
    return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/subcategory/'.$slug.'/' : $catalogUrl.'subcategory/'.$slug.'/';
  };
@endphp
<main class="product-page">
  <div class="parts-container">
    <nav class="product-breadcrumbs">
      <a href="{{ $catalogUrl }}">{{ $isRu ? 'Запчасти' : 'Запчастини' }}</a>
      <span>›</span>
      <a href="{{ $catalogUrl.($product['model_slug'] ?? '').'/' }}">{{ $product['model'] ?? '' }}</a>
      @foreach($categoryBreadcrumbs as $categoryBreadcrumb)
        <span>›</span>
        <a href="{{ $categoryBreadcrumbUrl($categoryBreadcrumb, $loop->index) }}">{{ $categoryBreadcrumb['label'] }}</a>
      @endforeach
      <span>›</span>
      <span>{{ $product['name'] }}</span>
    </nav>

    <div class="product-detail">
      <div class="product-visual">
        <section class="product-gallery">
        @if($images->isNotEmpty())
          <button type="button" class="product-main-image" data-gallery-open aria-label="{{ $isRu ? 'Открыть фото в полном размере' : 'Відкрити фото у повному розмірі' }}">
            <img src="{{ $images->first() }}" alt="{{ $product['name'] }}" data-product-main-image>
          </button>
        @else
          <div class="product-main-image no-image"><span>NIKOLACARS</span></div>
        @endif
        @if($images->count() > 1)
          <div class="product-thumbnails">
            @foreach($images as $image)
              <button type="button" class="{{ $loop->first ? 'active' : '' }}" data-product-image="{{ $image }}" data-product-image-index="{{ $loop->index }}"><img src="{{ $image }}" alt=""></button>
            @endforeach
          </div>
        @endif
        </section>

        @if(!empty($product['description']))
          <section class="product-description"><h2>{{ $isRu ? 'Описание' : 'Опис' }}</h2><p>{{ $product['description'] }}</p></section>
        @endif
      </div>

      <section class="product-info">
        <div class="product-model">{{ $product['model'] ?? '' }}</div>
        <h1>{{ $productHeading }}</h1>
        <div class="product-code-line">{{ collect([$product['part_number'] ?? null, $product['sku'] ?? null, $product['vin'] ?? null])->filter()->implode(' · ') }}</div>
        <div class="product-category-label">{{ $product['category_path'] ?? '' }}</div>

        <div class="product-buy-box">
          <div class="product-detail-price">{{ number_format((float) $product['price_uah'], 0, '.', ' ') }} грн</div>
          <div class="product-detail-stock">{{ $isRu ? 'В наличии' : 'В наявності' }}: {{ $product['quantity'] }}</div>
          <button
            type="button"
            data-product-add
            data-added-label="{{ $isRu ? 'В корзине' : 'У кошику' }}"
            aria-pressed="false"
          >{{ $isRu ? 'Добавить в корзину' : 'Додати в кошик' }}</button>
        </div>

        <dl class="product-specs">
          @if(!empty($product['part_number']))<div><dt>{{ $isRu ? 'Артикул' : 'Артикул' }}</dt><dd>{{ $product['part_number'] }}</dd></div>@endif
          @if(!empty($product['sku']))<div><dt>{{ $isRu ? 'Код склада' : 'Код складу' }}</dt><dd>{{ $product['sku'] }}</dd></div>@endif
          @if(!empty($product['condition']))<div><dt>{{ $isRu ? 'Состояние' : 'Стан' }}</dt><dd>{{ $product['condition'] }}</dd></div>@endif
          @if(!empty($product['part_origin']))<div><dt>{{ $isRu ? 'Тип запчасти' : 'Тип запчастини' }}</dt><dd>{{ $product['part_origin'] }}</dd></div>@endif
          @if(!empty($product['damage_status']))
            <div><dt>{{ $isRu ? 'Повреждения' : 'Пошкодження' }}</dt><dd>{{ $product['damage_status'] }}@if(!empty($product['damage_description']))<span class="product-spec-note">{{ $product['damage_description'] }}</span>@endif</dd></div>
          @endif
          @if(!empty($product['vin']))<div><dt>VIN</dt><dd>{{ $product['vin'] }}</dd></div>@endif
          @if(!empty($product['color']))<div><dt>{{ $isRu ? 'Цвет' : 'Колір' }}</dt><dd>{{ $product['color'] }}</dd></div>@endif
          @if(!empty($product['compatibility']))<div><dt>{{ $isRu ? 'Совместимость' : 'Сумісність' }}</dt><dd>{{ $product['compatibility'] }}</dd></div>@endif
        </dl>

      </section>
    </div>

    @if($similarProducts->isNotEmpty())
      <section class="product-recommendations">
        <div class="product-recommendations-heading">
          <h2>{{ $isRu ? 'Похожие товары' : 'Схожі товари' }}</h2>
        </div>
        @include('parts._recommendation_cards', ['products' => $similarProducts])
      </section>
    @endif

    @if($subcategoryProducts->isNotEmpty())
      <section class="product-recommendations">
        <div class="product-recommendations-heading">
          <h2>{{ $isRu ? 'Другие товары из подкатегории' : 'Інші товари з підкатегорії' }}</h2>
          <p>{{ $product['category_path'] ?? '' }}</p>
        </div>
        <div class="product-recommendations-carousel" data-recommendations-carousel>
          <button type="button" class="product-carousel-arrow product-carousel-prev" data-carousel-prev aria-label="{{ $isRu ? 'Предыдущие товары' : 'Попередні товари' }}" hidden>‹</button>
          @include('parts._recommendation_cards', ['products' => $subcategoryProducts, 'carousel' => true])
          <button type="button" class="product-carousel-arrow product-carousel-next" data-carousel-next aria-label="{{ $isRu ? 'Следующие товары' : 'Наступні товари' }}" hidden>›</button>
        </div>
      </section>
    @endif
  </div>
</main>

@if($images->isNotEmpty())
  <div class="product-lightbox" data-product-lightbox role="dialog" aria-modal="true" aria-label="{{ $isRu ? 'Галерея фотографий товара' : 'Галерея фотографій товару' }}" aria-hidden="true" hidden>
    <button type="button" class="product-lightbox-backdrop" data-lightbox-close tabindex="-1" aria-label="{{ $isRu ? 'Закрыть галерею' : 'Закрити галерею' }}"></button>
    <button type="button" class="product-lightbox-close" data-lightbox-close aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button>
    @if($images->count() > 1)
      <button type="button" class="product-lightbox-arrow product-lightbox-prev" data-lightbox-prev aria-label="{{ $isRu ? 'Предыдущее фото' : 'Попереднє фото' }}">‹</button>
      <button type="button" class="product-lightbox-arrow product-lightbox-next" data-lightbox-next aria-label="{{ $isRu ? 'Следующее фото' : 'Наступне фото' }}">›</button>
    @endif
    <div class="product-lightbox-stage" data-lightbox-stage>
      <img src="{{ $images->first() }}" alt="{{ $product['name'] }}" data-lightbox-image>
      @if($images->count() > 1)<div class="product-lightbox-counter" data-lightbox-counter>1 / {{ $images->count() }}</div>@endif
    </div>
  </div>
@endif

<script type="application/json" id="productData">@json($product)</script>
@endsection

@push('scripts')
<script>window.productPageConfig = @json(['catalogUrl' => $catalogUrl]);</script>
<script src="{{ asset('assets/js/parts-product.js') }}?v=8" defer></script>
@endpush
