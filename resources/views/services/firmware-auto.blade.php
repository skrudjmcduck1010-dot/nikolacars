@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Прошивка автомобилей Tesla в Киеве 🛠️ NikolaCars — все для авто Tesla'
  : 'Прошивка автомобілів Тесла в Києві 🛠️ NikolaCars — все для авто Tesla'
)

@section('description', $isRu
  ? 'Прошивка автомобилей Tesla в Киеве 🚗 Подбор и доставка авто Tesla в Украину под ключ. Обслуживание на нашем СТО, прошивка авто. Ваши желания — наши решения.'
  : 'Прошивка автомобілів Тесла в Києві 🚗 Підбір та доставка автомобілів Tesla в Україну під ключ. Обслуговування на нашому СТО, Прошивка авто. Ваші бажання — Наші рішення.'
)

@section('content')
<section class="service-hero">
  <div class="hero-wrap">
    <div class="service-hero-slider" aria-label="Hero">
      <div class="service-hero-slide" style="background: url('/assets/img/slider/firmware-auto.webp') center/cover no-repeat, #000;">
        <div class="service-hero-inner">
          <div class="service-hero-content">

            {{-- LEFT TEXT --}}
            <div class="service-hero-left">
              <h1 class="service-hero-title">
                {{ $isRu ? 'Прошьем ваш автомобиль новой версией' : 'Прошиємо ваше авто новою версією' }}
              </h1>
              <div class="service-hero-subtitle">
                {{ $isRu
                  ? 'Настройка, адаптация и активация функций. Подготовка авто к эксплуатации в Украине.'
                  : 'Налаштування, адаптація та активація функцій. Підготовка авто до експлуатації в Україні.'
                }}
              </div>
            </div>

            {{-- RIGHT FORM (как на скрине) --}}
            <form class="call-form" id="callForm" action="{{ route('lead.callback') }}" method="post" novalidate>
              @csrf

              <div class="call-form-title">
                {{ $isRu ? 'ЗАКАЗАТЬ ЗВОНОК' : 'ЗАМОВИТИ ДЗВІНОК' }}
              </div>

              <div class="call-form-desc">
                {{ $isRu
                  ? 'Оставьте свои контактные данные — наш менеджер свяжется с вами и ответит на все вопросы.'
                  : "Залиште свої контактні дані, після чого наш менеджер зв'яжеться з Вами і відповість на всі запитання."
                }}
              </div>

              <div class="call-form-grid">
                <input class="call-input" type="text" name="name" placeholder="{{ $isRu ? 'Ваше имя*' : "Ваше ім'я*" }}" required>

                <div class="phone-field">
                  <span class="phone-prefix">+380</span>
                  <input
                    class="call-input phone-input"
                    type="tel"
                    name="phone_digits"
                    id="phoneInput"
                    maxlength="9"
                    inputmode="numeric"
                    placeholder="XXXXXXXXX"
                    required
                  >
                </div>

                <textarea class="call-textarea" name="details" rows="5" placeholder="{{ $isRu ? 'Детали запроса' : 'Деталі запиту' }}"></textarea>

                <button class="call-submit" type="submit">{{ $isRu ? 'Перезвонить' : 'Передзвонити' }}</button>
              </div>

              <div class="call-success">
                {{ $isRu ? 'Спасибо! Мы перезвоним вам в течение нескольких минут' : 'Дякуємо, ми Вам передзвонимо через декілька хвилин' }}
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="service-article">
  <div class="container">
    <div class="service-article-card">
      <div class="service-article-kicker">NikolaCars</div>

      <h2 class="service-article-h1">
        {{ $isRu ? 'Прошивка авто Tesla' : 'Прошивка авто Tesla' }}
      </h2>

      <div class="service-article-lines">
        <span></span><span></span>
      </div>

      <div class="service-article-text">

        <p>
          {{ $isRu
            ? 'NikolaCars предоставляет профессиональные услуги по прошивке, обновлению и адаптации программного обеспечения Tesla. Работаем с автомобилями из Европы и США, выполняем как базовые обновления, так и сложные работы с блоками управления и системами безопасности.'
            : 'NikolaCars надає професійні послуги з прошивки, оновлення та адаптації програмного забезпечення Tesla. Працюємо з автомобілями з Європи та США, виконуємо як базові оновлення, так і складні роботи з блоками управління та системами безпеки.'
          }}
        </p>

        <h3>{{ $isRu ? 'Наши услуги по прошивке Tesla' : 'Наші послуги з прошивки Tesla' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'Навигация EU с обновлением авто / без обновления — $500 / $200' : 'Навігація EU з оновленням авто / без оновлення — $500 / $200' }}</li>
          <li>{{ $isRu ? 'Компьютерная диагностика — от $100' : 'Комп’ютерна діагностика — від $100' }}</li>
          <li>{{ $isRu ? 'Прошивка блоков — $100' : 'Прошивка блоків — $100' }}</li>
          <li>{{ $isRu ? 'Crash SRS до 2020 года / с 2020 года — $100 / $200' : 'Краш SRS до 2020 року / з 2020 року — $100 / $200' }}</li>
          <li>{{ $isRu ? 'Crash BODY / АКБ / Пневмо-подвески — $150 / $150 / $100' : 'Краш BODY / АКБ / Пневмо-підвіски — $150 / $150 / $100' }}</li>
          <li>{{ $isRu ? 'Замена SIM — от $50' : 'Заміна SIM — від $50' }}</li>
          <li>{{ $isRu ? 'Замена модема — от $100' : 'Заміна модему — від $100' }}</li>
          <li>{{ $isRu ? 'Обновление прошивки — от $200' : 'Оновлення прошивки — від $200' }}</li>
          <li>{{ $isRu ? 'Калибровка радара — от $100' : 'Калібрування радару — від $100' }}</li>
          <li>{{ $isRu ? 'Калибровка камеры AP 1–2 — $100' : 'Калібрування камери AP 1–2 — $100' }}</li>
          <li>{{ $isRu ? 'Первый ключ Model S — $150' : 'Ключ перший Model S — $150' }}</li>
          <li>{{ $isRu ? 'Первый ключ Model Y / Model 3 — $300' : 'Ключ перший Model Y / Model 3 — $300' }}</li>
          <li>{{ $isRu ? 'Замена порта зарядки на CCS (Model S / Model 3) — $250 / $150' : 'Зміна порту зарядки на CCS (Model S / Model 3) — $250 / $150' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Последняя прошивка для автомобиля' : 'Остання прошивка для автомобіля' }}</h3>

        <h4>{{ $isRu ? 'Обновление программного обеспечения Tesla' : 'Оновлення програмного забезпечення Tesla' }}</h4>

        <p>
          {{ $isRu
            ? 'Специалисты нашей компании выполняют обновление прошивки до актуальной версии. С каждым новым релизом у Tesla появляются дополнительные функции, повышается стабильность систем, оптимизируется работа батареи и автопилота.'
            : 'Фахівці нашої компанії виконують оновлення прошивки до актуальної версії. З кожним новим релізом у Tesla з’являються додаткові функції, покращується стабільність систем, оптимізується робота батареї та автопілота.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Если ваш электрокар не получает новые OTA-обновления — обращайтесь к нам. Мы поможем восстановить или установить актуальную версию ПО.'
            : 'Якщо ваш електрокар не отримує нові OTA-оновлення — звертайтесь до нас. Ми допоможемо відновити або встановити актуальну версію ПЗ.'
          }}
        </p>

        <h3>{{ $isRu ? 'Как выполняется обновление Tesla' : 'Як здійснюється оновлення Tesla' }}</h3>

        <p>
          {{ $isRu
            ? 'Для начала нужно определить причину отсутствия обновлений. Самые частые варианты:'
            : 'Для початку необхідно визначити причину відсутності оновлень. Найчастіші варіанти:'
          }}
        </p>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'Автомобиль долгое время не подключался к интернету;' : 'Автомобіль довгий час не підключався до інтернету;' }}</li>
          <li>{{ $isRu ? 'Просроченные сертификаты на автомобиле.' : 'Прострочені сертифікати на автомобілі.' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'В первом случае проблема решается обновлением системы вручную. Наши специалисты с помощью профессионального оборудования устанавливают актуальную прошивку.'
            : 'У першому випадку проблема вирішується оновленням системи вручну. Наші спеціалісти за допомогою професійного обладнання встановлюють актуальну прошивку.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Для дальнейших автоматических обновлений мы устанавливаем предпоследнюю версию прошивки с запросом на последнюю версию с сервера Tesla — это помогает восстановить оригинальную связь автомобиля с серверами.'
            : 'Для подальшого автоматичного оновлення ми встановлюємо передостанню версію прошивки із запитом на останню версію з сервера Tesla, що дозволяє відновити оригінальний зв’язок автомобіля з серверами.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Во втором случае все последующие обновления могут устанавливаться вручную, поскольку связь с серверами ограничена из-за просроченных сертификатов.'
            : 'У другому випадку всі подальші оновлення можуть встановлюватися вручну, оскільки зв’язок із серверами обмежено через прострочені сертифікати.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'В любом случае специалисты NikolaCars помогут установить последнюю версию прошивки и восстановить полный функционал вашей Tesla.'
            : 'У будь-якому випадку фахівці NikolaCars допоможуть встановити останню версію прошивки та відновити повний функціонал вашої Tesla.'
          }}
        </p>

      </div>
    </div>
  </div>
