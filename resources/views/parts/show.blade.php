@extends('layouts.parts')

@section('content')
@php
  $isRu = $locale === 'ru';
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
    $path = \App\Services\CatalogPath::normalize($slug);
    if ($index === 0) {
      return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/'.$slug.'/' : $catalogUrl.$slug.'/';
    }
    return $modelSlug !== '' ? $catalogUrl.$modelSlug.'/'.$path.'/' : $catalogUrl.$path.'/';
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
            <picture data-product-main-picture>
              <img src="{{ $mainDisplayUrl }}"
                   @if($mainSrcset !== '') srcset="{{ $mainSrcset }}" sizes="(max-width: 800px) calc(100vw - 32px), 660px" @endif
                   alt="{{ $product['name'] }}"
                   @if(!empty($mainDisplayVariant['width'])) width="{{ $mainDisplayVariant['width'] }}" height="{{ $mainDisplayVariant['height'] }}" @endif
                   decoding="async"
                   fetchpriority="high"
                   data-product-main-image>
            </picture>
          </button>
        @else
          <div class="product-main-image no-image"><span>NIKOLACARS</span></div>
        @endif
        @if($images->count() > 1)
          <div class="product-thumbnails">
            @foreach($imageItems as $imageItem)
              <button type="button" class="{{ $loop->first ? 'active' : '' }}" data-product-image="{{ $imageItem['url'] }}" data-product-image-index="{{ $loop->index }}"><img src="{{ $imageItem['thumbnail_url'] ?? $imageItem['url'] }}" alt="" width="{{ $imageItem['thumbnail_width'] ?? 360 }}" height="{{ $imageItem['thumbnail_height'] ?? 300 }}" loading="lazy" decoding="async"></button>
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
          @php
            $hasPrice = (float) ($product['price_uah'] ?? 0) > 0;
            $hasStock = (int) ($product['quantity'] ?? 0) > 0;
            $isSold = ($product['availability_status'] ?? '') === 'sold';
          @endphp
          @if(!$isSold)
            <div class="product-detail-price{{ $hasPrice ? '' : ' product-detail-price-on-request' }}">
              @if($hasPrice)
                {{ number_format((float) $product['price_uah'], 0, '.', ' ') }} грн
              @else
                <strong>{{ $isRu ? 'Цену уточняйте' : 'Ціну уточнюйте' }}</strong>
                <span>{{ $isRu ? 'по телефонам' : 'за телефонами' }}</span>
                <span><a href="tel:+380990698380" class="binct-phone-number-1">+38 (099) 069 83 80</a></span>
                <span><a href="tel:+380634730819" class="binct-phone-number-2">+38 (063) 473 08 19</a></span>
              @endif
            </div>
          @endif
          <div class="product-detail-stock{{ $isSold ? ' is-sold' : '' }}">{{ $isSold ? 'Продано' : ($hasStock ? (($isRu ? 'В наличии' : 'В наявності').': '.$product['quantity']) : ($isRu ? 'Временно нет в наличии' : 'Тимчасово немає в наявності')) }}</div>
          @if($hasPrice && $hasStock)<button
            type="button"
            data-product-add
            data-added-label="{{ $isRu ? 'В корзине' : 'У кошику' }}"
            aria-pressed="false"
          >{{ $isRu ? 'Добавить в корзину' : 'Додати в кошик' }}</button>@endif
        </div>

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
          @if(!empty($product['color']))<div><dt>{{ $isRu ? 'Цвет' : 'Колір' }}</dt><dd>{{ $product['color'] }}</dd></div>@endif
          @if(!empty($product['compatibility']))<div><dt>{{ $isRu ? 'Совместимость' : 'Сумісність' }}</dt><dd>{{ $product['compatibility'] }}</dd></div>@endif
        </dl>
        @if(collect(['length_cm', 'width_cm', 'height_cm'])->contains(fn ($field) => is_numeric($product[$field] ?? null) && (float) $product[$field] > 0))
          <p class="product-spec-note">{{ $isRu ? 'Указаны размеры самой запчасти без упаковки.' : 'Вказано розміри самої запчастини без пакування.' }}</p>
        @endif

      </section>
    </div>

    <section class="product-description" id="delivery-and-returns">
      <h2>{{ $isRu ? 'Доставка и возврат' : 'Доставка та повернення' }}</h2>
      <p>{{ $isRu
        ? 'Отправляем запчасти Новой почтой по Украине. Доставку оплачивает клиент по тарифам перевозчика; стоимость зависит от веса, габаритов и способа получения. Бесплатный самовывоз: Киев, ул. Коллекторная, 30.'
        : 'Надсилаємо запчастини Новою поштою по Україні. Доставку оплачує клієнт за тарифами перевізника; вартість залежить від ваги, габаритів і способу отримання. Безкоштовний самовивіз: Київ, вул. Колекторна, 30.' }}</p>
      <p>{{ $isRu
        ? 'Подготовка заказа — от 1 до 16 часов без учёта выходных. Обрабатываем и отправляем заказы с понедельника по пятницу. Заказы, поступившие вне рабочего времени, обрабатываем с 10:00 следующего рабочего дня; в пятницу после 18:00 и в выходные — с 10:00 понедельника. Ориентировочный срок доставки Новой почтой по Украине — 1–3 дня после отправки; точный срок зависит от направления и условий работы перевозчика.'
        : 'Підготовка замовлення — від 1 до 16 годин без урахування вихідних. Обробляємо та відправляємо замовлення з понеділка по п’ятницю. Замовлення, що надійшли поза робочим часом, обробляємо з 10:00 наступного робочого дня; у п’ятницю після 18:00 та у вихідні — з 10:00 понеділка. Орієнтовний строк доставки Новою поштою по Україні — 1–3 дні після відправлення; точний строк залежить від напрямку та умов роботи перевізника.' }}</p>
      <p>{{ $isRu
        ? 'Проверка, возврат или обмен — в течение 14 календарных дней с момента получения товара. Возврат бывших в употреблении запчастей согласовывается с NikolaCars: если состояние не соответствует заявленному или получена не та деталь, свяжитесь с нами для оформления.'
        : 'Перевірка, повернення або обмін — протягом 14 календарних днів з моменту отримання товару. Повернення вживаних запчастин погоджується з NikolaCars: якщо стан не відповідає заявленому або отримано не ту деталь, зв’яжіться з нами для оформлення.' }}</p>
      <p>{{ $isRu
        ? 'Согласованный возврат можно отправить Новой почтой или передать лично в NikolaCars по адресу: Киев, ул. Коллекторная, 30. Перед возвратом свяжитесь с нами для согласования и уточнения реквизитов отправки.'
        : 'Погоджене повернення можна надіслати Новою поштою або передати особисто в NikolaCars за адресою: Київ, вул. Колекторна, 30. Перед поверненням зв’яжіться з нами для погодження та уточнення реквізитів відправлення.' }}</p>
      <p>{{ $isRu
        ? 'По условиям магазина возврат не предусмотрен после окончания срока проверки, при нарушении правил установки или эксплуатации, новых механических повреждениях, из-за заранее оговоренного дефекта или если деталь не понадобилась. Расходы на доставку, установку и настройку магазин не компенсирует; доставку возврата оплачивает покупатель.'
        : 'За умовами магазину повернення не передбачене після закінчення строку перевірки, у разі порушення правил встановлення або експлуатації, нових механічних пошкоджень, через заздалегідь обумовлений дефект або якщо деталь не знадобилася. Витрати на доставку, встановлення та налаштування магазин не компенсує; доставку повернення оплачує покупець.' }}</p>
      <p><a href="https://nikolacars.com.ua/ua/delivery_info">{{ $isRu ? 'Подробные условия доставки и оплаты в нашем магазине на Prom' : 'Докладні умови доставки та оплати в нашому магазині на Prom' }}</a></p>
    </section>

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
      <img alt="{{ $product['name'] }}" decoding="async" data-lightbox-image>
      @if($images->count() > 1)<div class="product-lightbox-counter" data-lightbox-counter>1 / {{ $images->count() }}</div>@endif
    </div>
  </div>
@endif

<script type="application/json" id="productData">@json($product)</script>
@endsection

@push('scripts')
<script>window.productPageConfig = @json(['catalogUrl' => $catalogUrl]);</script>
<script src="{{ asset('assets/js/parts-product.js') }}?v=20260921-image-seo" defer></script>
@endpush
