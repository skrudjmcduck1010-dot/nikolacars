<section class="product-recommendations product-sold-recommendations">
  @if($similarProducts->isNotEmpty() || $subcategoryProducts->isNotEmpty())
    <div class="product-recommendations-heading">
      <h2>{{ $similarProducts->isNotEmpty() ? ($isRu ? 'Похожие запчасти в наличии' : 'Схожі запчастини в наявності') : ($isRu ? 'Другие запчасти в наличии' : 'Інші запчастини в наявності') }}</h2>
      <p>{{ $isRu ? 'Совместимость с вашим автомобилем уточняйте по VIN.' : 'Сумісність із вашим автомобілем уточнюйте за VIN.' }}</p>
    </div>
    @include('parts._recommendation_cards', ['products' => $similarProducts->isNotEmpty() ? $similarProducts : $subcategoryProducts])
  @else
    <section class="product-sold-empty">
      <h2>{{ $isRu ? 'Похожих запчастей в наличии сейчас нет' : 'Схожих запчастин у наявності зараз немає' }}</h2>
      <p>{{ $isRu ? 'Свяжитесь с нами для подбора по VIN или посмотрите другие запчасти в каталоге.' : 'Зв’яжіться з нами для підбору за VIN або перегляньте інші запчастини в каталозі.' }}</p>
      <div class="product-sold-actions">
        <a href="{{ $catalogUrl }}">{{ $isRu ? 'Перейти в каталог' : 'Перейти до каталогу' }}</a>
        <a href="tel:+380990698380" class="binct-phone-number-1">+38 (099) 069 83 80</a>
      </div>
    </section>
  @endif
</section>
