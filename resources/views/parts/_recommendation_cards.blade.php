<div class="{{ !empty($carousel) ? 'product-recommendations-track' : 'product-recommendations-grid' }}" @if(!empty($carousel)) data-recommendations-track @endif>
  @foreach($products as $item)
    @php
      $cardImage = $item['thumbnail_url'] ?? $item['image_url'] ?? null;
      $productUrl = rtrim($catalogUrl, '/').'/'.$item['id'].'/';
    @endphp
    <article class="part-card">
      <a href="{{ $productUrl }}" class="part-image {{ empty($cardImage) ? 'no-image' : '' }}">
        @if(!empty($cardImage))
          <img src="{{ $cardImage }}" alt="{{ $item['name'] }}" loading="lazy" decoding="async">
        @endif
        <span>NIKOLACARS</span>
      </a>
      <div class="part-card-body">
        <h3><a href="{{ $productUrl }}">{{ $item['name'] }}</a></h3>
        <div class="part-codes">{{ collect([$item['part_number'] ?? null, $item['sku'] ?? null])->filter()->implode(' · ') }}</div>
        <div class="part-price">{{ number_format((float) $item['price_uah'], 0, '.', ' ') }} грн</div>
        <div class="part-stock">{{ $isRu ? 'В наличии' : 'В наявності' }}: {{ $item['quantity'] }}</div>
        <button
          type="button"
          class="add-cart"
          data-recommendation-add="{{ $item['id'] }}"
          data-default-label="{{ $isRu ? 'В корзину' : 'У кошик' }}"
          data-added-label="{{ $isRu ? 'В корзине' : 'У кошику' }}"
        >{{ $isRu ? 'В корзину' : 'У кошик' }}</button>
      </div>
    </article>
  @endforeach
</div>