</section>

<section class="reviews">
  <div class="container">
    <div class="reviews-head">
      <div class="reviews-kicker">
        {{ $isRu ? 'Что говорят о нас наши счастливые клиенты' : 'Що говорять про нас наші щасливі клієнти' }}
      </div>
      <h2 class="reviews-title">{{ $isRu ? 'ОТЗЫВЫ' : 'ВІДГУКИ' }}</h2>
      <div class="reviews-lines"><span></span><span></span></div>
    </div>

    <div class="reviews-slider" id="reviewsSlider" aria-label="{{ $isRu ? 'Отзывы' : 'Відгуки' }}">
      <button class="reviews-arrow prev" type="button" aria-label="Previous">‹</button>
      <button class="reviews-arrow next" type="button" aria-label="Next">›</button>

      <div class="reviews-viewport">
        <div class="reviews-track">
          {{-- 1 --}}
          <article class="review">
            <div class="review-bubble">
              <div class="review-quote" aria-hidden="true">“</div>
              <div class="review-text">
                В марте 2020 года заказал машину TESLA Model S из Америки. Ранее не имел дела с американскими авто,
                потому не рискнул выбирать машину самостоятельно. Мои требования к машине были минимальны, так как все
                упиралось в цену. Остановил выбор на 60ке 2014 года. Ребята скинули несколько вариантов и мы определились.
                Уже в начале мая машина была в Киеве. Ремонт занял около месяца, повреждения были незначительные. Готовую
                машину мне передали в эксплуатацию в июне. С выбором явно не ошибся, тесла это нереальный автомобиль.
                Спасибо nikolacars за то что помогли мне с пригоном, ремонтом и прошивками и уложились в мой бюджет.
                Следующую теслу буду заказывать только с вами.
              </div>
            </div>

            <div class="review-person">
              <div class="review-avatar">
                <img src="/images/reviews/egor.jpg" alt="Егор Титов">
              </div>
              <div class="review-meta">
                <div class="review-name">ЕГОР ТИТОВ</div>
                <div class="review-car">Tesla Model S</div>
              </div>
            </div>
          </article>

          {{-- 2 --}}
          <article class="review">
            <div class="review-bubble">
              <div class="review-quote" aria-hidden="true">“</div>
              <div class="review-text">
                Спасибо сервису НИКОЛАКАРЗ за то что помогли осуществить мою давнюю мечту, и мужественно выдержали мою
                дотошность и внимание к мелочам. Теперь я счастливая обладательница лучшего автомобиля в мире TESLA model 3
              </div>
            </div>

            <div class="review-person">
              <div class="review-avatar">
                <img src="/images/reviews/yula.jpg" alt="Юла">
              </div>
              <div class="review-meta">
                <div class="review-name">ЮЛА</div>
                <div class="review-car">Tesla Model 3</div>
              </div>
            </div>
          </article>

          {{-- 3 --}}
          <article class="review">
            <div class="review-bubble">
              <div class="review-quote" aria-hidden="true">“</div>
              <div class="review-text">
                Давно хотів Tesla. Але ціна БУ автомобіля в Україні мене не втомлювала зовсім. Вирішив подивитися на
                аукціонах в Америці, і знайшов кілька варіантів 3-ки які сподобалися. Досвіду пригону машин у мене немає,
                і я почав пошуки компанії які цим займаються. Порадили nikolacars, зідзвонились, прикинули вартість і
                вирішив працювати з ними. Загалом готовий автомобіль був у мене вже через 4 місяці з дати покупки на
                аукціоні. Вибором машини і посередником задоволений на всі 100. Рекомендую!
              </div>
            </div>

            <div class="review-person">
              <div class="review-avatar">
                <img src="/images/reviews/yuriy.jpg" alt="Юрій">
              </div>
              <div class="review-meta">
                <div class="review-name">ЮРІЙ</div>
                <div class="review-car">Tesla Model S</div>
              </div>
            </div>
          </article>

          {{-- 4 --}}
          <article class="review">
            <div class="review-bubble">
              <div class="review-quote" aria-hidden="true">“</div>
              <div class="review-text">
                Тесла це автомобіль, який спробувавши одного разу полюбиш назавжди. Прискорення за 4,6 секунди до 100,
                відмінно тримає дорогу, дуже тиха, шикарний дизайн і якість матеріалів на вищому рівні. На одному заряді
                спокійно катаюся по Києву тиждень. Мінусів поки що не побачив. Машину замовляв через Nikolacars, вони самі
                підібрали машину в Америці, пригнали в Україну, повністю займалися ремонтом і встановили всі прошивки.
                Все швидко, якісно і не дорого. Сервісом залишився дуже задоволений, всім рекомендую NIKOLACARS.
              </div>
            </div>

            <div class="review-person">
              <div class="review-avatar">
                <img src="/images/reviews/denis.jpg" alt="Денис Б">
              </div>
              <div class="review-meta">
                <div class="review-name">ДЕНИС Б</div>
                <div class="review-car">Tesla Model S</div>
              </div>
            </div>
          </article>
        </div>
      </div>

      <div class="reviews-dots" aria-label="Pagination"></div>
    </div>
  </div>
