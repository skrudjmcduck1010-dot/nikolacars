<dialog class="basket-modal" data-basket-modal aria-labelledby="basket-title" data-locale="{{ $loc }}">
  <div class="basket-head"><h2 id="basket-title">{{ $isRu ? 'Корзина' : 'Кошик' }}</h2><button type="button" data-basket-close aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button></div>
  <div class="basket-items" data-basket-items></div>
  <p class="basket-empty" data-basket-empty hidden>{{ $isRu ? 'Корзина пока пуста.' : 'Кошик поки порожній.' }}</p>
  <div class="basket-footer"><button type="button" class="basket-continue" data-basket-close>{{ $isRu ? 'Продолжить покупки' : 'Продовжити покупки' }}</button><div class="basket-summary" data-basket-summary><strong data-basket-total></strong><button type="button" class="basket-checkout" data-basket-checkout>{{ $isRu ? 'Оформить заказ' : 'Оформити замовлення' }}</button></div></div>
</dialog>
<link rel="stylesheet" href="{{ asset('assets/css/cart-modal.css') }}?v=1">
<script src="{{ asset('assets/js/cart-modal.js') }}?v=4" defer></script>
