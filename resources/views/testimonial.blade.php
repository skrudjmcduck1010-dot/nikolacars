@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Отзывы о NikolaCars 🏅 Сервис и продажа авто Tesla в Киеве'
  : 'Відгуки про NikolaCars 🏅 Сервіс та продаж авто Tesla в Києві'
)

@section('description', $isRu
  ? 'Отзывы 👍 Что говорят о нас наши клиенты. Читайте на нашем сайте. NikolaCars — надёжный партнёр в мире электромобилей Tesla.'
  : 'Відгуки 👍 Що кажуть про нас наші клієнти, Читайте на нашому сайті. NikolaCars — надійний партнер в світі електромобілів Tesla.'
)

@section('content')
<section class="page-simple">
  <div class="container">
    <div class="page-simple-card">

      <div class="services-header">
        <p class="services-subtitle">
          {{ $isRu ? 'Что говорят о нас наши счастливые клиенты' : 'Що говорять про нас наші щасливі клієнти' }}
        </p>
        <h1 class="services-title">
          {{ $isRu ? 'ОТЗЫВЫ' : 'ВІДГУКИ' }}
        </h1>
        <div class="services-divider"></div>
      </div>

      <div class="testimonials-grid">

        {{-- 1 --}}
        <article class="t-card">
          <div class="t-cover">
            <img src="/images/reviews/egor-cover.jpg" alt="Егор Титов" loading="lazy">
            <div class="t-avatar">
              <img src="/images/reviews/egor.jpg" alt="Егор Титов" loading="lazy">
            </div>
          </div>

          <div class="t-body">
            <div class="t-name">Егор Титов</div>
            <div class="t-model">Tesla Model S</div>

            <div class="t-text">
              В марте 2020 года заказал машину TESLA Model S из Америки. Ранее не имел дела с американскими авто,
              потому не рискнул выбирать машину самостоятельно. Мои требования к машине были минимальны, так как все
              упиралось в цену. Остановил выбор на 60ке 2014 года. Ребята скинули несколько вариантов и мы определились.
              Уже в начале мая машина была в Киеве. Ремонт занял около месяца, повреждения были незначительные. Готовую
              машину мне передали в эксплуатацию в июне. С выбором явно не ошибся, тесла это нереальный автомобиль.
              Спасибо nikolacars за то что помогли мне с пригоном, ремонтом и прошивками и уложились в мой бюджет.
              Следующую теслу буду заказывать только с вами.
            </div>

            <div class="t-quote">”</div>
          </div>
        </article>

        {{-- 2 --}}
        <article class="t-card">
          <div class="t-cover">
            <img src="/images/reviews/yula-cover.jpg" alt="Юла" loading="lazy">
            <div class="t-avatar">
              <img src="/images/reviews/yula.jpg" alt="Юла" loading="lazy">
            </div>
          </div>

          <div class="t-body">
            <div class="t-name">Юла</div>
            <div class="t-model">Tesla Model 3</div>

            <div class="t-text">
              Спасибо сервису НИКОЛАКАРЗ за то что помогли осуществить мою давнюю мечту, и мужественно выдержали мою
              дотошность и внимание к мелочам. Теперь я счастливая обладательница лучшего автомобиля в мире TESLA model 3
            </div>

            <div class="t-quote">”</div>
          </div>
        </article>

        {{-- 3 --}}
        <article class="t-card">
          <div class="t-cover">
            <img src="/images/reviews/yuriy-cover.jpg" alt="Юрій" loading="lazy">
            <div class="t-avatar">
              <img src="/images/reviews/yuriy.jpg" alt="Юрій" loading="lazy">
            </div>
          </div>

          <div class="t-body">
            <div class="t-name">Юрій</div>
            <div class="t-model">Tesla Model S</div>

            <div class="t-text">
              Давно хотів Tesla. Але ціна БУ автомобіля в Україні мене не втомлювала зовсім. Вирішив подивитися на
              аукціонах в Америці, і знайшов кілька варіантів 3-ки які сподобалися. Досвіду пригону машин у мене немає,
              і я почав пошуки компанії які цим займаються. Порадили nikolacars, зідзвонились, прикинули вартість і
              вирішив працювати з ними. Загалом готовий автомобіль був у мене вже через 4 місяці з дати покупки на
              аукціоні. Вибором машини і посередником задоволений на всі 100. Рекомендую!
            </div>

            <div class="t-quote">”</div>
          </div>
        </article>

        {{-- 4 --}}
        <article class="t-card">
          <div class="t-cover">
            <img src="/images/reviews/denis-cover.jpg" alt="Денис Б" loading="lazy">
            <div class="t-avatar">
              <img src="/images/reviews/denis.jpg" alt="Денис Б" loading="lazy">
            </div>
          </div>

          <div class="t-body">
            <div class="t-name">Денис Б</div>
            <div class="t-model">Tesla Model S</div>

            <div class="t-text">
              Тесла це автомобіль, який спробувавши одного разу полюбиш назавжди. Прискорення за 4,6 секунди до 100,
              відмінно тримає дорогу, дуже тиха, шикарний дизайн і якість матеріалів на вищому рівні. На одному заряді
              спокійно катаюся по Києву тиждень. Мінусів поки що не побачив. Машину замовляв через Nikolacars, вони самі
              підібрали машину в Америці, пригнали в Україну, повністю займалися ремонтом і встановили всі прошивки.
              Все швидко, якісно і не дорого. Сервісом залишився дуже задоволений, всім рекомендую NIKOLACARS.
            </div>

            <div class="t-quote">”</div>
          </div>
        </article>

      </div>

    </div>
  </div>
</section>
@endsection