</section>

<section class="contacts">
  <div class="container">

    <div class="contacts-head">
      <div class="contacts-kicker">{{ $isRu ? 'Связь с нами' : "Звʼязок з нами" }}</div>
      <h2 class="contacts-title">{{ $isRu ? 'НАШИ КОНТАКТЫ' : 'НАШІ КОНТАКТИ' }}</h2>
      <div class="contacts-lines">
        <span></span><span></span>
      </div>
    </div>

    <div class="contacts-grid">

      {{-- LEFT --}}
      <div class="contacts-card">
        <div class="contact-item">
          <div class="contact-icon">📍</div>
          <div>
            <div class="contact-label">{{ $isRu ? 'АДРЕС' : 'АДРЕСА' }}</div>
            <div class="contact-value">{{ $isRu ? 'г. Киев, ул. Коллекторная, 30' : 'м. Київ, вул. Колекторна, 30' }}</div>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-icon">📞</div>
          <div>
            <div class="contact-label">{{ $isRu ? 'ТЕЛЕФОН' : 'ТЕЛЕФОН' }}</div>
            <div class="contact-value">
              <a href="tel:+380975120255">+38 (097) 512 02 55</a>
            </div>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-icon">✉️</div>
          <div>
            <div class="contact-label">EMAIL</div>
            <div class="contact-value">
              <a href="mailto:nikola.carsua@gmail.com">nikola.carsua@gmail.com</a>
            </div>
          </div>
        </div>
      </div>

      {{-- RIGHT --}}
      <div class="contacts-map">
        <iframe
          src="https://www.google.com/maps?q=Київ,+вулиця+Колекторна,+30&output=embed"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade">
        </iframe>
      </div>

    </div>
  </div>
