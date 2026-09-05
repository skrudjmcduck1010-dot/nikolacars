<div class="{{ !empty($carousel) ? 'product-recommendations-track' : 'product-recommendations-grid' }}" @if(!empty($carousel)) data-recommendations-track @endif>
  @foreach($products as $item)
    @php
      $cardImage = $item['thumbnail_url'] ?? $item['image_url'] ?? null;
      $productUrl = rtrim($catalogUrl, '/').'/'.$item['id'].'/';
    @endphp
    <article class="part-card">
      <a href="{{ $productUrl }}" class="part-image {{ empty($cardImage) ? 'no-image' : '' }}">
        @if(!empty($cardImage))
          <img src="{{ $cardImage }}" alt="{{ $item['name'] }}" width="{{ $item['thumbnail_width'] ?? 360 }}" height="{{ $item['thumbnail_height'] ?? 300 }}" loading="lazy" decoding="async">
        @endif
        <span>NIKOLACARS</span>
      </a>
      <div class="part-card-body">
        <h3><a href="{{ $productUrl }}">{{ $item['name'] }}</a></h3>
        <div class="part-codes">{{ collect([$item['part_number'] ?? null, $item['sku'] ?? null])->filter()->implode(' · ') }}</div>
        <div class="part-purchase-row">
          <div class="part-purchase-info">
            <div class="part-price">{{ number_format((float) $item['price_uah'], 0, '.', ' ') }} грн</div>
            <div class="part-stock">{{ $isRu ? 'В наличии' : 'В наявності' }}: {{ $item['quantity'] }}</div>
          </div>
          <button
            type="button"
            class="add-cart"
            data-recommendation-add="{{ $item['id'] }}"
            data-default-label="{{ $isRu ? 'В корзину' : 'У кошик' }}"
            data-added-label="{{ $isRu ? 'В корзине' : 'У кошику' }}"
            aria-label="{{ $isRu ? 'В корзину' : 'У кошик' }}"
            title="{{ $isRu ? 'В корзину' : 'У кошик' }}"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 7H6"></path>
              <circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle>
            </svg>
          </button>
        </div>
      </div>
    </article>
  @endforeach
</div>
