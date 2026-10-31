<section class="product-info">
  <h1 class="product-heading">{{ $productHeading }}</h1>
  <div class="product-code-line">{{ collect([$product['part_number'] ?? null, $product['sku'] ?? null, $product['vin'] ?? null])->filter()->implode(' · ') }}</div>
  <div class="product-category-label">{{ $product['category_path'] ?? '' }}</div>

  @if(!$isSold)
  <div class="product-buy-box">
    @php
      $hasPrice = (float) ($product['price_uah'] ?? 0) > 0;
      $hasStock = (int) ($product['quantity'] ?? 0) > 0;
    @endphp
    @if(!$isSold)
      <div class="product-detail-price{{ $hasPrice ? '' : ' product-detail-price-on-request' }}">
        @if($hasPrice)
          {{ number_format((float) $product['price_uah'], 0, '.', ' ') }} грн
        @else
          <strong>{{ $isRu ? 'Цену уточняйте по телефону' : 'Ціну уточнюйте за телефоном' }}</strong>
          <span><a href="tel:+380501753084">(050) 175 30 84</a></span>
        @endif
      </div>
    @endif
    <div class="product-detail-stock{{ $isSold ? ' is-sold' : '' }}">{{ $isSold ? 'Продано' : ($hasStock ? (($isRu ? 'В наличии' : 'В наявності').': '.$product['quantity']) : ($isRu ? 'Временно нет в наличии' : 'Тимчасово немає в наявності')) }}</div>
    @if(!$isSold && $hasPrice && $hasStock)<button
      type="button"
      data-product-add
      data-added-label="{{ $isRu ? 'В корзине' : 'У кошику' }}"
      aria-pressed="false"
    >{{ $isRu ? 'Добавить в корзину' : 'Додати в кошик' }}</button>@endif
    @if(!$hasPrice)
      <a class="product-call-button" href="tel:+380501753084">{{ $isRu ? 'Позвонить' : 'Зателефонувати' }}</a>
    @endif
  </div>
  @endif

  @if($isSold)
    <details class="product-sold-specifications" data-sold-specifications open>
      <summary>{{ $isRu ? 'Характеристики запчасти' : 'Характеристики запчастини' }}</summary>
      <div>
  @endif
  <dl class="product-specs">
    @foreach(['length_cm' => ['Длина', 'Довжина'], 'width_cm' => ['Ширина', 'Ширина'], 'height_cm' => ['Высота', 'Висота']] as $dimension => $labels)
      @if(is_numeric($product[$dimension] ?? null) && (float) $product[$dimension] > 0)
        <div><dt>{{ $labels[$isRu ? 0 : 1] }}</dt><dd>{{ (int) $product[$dimension] }} см</dd></div>
      @endif
    @endforeach
    @if(!empty($product['part_number']))<div><dt>{{ $isRu ? 'Артикул' : 'Артикул' }}</dt><dd>{{ $product['part_number'] }}</dd></div>@endif
    @if(!empty($product['sku']))<div><dt>{{ $isRu ? 'Код склада' : 'Код складу' }}</dt><dd>{{ $product['sku'] }}</dd></div>@endif
    @if(!empty($product['condition']))<div><dt>{{ $isRu ? 'Состояние' : 'Стан' }}</dt><dd>{{ $product['condition'] }}</dd></div>@endif
    @if(!empty($product['part_origin']))<div><dt>{{ $isRu ? 'Тип запчасти' : 'Тип запчастини' }}</dt><dd>{{ $product['part_origin'] }}</dd></div>@endif
    @if(!empty($product['damage_status']))
      <div><dt>{{ $isRu ? 'Повреждения' : 'Пошкодження' }}</dt><dd>{{ $product['damage_status'] }}@if(!empty($product['damage_description']))<span class="product-spec-note">{{ $product['damage_description'] }}</span>@endif</dd></div>
    @endif
    @if(!empty($product['vin']))<div><dt>VIN</dt><dd>{{ $product['vin'] }}</dd></div>@endif
    @if(!empty($product['model']))<div><dt>{{ $isRu ? 'Модель Тесла' : 'Модель Тесла' }}</dt><dd>{{ $product['model'] }}</dd></div>@endif
    @if(!empty($product['color']))<div><dt>{{ $isRu ? 'Цвет' : 'Колір' }}</dt><dd>{{ $product['color'] }}</dd></div>@endif
    @if(!empty($product['compatibility']))
      <div><dt>{{ $isRu ? 'Совместимость' : 'Сумісність' }}</dt><dd class="product-compatibility">
        @foreach(preg_split('/\s*[,;\r\n]+\s*/u', (string) $product['compatibility'], -1, PREG_SPLIT_NO_EMPTY) as $compatibleModel)
          <span>{{ trim($compatibleModel) }}</span>
        @endforeach
      </dd></div>
    @endif
  </dl>
  @if(collect(['length_cm', 'width_cm', 'height_cm'])->contains(fn ($field) => is_numeric($product[$field] ?? null) && (float) $product[$field] > 0))
    <p class="product-spec-note">{{ $isRu ? 'Указаны размеры самой запчасти без упаковки.' : 'Вказано розміри самої запчастини без пакування.' }}</p>
  @endif

  @if($isSold)
      </div>
    </details>
    <div class="product-buy-box product-sold-status">
      <div class="product-detail-stock is-sold">Продано</div>
    </div>
  @endif
</section>
