@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
  $apiPrefix = $isRu ? '/ru/parts/api' : '/parts/api';
  $catalogBase = $isRu ? '/ru/parts' : '/parts';
  $models = $initialCatalog['models'] ?? [];
  $categories = $initialCatalog['categories'] ?? [];
  $products = $initialCatalog['products'] ?? [];
  $pagination = $initialCatalog['pagination'] ?? [];
  $selection = $initialCatalog['selection'] ?? [];
  $selectedModel = $selection['model'] ?? '';
  $selectedCategory = $selection['category'] ?? '';
  $selectedModelSlug = $selection['model_slug'] ?? '';
  $selectedCategorySlug = $selection['category_slug'] ?? '';
  $currentPage = (int) ($pagination['page'] ?? 1);
  $lastPage = (int) ($pagination['last_page'] ?? 1);
  $sectionUrl = static function (string $model = '', string $category = '') use ($catalogBase): string {
    if ($model !== '' && $category !== '') return $catalogBase.'/'.$model.'/'.$category.'/';
    if ($model !== '') return $catalogBase.'/'.$model.'/';
    if ($category !== '') return $catalogBase.'/category/'.$category.'/';
    return $catalogBase.'/';
  };
  $modelLabelParts = static function (string $label): array {
    $label = trim($label);
    if (preg_match('/^(.*?)(\d{2}\.\d{4}\s*-\s*(?:\d{2}\.\d{4})?)$/u', $label, $matches) !== 1) {
      return [$label, ''];
    }

    return [
      trim($matches[1]),
      preg_replace('/\s*-\s*/u', '-', trim($matches[2])),
    ];
  };
  $pageUrl = static function (int $page) use ($sectionUrl, $selectedModelSlug, $categorySlug): string {
    $url = $sectionUrl($selectedModelSlug, $categorySlug);
    return $page > 1 ? $url.'?page='.$page : $url;
  };
  $pageNumbers = collect([1, $lastPage, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2])
    ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
    ->unique()
    ->sort()
    ->values();