</section>

<section class="service-final">
  <div class="container">
    <div class="service-final-content">

      <h2>{{ $isRu ? 'Прошивка автомобилей Tesla в Киеве' : 'Прошивка автомобілів Tesla в Києві' }}</h2>

      <p>
        {{ $isRu
          ? 'Мало кому понравится, если в дороге внезапно автопилот Tesla начнёт работать некорректно, навигация будет сбоить, а батарея разряжаться быстрее обычного. Чаще всего это явный признак того, что вам нужна срочная прошивка Tesla.'
          : 'Мало кому сподобається, якщо в дорозі раптово автопілот Tesla почне працювати некоректно, навігація даватиме збої, а батарея розряджатиметься швидше, ніж зазвичай. Найімовірніше, це явна ознака того, що вам потрібна термінова прошивка Tesla.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'NikolaCars — компания, которая предоставляет услуги по обновлению и прошивке автомобилей Tesla в Киеве на профессиональном уровне и с максимальной ответственностью.'
          : 'NikolaCars — компанія, що надає послуги з оновлення та прошивки автомобілів Tesla в Києві на професійному рівні та з максимальною відповідальністю.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'Прошивка авто Tesla — это программное обеспечение, которое отвечает за управление всеми системами электромобиля: от основной операционной системы до автопилота, управления батареей, мультимедиа и вспомогательных модулей.'
          : 'Прошивка авто Tesla — це програмне забезпечення, яке відповідає за управління всіма системами електромобіля: від основної операційної системи до автопілота, управління батареєю, мультимедіа та допоміжних модулів.'
        }}
      </p>

      <h3>{{ $isRu ? 'Почему важно обновлять прошивку Tesla?' : 'Чому важливо оновлювати прошивку Tesla?' }}</h3>

      <ul>
        <li><strong>{{ $isRu ? 'Улучшение производительности.' : 'Покращення продуктивності.' }}</strong> {{ $isRu ? 'Устранение багов и оптимизация работы систем.' : 'Усунення багів та оптимізація роботи систем.' }}</li>
        <li><strong>{{ $isRu ? 'Увеличение запаса хода.' : 'Збільшення запасу ходу.' }}</strong> {{ $isRu ? 'Новые версии прошивки оптимизируют энергопотребление.' : 'Нові версії прошивки оптимізують енергоспоживання.' }}</li>
        <li><strong>{{ $isRu ? 'Обновление автопилота и безопасности.' : 'Оновлення автопілота та безпеки.' }}</strong> {{ $isRu ? 'Повышение надежности и стабильности работы систем.' : 'Підвищення надійності та стабільності роботи систем.' }}</li>
        <li><strong>{{ $isRu ? 'Улучшение комфорта.' : 'Покращення комфорту.' }}</strong> {{ $isRu ? 'Обновление интерфейса и мультимедиа.' : 'Оновлення інтерфейсу та мультимедіа.' }}</li>
      </ul>

      <p>
        {{ $isRu
          ? 'Своевременная перепрошивка Tesla позволяет электромобилю работать на максимуме своих возможностей и соответствовать современным требованиям безопасности и комфорта.'
          : 'Своєчасна перепрошивка Tesla дозволяє електромобілю працювати на максимумі своїх можливостей і відповідати сучасним вимогам безпеки та комфорту.'
        }}
      </p>

      <h3>{{ $isRu ? 'Наши услуги по прошивке Tesla' : 'Наші послуги з прошивки Tesla' }}</h3>

      <ul>
        <li>{{ $isRu ? 'Обновление прошивки до актуальной версии' : 'Оновлення прошивки до актуальної версії' }}</li>
        <li>{{ $isRu ? 'Прошивка отдельных модулей и блоков' : 'Прошивка окремих модулів та блоків' }}</li>
        <li>{{ $isRu ? 'Программирование ключей Tesla' : 'Програмування ключів Tesla' }}</li>
        <li>{{ $isRu ? 'Калибровка автопилота и радаров' : 'Калібрування автопілота та радарів' }}</li>
        <li>{{ $isRu ? 'Активация Diagnostic / Factory Mode' : 'Активація Diagnostic / Factory Mode' }}</li>
        <li>{{ $isRu ? 'Обновление навигации и GPS-систем' : 'Оновлення навігації та GPS-систем' }}</li>
      </ul>

      <h3>{{ $isRu ? 'Все для Tesla — в одном месте' : 'Все для Tesla — в одному місці' }}</h3>

      <p>
        {{ $isRu
          ? 'Мы выполняем полный спектр работ, чтобы ваша Tesla всегда была в отличном техническом состоянии. Профессионализм сотрудников NikolaCars гарантирует безопасную и качественную прошивку автомобиля без рисков для электроники.'
          : 'Ми виконуємо повний спектр робіт, щоб ваша Tesla завжди була у відмінному технічному стані. Професіоналізм співробітників NikolaCars гарантує безпечну та якісну прошивку автомобіля без ризиків для електроніки.'
        }}
      </p>

      <h3>{{ $isRu ? 'Почему выбирают NikolaCars?' : 'Чому обирають NikolaCars?' }}</h3>

      <ul>
        <li>{{ $isRu ? 'Сертифицированные специалисты с опытом работы с Tesla' : 'Сертифіковані спеціалісти з досвідом роботи з Tesla' }}</li>
        <li>{{ $isRu ? 'Оригинальное программное обеспечение и оборудование' : 'Оригінальне програмне забезпечення та обладнання' }}</li>
        <li>{{ $isRu ? 'Индивидуальный подход к каждому авто' : 'Індивідуальний підхід до кожного авто' }}</li>
        <li>{{ $isRu ? 'Гарантия на выполненные работы' : 'Гарантія на виконані роботи' }}</li>
        <li>{{ $isRu ? 'Оперативное выполнение без потери качества' : 'Оперативне виконання без втрати якості' }}</li>
      </ul>

      <p>
        {{ $isRu
          ? 'NikolaCars — лидер в сфере обслуживания и прошивки автомобилей Tesla в Киеве. Мы поможем раскрыть потенциал вашего электромобиля и обеспечить его стабильную работу на долгие годы.'
          : 'NikolaCars — лідер у сфері обслуговування та прошивки автомобілів Tesla в Києві. Ми допоможемо розкрити потенціал вашого електромобіля та забезпечити його стабільну роботу на довгі роки.'
        }}
      </p>

    </div>
  </div>
