@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Восстановление сертификатов Tesla — NikolaCars'
  : 'Відновлення сертифікатів Tesla — NikolaCars'
)

@section('description', $isRu
  ? 'Официальное восстановление сертификатов Tesla: OTA-обновления, Tesla App, синхронизация с серверами. Установка в Киеве с гарантией.'
  : 'Офіційне відновлення сертифікатів Tesla: OTA-оновлення, Tesla App, синхронізація з серверами. Встановлення в Києві з гарантією.'
)

@section('content')
<section class="service-hero">
  <div class="hero-wrap">
    <div class="service-hero-slider" aria-label="Hero">
      <div class="service-hero-slide" style="background: url('/assets/img/slider/vidnovlennya-sertyfikativ.webp') center/cover no-repeat, #000;">
        <div class="service-hero-inner">
          <div class="service-hero-content">

            {{-- LEFT TEXT --}}
            <div class="service-hero-left">
              <h1 class="service-hero-title">
                {{ $isRu ? 'Восстановление сертификатов Tesla на нашем СТО' : 'Відновлення сертифікатів Tesla на нашому СТО' }}
              </h1>
              <div class="service-hero-subtitle">
                {{ $isRu ? 'Верните полный функционал вашего авто у настоящих профессионалов' : 'Поверніть повний функціонал вашого авто у справжніх професіоналів' }}
              </div>
            </div>

            {{-- RIGHT FORM (как на скрине) --}}
            <form class="call-form" id="callForm" action="{{ route('lead.callback') }}" method="post" novalidate>
              @csrf

              <div class="call-form-title">{{ $isRu ? 'ЗАКАЗАТЬ ЗВОНОК' : 'ЗАМОВИТИ ДЗВІНОК' }}</div>
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
        {{ $isRu ? 'ВОССТАНОВЛЕНИЕ СЕРТИФИКАТОВ TESLA' : 'ВІДНОВЛЕННЯ СЕРТИФІКАТІВ TESLA' }}
      </h2>

      <div class="service-article-lines">
        <span></span><span></span>
      </div>

      <div class="service-article-text">

        <h3>{{ $isRu ? 'С гарантией через официального дилера' : 'З гарантією через офіційного дилера' }}</h3>

        <p>
          {{ $isRu
            ? 'Если ваша Tesla перестала обновляться, не подключается к приложению или возникают ошибки при загрузке обновлений — чаще всего причина в утерянных или просроченных сертификатах.'
            : "Якщо ваша Tesla перестала оновлюватися, не підключається до додатку або виникають помилки під час завантаження оновлень — найчастіше причина у втрачених або прострочених сертифікатах."
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Мы предлагаем официальное восстановление сертификатов Tesla через официальный сервис Tesla с гарантией стабильной работы обновлений в будущем.'
            : 'Ми пропонуємо офіційне відновлення сертифікатів Tesla через офіційний сервіс Tesla, з гарантією стабільної роботи оновлень у майбутньому.'
          }}
        </p>

        <h3>{{ $isRu ? 'Почему важно восстанавливать сертификаты официально' : 'Чому важливо відновлювати сертифікати офіційно' }}</h3>

        <p>
          {{ $isRu
            ? 'На рынке много предложений с низкой ценой, однако в большинстве таких случаев восстановление выполняется без официального сервиса Tesla. На практике это часто приводит к повторным сбоям, проблемам с обновлениями и потере доступа к Tesla App.'
            : 'На ринку багато пропозицій з низькою ціною, однак у більшості таких випадків відновлення виконується без офіційного сервісу Tesla. На практиці це часто призводить до повторних збоїв, проблем з оновленнями та втрати доступу до Tesla App.'
          }}
        </p>

        <p>{{ $isRu ? 'Мы работаем только официальным путем, поэтому:' : 'Ми працюємо виключно офіційним шляхом, тому:' }}</p>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'сертификаты восстанавливаются корректно;' : 'сертифікати відновлюються коректно;' }}</li>
          <li>{{ $isRu ? 'обновления далее устанавливаются стабильно;' : 'оновлення надалі встановлюються стабільно;' }}</li>
          <li>{{ $isRu ? 'автомобиль остаётся полностью совместимым с серверами Tesla.' : 'автомобіль залишається повністю сумісним із серверами Tesla.' }}</li>
        </ul>

        <p><strong>{{ $isRu ? 'Выбор способа восстановления — за вами.' : 'Вибір способу відновлення — за вами.' }}</strong></p>

        <h3>{{ $isRu ? 'Что вы получаете' : 'Що ви отримуєте' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'Официальное восстановление сертификатов Tesla;' : 'Офіційне відновлення сертифікатів Tesla;' }}</li>
          <li>{{ $isRu ? 'Стабильные OTA-обновления;' : 'Стабільні OTA-оновлення;' }}</li>
          <li>{{ $isRu ? 'Корректную работу Tesla App;' : 'Коректну роботу Tesla App;' }}</li>
          <li>{{ $isRu ? 'Гарантию на выполненные работы;' : 'Гарантію на виконані роботи;' }}</li>
          <li>{{ $isRu ? 'Прозрачный и безопасный процесс.' : 'Прозорий та безпечний процес.' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Как проходит процесс восстановления' : 'Як проходить процес відновлення' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'Заказываем сертификаты индивидуально под ваше авто;' : 'Замовляємо сертифікати індивідуально під ваше авто;' }}</li>
          <li>{{ $isRu ? 'Стандартный срок ожидания — 2–3 дня (в некоторых случаях — до 1 недели);' : 'Стандартний термін очікування — 2–3 дні (у деяких випадках — до 1 тижня);' }}</li>
          <li>{{ $isRu ? 'В день восстановления желательно заехать к нам на станцию для стабильного интернет-соединения;' : 'У день відновлення бажано заїхати до нас на станцію для забезпечення стабільного інтернет-зʼєднання;' }}</li>
          <li>{{ $isRu ? 'Адрес: г. Киев, ул. Коллекторная, 30;' : 'Адреса: м. Київ, вул. Колекторна, 30;' }}</li>
          <li>{{ $isRu ? 'В течение нескольких часов устанавливаем сертификаты на ваш автомобиль;' : 'Протягом декількох годин встановлюємо сертифікати на ваш автомобіль;' }}</li>
          <li>{{ $isRu ? 'Без разбора авто, без пайки, без сторонних вмешательств.' : 'Без розбору авто, без пайки, без сторонніх втручань.' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Запись на консультацию' : 'Запис на консультацію' }}</h3>

        <p>
          {!! $isRu
            ? 'Оставьте заявку — мы проверим состояние сертификатов вашей Tesla и предложим оптимальное решение. Или звоните по номеру <a href="tel:+380975120255">+38 (097) 512 02 55</a>.'
            : 'Залиште заявку — ми перевіримо стан сертифікатів вашої Tesla та запропонуємо оптимальне рішення. Або телефонуйте за номером <a href="tel:+380975120255">+38 (097) 512 02 55</a>.'
          !!}
        </p>

        <p><strong>{{ $isRu ? 'Официально. Надёжно. С гарантией.' : 'Офіційно. Надійно. З гарантією.' }}</strong></p>

      </div>
    </div>
  </div>

  <section class="reviews">
    <div class="container">
      <div class="reviews-head">
        <div class="reviews-kicker">{{ $isRu ? 'Что говорят о нас наши счастливые клиенты' : 'Що говорять про нас наші щасливі клієнти' }}</div>
        <h2 class="reviews-title">{{ $isRu ? 'ОТЗЫВЫ' : 'ВІДГУКИ' }}</h2>
        <div class="reviews-lines"><span></span><span></span></div>
      </div>

      <div class="reviews-slider" id="reviewsSlider" aria-label="{{ $isRu ? 'Отзывы' : 'Відгуки' }}">
        <button class="reviews-arrow prev" type="button" aria-label="Previous">‹</button>
        <button class="reviews-arrow next" type="button" aria-label="Next">›</button>

        <div class="reviews-viewport">
          <div class="reviews-track">
            {{-- отзывы оставляем как есть --}}
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
        <div class="contacts-lines"><span></span><span></span></div>
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
              <div class="contact-label">ТЕЛЕФОН</div>
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

        <h2>{{ $isRu ? 'Официальное восстановление сертификатов Tesla через сервис Tesla' : 'Відновлення сертифікатів Tesla офіційно через сервіс Tesla' }}</h2>

        <p>
          {{ $isRu
            ? 'Сертификаты Tesla — ключевой элемент, без которого автомобиль теряет значительную часть функционала. Они отвечают за OTA-обновления, корректную работу Tesla App, синхронизацию с серверами Tesla, навигацию, онлайн-сервисы и безопасность соединения.'
            : 'Сертифікати Tesla — це ключовий елемент, без якого автомобіль втрачає значну частину свого функціоналу. Саме вони відповідають за отримання OTA-оновлень, коректну роботу Tesla App, синхронізацію з серверами Tesla, навігацію, онлайн-сервіси та безпеку з’єднання.'
          }}
        </p>

        <p>{{ $isRu ? 'Если ваша Tesla:' : 'Якщо ваша Tesla:' }}</p>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'не получает обновления;' : 'не отримує оновлення;' }}</li>
          <li>{{ $isRu ? 'зависает при загрузке на 0%, 50% или 100%;' : 'зависає під час завантаження на 0%, 50% або 100%;' }}</li>
          <li>{{ $isRu ? 'долго показывает 0 B/s;' : 'довго показує 0 B/s;' }}</li>
          <li>{{ $isRu ? 'не подключается к Tesla App;' : 'не підключається до Tesla App;' }}</li>
          <li>{{ $isRu ? 'потеряла европейскую навигацию;' : 'втратила європейську навігацію;' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'в большинстве случаев проблема именно в утерянных или просроченных сертификатах Tesla.'
            : 'у більшості випадків проблема полягає саме у втрачених або прострочених сертифікатах Tesla.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Мы предлагаем официальное восстановление сертификатов Tesla через официальный сервис Tesla с гарантией стабильной работы в будущем.'
            : 'Ми пропонуємо офіційне відновлення сертифікатів Tesla через офіційний сервіс Tesla з гарантією стабільної роботи у майбутньому.'
          }}
        </p>

        <h3>{{ $isRu ? 'Почему в Tesla «слетают» сертификаты' : 'Чому в Tesla злітають сертифікати' }}</h3>

        <p>{{ $isRu ? 'Самые распространённые причины:' : 'Найпоширеніші причини:' }}</p>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'автомобиль долго не был подключён к интернету;' : 'автомобіль тривалий час не був підключений до інтернету;' }}</li>
          <li>{{ $isRu ? 'авто было импортировано из другого региона;' : 'авто було імпортоване з іншого регіону;' }}</li>
          <li>{{ $isRu ? 'использовались некорректные/неофициальные вмешательства;' : 'використовувалося некоректне або неофіційне втручання;' }}</li>
          <li>{{ $isRu ? 'менялись модули без последующей синхронизации;' : 'змінювалися модулі без подальшої синхронізації;' }}</li>
          <li>{{ $isRu ? 'авто долго не получало OTA-обновления.' : 'авто довго не отримувало OTA-оновлення.' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'В результате Tesla перестаёт «доверять» соединению с серверами, и доступ к сервисам ограничивается.'
            : 'У результаті Tesla перестає «довіряти» з’єднанню з серверами, і доступ до сервісів обмежується.'
          }}
        </p>

        <h3>{{ $isRu ? 'Почему важно выбирать официальное восстановление' : 'Чому важливо обирати офіційне відновлення' }}</h3>

        <p>
          {{ $isRu
            ? 'На рынке можно найти предложения дешевле, но не все из них работают через официальные каналы Tesla.'
            : 'На ринку можна знайти пропозиції з нижчою вартістю, проте не всі з них передбачають роботу через офіційні канали Tesla.'
          }}
        </p>

        <p>{{ $isRu ? 'Последствия неофициального вмешательства:' : 'Наслідки неофіційного втручання:' }}</p>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'повторные ошибки обновлений;' : 'повторні помилки оновлень;' }}</li>
          <li>{{ $isRu ? 'проблемы с Tesla App;' : 'проблеми з Tesla App;' }}</li>
          <li>{{ $isRu ? 'сбои навигации;' : 'збої навігації;' }}</li>
          <li>{{ $isRu ? 'блокировка сервисов после очередного OTA.' : 'блокування сервісів після чергового OTA.' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'Мы принципиально работаем только официальным путём — это помогает избежать подобных проблем.'
            : 'Ми принципово працюємо виключно офіційним шляхом, що дозволяє уникнути цих проблем.'
          }}
        </p>

        <h3>{{ $isRu ? 'Как проходит восстановление сертификатов Tesla' : 'Як проходить відновлення сертифікатів Tesla' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'проверка состояния сертификатов и подключения автомобиля;' : 'перевірка стану сертифікатів та підключення автомобіля;' }}</li>
          <li>{{ $isRu ? 'заказ сертификатов индивидуально под конкретное авто;' : 'замовлення сертифікатів індивідуально під конкретне авто;' }}</li>
          <li>{{ $isRu ? 'стандартный срок ожидания — 2–3 дня (иногда до 7 дней);' : 'стандартний термін очікування — 2–3 дні (інколи до 7 днів);' }}</li>
          <li>{{ $isRu ? 'в день восстановления рекомендуем заехать к нам на станцию;' : 'у день відновлення рекомендуємо заїхати до нас на станцію;' }}</li>
          <li>{{ $isRu ? 'адрес: г. Киев, ул. Коллекторная, 30;' : 'адреса: м. Київ, вул. Колекторна, 30;' }}</li>
          <li>{{ $isRu ? 'обеспечиваем стабильное и быстрое интернет-соединение;' : "забезпечуємо стабільне та швидке інтернет-зʼєднання;" }}</li>
          <li>{{ $isRu ? 'в течение нескольких часов устанавливаем сертификаты на автомобиль.' : 'протягом кількох годин встановлюємо сертифікати на автомобіль.' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'Все работы выполняются без разбора авто, без пайки и без физических вмешательств.'
            : 'Всі роботи виконуються без розбору авто, без пайки, без фізичних втручань.'
          }}
        </p>

        <h3>{{ $isRu ? 'Что вы получите после восстановления' : 'Що ви отримуєте після відновлення' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'стабильные OTA-обновления программного обеспечения;' : 'стабільні OTA-оновлення програмного забезпечення;' }}</li>
          <li>{{ $isRu ? 'полноценную работу Tesla App;' : 'повноцінну роботу Tesla App;' }}</li>
          <li>{{ $isRu ? 'корректную синхронизацию с серверами Tesla;' : 'коректну синхронізацію з серверами Tesla;' }}</li>
          <li>{{ $isRu ? 'европейскую навигацию и карты (для поддерживаемых авто);' : 'європейську навігацію та карти (для авто, що підтримують);' }}</li>
          <li>{{ $isRu ? 'сохранение рыночной стоимости автомобиля;' : 'збереження вартості автомобіля;' }}</li>
          <li>{{ $isRu ? 'гарантию на выполненные работы.' : 'гарантію на виконані роботи.' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Для каких моделей подходит услуга' : 'Для яких моделей підходить послуга' }}</h3>

        <ul class="service-final-list">
          <li>Tesla Model S;</li>
          <li>Tesla Model 3;</li>
          <li>Tesla Model X;</li>
          <li>Tesla Model Y.</li>
        </ul>

        <p>
          {{ $isRu
            ? 'Услуга подходит для автомобилей, импортированных из Европы и США.'
            : 'Послуга підходить для автомобілів, імпортованих з Європи та США.'
          }}
        </p>

        <h3>{{ $isRu ? 'Почему это важно для стоимости автомобиля' : 'Чому це важливо для вартості автомобіля' }}</h3>

        <p>
          {{ $isRu
            ? 'Tesla без актуальных сертификатов заметно теряет рыночную стоимость. Отсутствие обновлений и Tesla App — серьёзный минус при продаже и дальнейшей эксплуатации. Официальное восстановление сертификатов возвращает полный функционал и сохраняет ликвидность авто.'
            : 'Tesla без актуальних сертифікатів значно втрачає свою ринкову цінність. Відсутність оновлень і Tesla App — це серйозний мінус при продажу або подальшій експлуатації. Офіційне відновлення сертифікатів дозволяє повернути автомобілю повний функціонал та зберегти його ліквідність.'
          }}
        </p>

        <h3>{{ $isRu ? 'Запись на консультацию' : 'Запис на консультацію' }}</h3>

        <p>
          {{ $isRu
            ? 'Мы предоставим полную консультацию, проверим состояние сертификатов вашей Tesla и предложим оптимальное официальное решение.'
            : 'Ми надамо повну консультацію, перевіримо стан сертифікатів вашої Tesla та запропонуємо оптимальне офіційне рішення.'
          }}
        </p>

        <p><strong>{{ $isRu ? 'Официально. Безопасно. С гарантией.' : 'Офіційно. Безпечно. З гарантією.' }}</strong></p>

      </div>
    </div>
  </section>
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
    const uaPhoneRegex = /^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/;

    if (!uaPhoneRegex.test(fullPhone)) {
      alert(@json($isRu ? 'Введите корректный украинский номер (9 цифр после +380)' : 'Введіть коректний український номер (9 цифр після +380)'));
      phoneInput.focus();
      return;
    }

    // UI
    form.classList.remove('is-success');
    if (submitBtn) submitBtn.disabled = true;

    const fd = new FormData(form);
    fd.set('phone', fullPhone);
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
        alert(@json($isRu ? 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.' : 'Не вдалося відправити заявку. Спробуйте ще раз або зателефонуйте нам.'));
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