@endphp
<main class="parts-main" id="partsCatalog"
      data-locale="{{ $locale }}"
      data-product-base="{{ $isRu ? '/ru/parts' : '/parts' }}"
      data-catalog-base="{{ $isRu ? '/ru/parts' : '/parts' }}"
      data-model-slug="{{ $modelSlug }}"
      data-category-slug="{{ $categorySlug }}"
      data-catalog-url="{{ $apiPrefix }}/catalog/"
      data-cities-url="{{ $apiPrefix }}/nova-poshta/cities/"
      data-warehouses-url="{{ $apiPrefix }}/nova-poshta/warehouses/"
      data-order-url="{{ $apiPrefix }}/orders">
  <div class="parts-container">
    <div class="parts-title-row">
      <div class="parts-title-copy">
        <h1>{{ $isRu ? 'Запчасти Tesla' : 'Запчастини Tesla' }}</h1>
        <p data-current-context>{{ $isRu ? 'Оригинальные запчасти со склада NikolaCars' : 'Оригінальні запчастини зі складу NikolaCars' }}</p>
      </div>
      <form class="parts-toolbar parts-title-search" id="partsFilterForm" data-filter-form role="search">
        <div class="parts-search-field">
          <input type="search" name="q" value="{{ request('q') }}" autocomplete="off"
                 placeholder="{{ $isRu ? 'Название, Артикул' : 'Назва, Артикул' }}"
                 aria-label="{{ $isRu ? 'Поиск запчастей' : 'Пошук запчастин' }}"
                 aria-autocomplete="list" aria-controls="partsSearchSuggestions" aria-expanded="false"
                 data-search-input>
          <div class="parts-search-suggestions" id="partsSearchSuggestions" data-search-suggestions role="listbox" hidden></div>
        </div>
        <button type="submit" class="parts-search-submit" aria-label="{{ $isRu ? 'Найти' : 'Знайти' }}" title="{{ $isRu ? 'Найти' : 'Знайти' }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="11" cy="11" r="6.5"></circle>
            <path d="m16 16 4.5 4.5"></path>
          </svg>
        </button>
      </form>
    </div>

    <div class="parts-model-tabs" data-models>
      <a href="{{ $sectionUrl('', $selectedCategorySlug) }}" class="parts-model-tab {{ $selectedModel === '' ? 'active' : '' }}"><span class="parts-model-copy"><span class="parts-model-name">{{ $isRu ? 'Все модели' : 'Усі моделі' }}</span></span></a>
      @foreach($models as $model)
        @php [$modelName, $modelYears] = $modelLabelParts($model['label']); @endphp
        <a href="{{ $sectionUrl($model['slug'], $selectedCategorySlug) }}" class="parts-model-tab {{ $selectedModel === $model['value'] ? 'active' : '' }}">
          <span class="parts-model-copy">
            <span class="parts-model-name">{{ $modelName }}</span>
            @if($modelYears !== '')<span class="parts-model-years">{{ $modelYears }}</span>@endif
          </span>
          <span class="parts-model-count">{{ $model['count'] }}</span>
        </a>
      @endforeach
    </div>

    <div class="parts-layout">
      <aside class="parts-sidebar">
        <div class="parts-sidebar-title" data-sidebar-title>{{ $selectedModel ?: ($isRu ? 'Все запчасти' : 'Усі запчастини') }}</div>
        <a href="{{ $sectionUrl($selectedModelSlug) }}" class="parts-category {{ $selectedCategory === '' ? 'active' : '' }}" data-all-parts><span>{{ $isRu ? 'Все запчасти модели' : 'Усі запчастини моделі' }}</span><b data-model-total>{{ collect($categories)->sum('count') }}</b></a>
        <div data-categories>
          @foreach($categories as $category)
            <a href="{{ $sectionUrl($selectedModelSlug, $category['slug']) }}" class="parts-category {{ $selectedCategory === $category['value'] ? 'active' : '' }}"><span>{{ $category['label'] }}</span><b>{{ $category['count'] }}</b></a>
          @endforeach
        </div>
      </aside>

      <section class="parts-results">
        <div class="parts-results-head">
          <h2 data-results-title>{{ $selectedCategory ?: ($selectedModel ?: ($isRu ? 'Все запчасти' : 'Усі запчастини')) }}</h2>
          <div class="parts-results-controls">
            <select class="parts-sort" name="sort" form="partsFilterForm" data-sort aria-label="{{ $isRu ? 'Сортировка' : 'Сортування' }}">
              <option value="newest" @selected(request('sort', 'newest') === 'newest')>{{ $isRu ? 'Сначала новые' : 'Спочатку нові' }}</option>
              <option value="price_asc" @selected(request('sort') === 'price_asc')>{{ $isRu ? 'Цена: по возрастанию' : 'Ціна: за зростанням' }}</option>
              <option value="price_desc" @selected(request('sort') === 'price_desc')>{{ $isRu ? 'Цена: по убыванию' : 'Ціна: за спаданням' }}</option>
              <option value="name" @selected(request('sort') === 'name')>{{ $isRu ? 'По названию' : 'За назвою' }}</option>
            </select>
          </div>
        </div>
        <div class="parts-products" data-products>
          @foreach($products as $product)
            @php $cardImage = $product['thumbnail_url'] ?? $product['image_url'] ?? null; @endphp
            <article class="part-card">
              <a href="{{ $catalogBase.'/'.$product['id'].'/' }}" class="part-image {{ empty($cardImage) ? 'no-image' : '' }}">
                @if(!empty($cardImage))<img src="{{ $cardImage }}" alt="{{ $product['name'] }}" loading="lazy" decoding="async">@endif
                <span>NIKOLACARS</span>
              </a>
              <div class="part-card-body">
                <h3><a href="{{ $catalogBase.'/'.$product['id'].'/' }}">{{ $product['name'] }}</a></h3>
                <div class="part-codes">{{ collect([$product['part_number'] ?? null, $product['sku'] ?? null, $product['vin'] ?? null])->filter()->implode(' · ') }}</div>
                <div class="part-category-path">{{ $product['category_path'] }}</div>
                <div class="part-purchase-row">
                  <div class="part-price">{{ number_format((float) $product['price_uah'], 0, '.', ' ') }} грн</div>
                  <button type="button" class="add-cart" data-add-cart="{{ $product['id'] }}"
                          aria-label="{{ $isRu ? 'В корзину' : 'У кошик' }}" title="{{ $isRu ? 'В корзину' : 'У кошик' }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                      <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 7H6"></path>
                      <circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle>
                    </svg>
                  </button>
                </div>
                <div class="part-stock">{{ $isRu ? 'В наличии' : 'В наявності' }}: {{ $product['quantity'] }}</div>
              </div>
            </article>
          @endforeach
        </div>
        <div class="parts-empty" data-empty @if(count($products)) hidden @endif>{{ $isRu ? 'По выбранным фильтрам запчастей не найдено.' : 'За вибраними фільтрами запчастин не знайдено.' }}</div>
        <button type="button" class="parts-more" data-more @if(($pagination['page'] ?? 1) >= ($pagination['last_page'] ?? 1)) hidden @endif>{{ $isRu ? 'Показать ещё' : 'Показати ще' }}</button>
        <nav class="parts-pagination" data-pagination aria-label="{{ $isRu ? 'Страницы каталога' : 'Сторінки каталогу' }}" @if($lastPage <= 1) hidden @endif>
          @if($currentPage > 1)<a href="{{ $pageUrl($currentPage - 1) }}" rel="prev">← {{ $isRu ? 'Назад' : 'Назад' }}</a>@endif
          @php $previousPageNumber = null; @endphp
          @foreach($pageNumbers as $pageNumber)
            @if($previousPageNumber !== null && $pageNumber > $previousPageNumber + 1)<span class="parts-pagination-gap">…</span>@endif
            @if($pageNumber === $currentPage)
              <span class="active" aria-current="page">{{ $pageNumber }}</span>
            @else
              <a href="{{ $pageUrl($pageNumber) }}">{{ $pageNumber }}</a>
            @endif
            @php $previousPageNumber = $pageNumber; @endphp
          @endforeach
          @if($currentPage < $lastPage)<a href="{{ $pageUrl($currentPage + 1) }}" rel="next">{{ $isRu ? 'Далее' : 'Далі' }} →</a>@endif
        </nav>
      </section>
    </div>
  </div>
