<div class="product-detail{{ $isSold ? ' is-sold' : '' }}">
  <div class="product-visual">
    <section class="product-gallery">
    @if($images->isNotEmpty())
      <button type="button" class="product-main-image" data-gallery-open aria-label="{{ $isRu ? 'Открыть фото в полном размере' : 'Відкрити фото у повному розмірі' }}">
        <picture data-product-main-picture>
          <img src="{{ $mainDisplayUrl }}"
               @if($mainSrcset !== '') srcset="{{ $mainSrcset }}" sizes="(max-width: 800px) calc(100vw - 32px), 660px" @endif
               alt="{{ $product['name'] }}"
               @if(!empty($mainDisplayVariant['width'])) width="{{ $mainDisplayVariant['width'] }}" height="{{ $mainDisplayVariant['height'] }}" @endif
               decoding="async"
               fetchpriority="high"
               data-product-main-image>
        </picture>
        @if($isSold)<b class="product-sold-badge">Продано</b>@endif
      </button>
    @else
      <div class="product-main-image no-image"><span>NIKOLACARS</span>@if($isSold)<b class="product-sold-badge">Продано</b>@endif</div>
    @endif
    @if($images->count() > 1)
      <div class="product-thumbnails">
        @foreach($imageItems as $imageItem)
          <button type="button" class="{{ $loop->first ? 'active' : '' }}" data-product-image="{{ $imageItem['url'] }}" data-product-image-index="{{ $loop->index }}"><img src="{{ $imageItem['thumbnail_url'] ?? $imageItem['url'] }}" alt="" width="{{ $imageItem['thumbnail_width'] ?? 360 }}" height="{{ $imageItem['thumbnail_height'] ?? 300 }}" loading="lazy" decoding="async"></button>
        @endforeach
      </div>
    @endif
    </section>

    @if(!$isSold && !empty($product['description']))
      <section class="product-description"><h2>{{ $isRu ? 'Описание' : 'Опис' }}</h2><p>{{ $product['description'] }}</p></section>
    @endif
  </div>

  @include('parts._product_info')
</div>

@if(!$isSold)
  @include('parts._delivery_and_returns')
@endif