</section>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('callForm');
  if (!form) return;

  const submitBtn = form.querySelector('.call-submit');
  const csrf = form.querySelector('input[name="_token"]')?.value || '';

  const phoneInput = document.getElementById('phoneInput');

  // Ввод только цифр (9 цифр после +380)
  if (phoneInput) {
    phoneInput.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 9);
    });

    // можно автоподсветку/сообщение сделать позже
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    if (!phoneInput) {
      alert(@json($isRu ? 'Поле телефона не найдено' : 'Поле телефону не знайдено'));
      return;
    }

    const digits = phoneInput.value.replace(/\D/g, '');
    const fullPhone = '+380' + digits;

    // Только украинские мобильные коды
    const uaPhoneRegex = /^\+380(39|50|63|66|67|68|73|91|92|93|94|95|96|97|98|99)\d{7}$/;

    if (!uaPhoneRegex.test(fullPhone)) {
      alert(@json($isRu ? 'Введите корректный украинский номер (9 цифр после +380)' : 'Введіть коректний український номер (9 цифр після +380)'));
      phoneInput.focus();
      return;
    }

    // UI
    form.classList.remove('is-success');
    if (submitBtn) submitBtn.disabled = true;

    const fd = new FormData(form);

    // ВАЖНО: перезаписываем phone в полном формате
    fd.set('phone', fullPhone);

    // Если в форме поле называется phone_digits — можно удалить его, чтобы на сервер не улетало
    fd.delete('phone_digits');

    // page + utm
    fd.append('page', window.location.href);

    const params = new URLSearchParams(window.location.search);
    const utmKeys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];
    const utmPairs = [];
    utmKeys.forEach(k => { if (params.get(k)) utmPairs.push(`${k}=${params.get(k)}`); });
    fd.append('utm', utmPairs.join('&'));

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrf,
          'Accept': 'application/json'
        },
        body: fd
      });

      const json = await res.json().catch(() => ({}));

      if (!res.ok || !json.ok) {
        console.error('Lead send failed:', json);
        alert(@json($isRu ? 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.' : 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.'));
        return;
      }

      form.reset();
      form.classList.add('is-success');
    } catch (err) {
      console.error(err);
      alert(@json($isRu ? 'Ошибка сети. Попробуйте ещё раз.' : 'Помилка мережі. Спробуйте ще раз.'));
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
});
</script>

<script src="/assets/js/reviews.js" defer></script>
@endpush
@endsection