</main>

<div class="parts-overlay" data-overlay hidden></div>
<aside class="cart-drawer" data-cart-drawer aria-hidden="true">
  <div class="cart-head">
    <h2>{{ $isRu ? 'Корзина' : 'Кошик' }}</h2>
    <button type="button" data-close-cart aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button>
  </div>
  <div class="cart-lines" data-cart-lines></div>
  <div class="cart-empty" data-cart-empty>{{ $isRu ? 'Корзина пока пуста.' : 'Кошик поки порожній.' }}</div>
  <div class="cart-checkout" data-checkout hidden>
    <div class="cart-total"><span>{{ $isRu ? 'Итого' : 'Разом' }}</span><b data-cart-total>0 грн</b></div>
    <form data-order-form novalidate>
      <div class="checkout-grid">
        <label><span>{{ $isRu ? 'Имя' : 'Ім’я' }} *</span><input name="client_first_name" required maxlength="255" autocomplete="given-name"></label>
        <label><span>{{ $isRu ? 'Фамилия' : 'Прізвище' }} *</span><input name="client_last_name" required maxlength="255" autocomplete="family-name"></label>
      </div>
      <label><span>{{ $isRu ? 'Телефон' : 'Телефон' }} *</span><input name="client_phone" type="tel" required placeholder="+380 XX XXX XX XX" autocomplete="tel"></label>
      <fieldset class="delivery-methods">
        <legend>{{ $isRu ? 'Способ доставки' : 'Спосіб доставки' }}</legend>
        <label><input type="radio" name="delivery_method" value="pickup" checked> {{ $isRu ? 'Самовывоз' : 'Самовивіз' }}</label>
        <label><input type="radio" name="delivery_method" value="nova_poshta"> {{ $isRu ? 'Новая почта' : 'Нова пошта' }}</label>
      </fieldset>
      <div class="nova-poshta-fields" data-np-fields hidden>
        <label class="suggest-field"><span>{{ $isRu ? 'Город' : 'Місто' }} *</span><input name="nova_poshta_city" autocomplete="off"><input type="hidden" name="nova_poshta_city_ref"><div class="suggestions" data-city-suggestions></div></label>
        <label class="suggest-field"><span>{{ $isRu ? 'Отделение или почтомат' : 'Відділення або поштомат' }} *</span><input name="nova_poshta_warehouse" autocomplete="off" disabled><input type="hidden" name="nova_poshta_warehouse_ref"><div class="suggestions" data-warehouse-suggestions></div></label>
      </div>
      <label><span>{{ $isRu ? 'Комментарий' : 'Коментар' }}</span><textarea name="note" rows="3" maxlength="2000"></textarea></label>
      <div class="checkout-error" data-order-error hidden></div>
      <button type="submit" class="checkout-submit">{{ $isRu ? 'Оформить заказ' : 'Оформити замовлення' }}</button>
    </form>
  </div>
