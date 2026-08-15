@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
  $apiPrefix = $isRu ? '/ru/parts/api' : '/parts/api';
@endphp
<main class="parts-main" id="partsCatalog"
      data-locale="{{ $locale }}"
      data-product-base="{{ $isRu ? '/ru/parts' : '/parts' }}"
      data-catalog-url="{{ $apiPrefix }}/catalog/"
      data-cities-url="{{ $apiPrefix }}/nova-poshta/cities/"
      data-warehouses-url="{{ $apiPrefix }}/nova-poshta/warehouses/"
      data-order-url="{{ $apiPrefix }}/orders">
  <div class="parts-container">
    <div class="parts-title-row">
      <div>
        <h1>{{ $isRu ? 'Запчасти Tesla' : 'Запчастини Tesla' }}</h1>
        <p data-current-context>{{ $isRu ? 'Оригинальные запчасти со склада NikolaCars' : 'Оригінальні запчастини зі складу NikolaCars' }}</p>
      </div>
      <div class="parts-total" data-total></div>
    </div>

    <div class="parts-model-tabs" data-models></div>

    <form class="parts-toolbar" data-filter-form>
      <input type="search" name="q" autocomplete="off" placeholder="{{ $isRu ? 'Название, артикул, VIN' : 'Назва, артикул, VIN' }}">
      <select name="sort" aria-label="{{ $isRu ? 'Сортировка' : 'Сортування' }}">
        <option value="newest">{{ $isRu ? 'Сначала новые' : 'Спочатку нові' }}</option>
        <option value="price_asc">{{ $isRu ? 'Цена: по возрастанию' : 'Ціна: за зростанням' }}</option>
        <option value="price_desc">{{ $isRu ? 'Цена: по убыванию' : 'Ціна: за спаданням' }}</option>
        <option value="name">{{ $isRu ? 'По названию' : 'За назвою' }}</option>
      </select>
      <button type="submit">{{ $isRu ? 'Найти' : 'Знайти' }}</button>
    </form>

    <div class="parts-layout">
      <aside class="parts-sidebar">
        <div class="parts-sidebar-title" data-sidebar-title>{{ $isRu ? 'Все запчасти' : 'Усі запчастини' }}</div>
        <button type="button" class="parts-category active" data-category=""><span>{{ $isRu ? 'Все запчасти модели' : 'Усі запчастини моделі' }}</span><b data-model-total>0</b></button>
        <div data-categories></div>
      </aside>

      <section class="parts-results">
        <div class="parts-results-head">
          <h2 data-results-title>{{ $isRu ? 'Каталог' : 'Каталог' }}</h2>
          <span data-results-count></span>
        </div>
        <div class="parts-category-grid" data-category-grid></div>
        <div class="parts-products" data-products></div>
        <div class="parts-empty" data-empty hidden></div>
        <button type="button" class="parts-more" data-more hidden>{{ $isRu ? 'Показать ещё' : 'Показати ще' }}</button>
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
    'catalog' => 'Каталог',
    'positions' => $isRu ? 'позиций' : 'позицій',
    'inStock' => $isRu ? 'В наличии' : 'В наявності',
    'toCart' => $isRu ? 'В корзину' : 'У кошик',
    'inCart' => $isRu ? 'В корзине' : 'У кошику',
    'empty' => $isRu ? 'По выбранным фильтрам запчастей не найдено.' : 'За вибраними фільтрами запчастин не знайдено.',
    'loadError' => $isRu ? 'Не удалось загрузить каталог. Попробуйте ещё раз.' : 'Не вдалося завантажити каталог. Спробуйте ще раз.',
    'orderError' => $isRu ? 'Не удалось оформить заказ.' : 'Не вдалося оформити замовлення.',
    'required' => $isRu ? 'Заполните обязательные поля.' : 'Заповніть обов’язкові поля.',
    'success' => $isRu ? 'Номер вашего заказа: :number. Мы свяжемся с вами для подтверждения.' : 'Номер вашого замовлення: :number. Ми зв’яжемося з вами для підтвердження.',
    'uah' => 'грн',
  ];
@endphp
<script>
window.partsI18n = @json($partsI18n);
</script>
<script src="{{ asset('assets/js/parts.js') }}?v=3" defer></script>
@endpush
