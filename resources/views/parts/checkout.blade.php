@extends('layouts.parts')
@section('content')
@php
  $isRu = $locale === 'ru';
  $apiPrefix = $isRu ? '/ru/parts/api' : '/parts/api';
@endphp
<main class="parts-main" id="partsCheckout" data-locale="{{ $locale }}" data-cities-url="{{ $apiPrefix }}/nova-poshta/cities/" data-warehouses-url="{{ $apiPrefix }}/nova-poshta/warehouses/" data-order-url="{{ $apiPrefix }}/orders">
  <div class="parts-container">
    <a class="checkout-back" href="{{ $isRu ? '/ru/parts/' : '/parts/' }}">← {{ $isRu ? 'Вернуться к покупкам' : 'Повернутися до покупок' }}</a>
    <h1>{{ $isRu ? 'Оформление заказа' : 'Оформлення замовлення' }}</h1>
    <div class="checkout-page-empty" data-page-empty hidden><p>{{ $isRu ? 'Корзина пуста. Добавьте товары перед оформлением заказа.' : 'Кошик порожній. Додайте товари перед оформленням замовлення.' }}</p></div>
    <div class="checkout-page-grid" data-page-checkout hidden>
      <section class="checkout-page-form cart-checkout"><h2>{{ $isRu ? 'Контактные данные и доставка' : 'Контактні дані та доставка' }}</h2>
    <form data-order-form novalidate>
      <div class="checkout-grid">
        <label><span>{{ $isRu ? 'Имя' : 'Ім’я' }} *</span><input name="client_first_name" required maxlength="255" autocomplete="given-name"></label>
        <label><span>{{ $isRu ? 'Фамилия' : 'Прізвище' }} *</span><input name="client_last_name" required maxlength="255" autocomplete="family-name"></label>
      </div>
      <label><span>{{ $isRu ? 'Телефон' : 'Телефон' }} *</span><input name="client_phone" type="tel" required value="+38" placeholder="+380 XX XXX XX XX" autocomplete="tel"></label>
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
      </section>
      <aside class="checkout-page-summary"><h2>{{ $isRu ? 'Ваш заказ' : 'Ваше замовлення' }}</h2><div data-checkout-items></div><div class="cart-total"><span>{{ $isRu ? 'Итого' : 'Разом' }}</span><b data-checkout-total></b></div><button type="button" class="checkout-edit" data-edit-basket>{{ $isRu ? 'Редактировать товары' : 'Редагувати товари' }}</button></aside>
    </div>
    <section class="checkout-page-success" data-success hidden role="status"><h2>{{ $isRu ? 'Спасибо! Заказ оформлен' : 'Дякуємо! Замовлення оформлено' }}</h2><p data-success-text></p><p data-success-after-hours hidden>{{ $isRu ? 'Заказ создан в нерабочее время. Мы обработаем его в ближайшее рабочее время. График работы: пн–пт, 10:00–18:00.' : 'Замовлення створено в неробочий час. Ми опрацюємо його в найближчий робочий час. Графік роботи: пн–пт, 10:00–18:00.' }}</p><a href="{{ $isRu ? '/ru/parts/' : '/parts/' }}">{{ $isRu ? 'Вернуться в каталог' : 'Повернутися до каталогу' }}</a></section>
  </div>
</main>
<link rel="stylesheet" href="{{ asset('assets/css/checkout.css') }}?v=2">
@endsection
@push('scripts')
<script>window.checkoutI18n = @json(['orderError' => $isRu ? 'Не удалось оформить заказ.' : 'Не вдалося оформити замовлення.', 'required' => $isRu ? 'Заполните обязательные поля.' : 'Заповніть обов’язкові поля.', 'success' => $isRu ? 'Номер вашего заказа: :number. Мы свяжемся с вами для подтверждения.' : 'Номер вашого замовлення: :number. Ми зв’яжемося з вами для підтвердження.']);</script>
<script src="{{ asset('assets/js/checkout.js') }}?v=20261018-after-hours-order" defer></script>
@endpush