</aside>

<div class="order-success" data-success hidden>
  <div class="order-success-card">
    <div class="order-success-icon">✓</div>
    <h2>{{ $isRu ? 'Заказ оформлен' : 'Замовлення оформлено' }}</h2>
    <p data-success-text></p>
    <button type="button" data-success-close>{{ $isRu ? 'Вернуться в каталог' : 'Повернутися до каталогу' }}</button>
  </div>
</div>
@endsection

@push('scripts')
@php
  $partsI18n = [
    'allModels' => $isRu ? 'Все модели' : 'Усі моделі',
    'allParts' => $isRu ? 'Все запчасти модели' : 'Усі запчастини моделі',
    'allProducts' => $isRu ? 'Все запчасти' : 'Усі запчастини',
    'catalog' => 'Каталог',
    'inStock' => $isRu ? 'В наличии' : 'В наявності',
    'toCart' => $isRu ? 'В корзину' : 'У кошик',
    'inCart' => $isRu ? 'В корзине' : 'У кошику',
    'empty' => $isRu ? 'По выбранным фильтрам запчастей не найдено.' : 'За вибраними фільтрами запчастин не знайдено.',
    'loadError' => $isRu ? 'Не удалось загрузить каталог. Попробуйте ещё раз.' : 'Не вдалося завантажити каталог. Спробуйте ще раз.',
    'searchEmpty' => $isRu ? 'Ничего не найдено' : 'Нічого не знайдено',
    'orderError' => $isRu ? 'Не удалось оформить заказ.' : 'Не вдалося оформити замовлення.',
    'required' => $isRu ? 'Заполните обязательные поля.' : 'Заповніть обов’язкові поля.',
    'success' => $isRu ? 'Номер вашего заказа: :number. Мы свяжемся с вами для подтверждения.' : 'Номер вашого замовлення: :number. Ми зв’яжемося з вами для підтвердження.',
    'uah' => 'грн',
    'seoBase' => $isRu ? 'Запчасти Tesla' : 'Запчастини Tesla',
    'seoSuffix' => 'NikolaCars',
  ];
@endphp
<script>
window.partsI18n = @json($partsI18n);
window.initialPartsCatalog = @json($initialCatalog);
</script>
<script src="{{ asset('assets/js/parts.js') }}?v=14" defer></script>
@endpush
