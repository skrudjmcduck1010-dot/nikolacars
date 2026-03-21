@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Замена и ремонт ручки Tesla 🛠️ NikolaCars'
  : 'Заміна та ремонт ручки Tesla 🛠️ NikolaCars'
)

@section('description', $isRu
  ? 'Замена и ремонт ручки дверей Tesla в Киеве: диагностика, восстановление механизма, калибровка и настройка soft-close. Запишитесь на сервис NikolaCars.'
  : 'Заміна та ремонт ручки дверей Tesla у Києві: діагностика, відновлення механізму, калібрування та налаштування soft-close. Запишіться на сервіс NikolaCars.'
)

@section('content')
<section class="service-hero">
  <div class="hero-wrap">
    <div class="service-hero-slider" aria-label="Hero">
      <div class="service-hero-slide" style="background: url('/assets/img/slider/tesla-service.webp') center/cover no-repeat, #000;">
        <div class="service-hero-inner">
          <div class="service-hero-content">

            {{-- LEFT TEXT --}}
            <div class="service-hero-left">
              <h1 class="service-hero-title">
                {{ $isRu ? 'Замена и ремонт ручки двери Tesla в Киеве' : 'Заміна та ремонт ручки дверей Tesla у Києві' }}
              </h1>
              <div class="service-hero-subtitle">
                {{ $isRu
                  ? 'Проблемы с ручкой Tesla? Восстановим привод, тросы, микрики и выполним калибровку без лишних затрат.'
                  : 'Проблеми з ручкою Tesla? Відновимо привід, троси, мікрики та виконаємо калібрування без зайвих витрат.'
                }}
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

      <h2 class="service-article-h1">{{ $isRu ? 'РЕМОНТ РУЧКИ TESLA В КИЕВЕ' : 'РЕМОНТ РУЧКИ TESLA У КИЄВІ' }}</h2>

      <div class="service-article-lines">
        <span></span><span></span>
      </div>

      <div class="service-article-text">

        <p>
          {!! $isRu
            ? 'Сломалась или заедает ручка двери? На СТО NikolaCars мы выполняем <strong>ремонт ручки Tesla</strong> и полную замену узла для моделей Tesla Model 3, Tesla Model Y, Tesla Model X и Tesla Model S. Работаем с оригинальными и проверенными компонентами, чтобы дверной механизм снова работал мягко и без ошибок.'
            : 'Зламалась або заїдає ручка дверей? На СТО NikolaCars ми виконуємо <strong>ремонт ручки Tesla</strong> та повну заміну вузла для моделей Tesla Model 3, Tesla Model Y, Tesla Model X і Tesla Model S. Працюємо з оригінальними та перевіреними компонентами, щоб дверний механізм знову працював м’яко і без помилок.'
          !!}
        </p>

        <p>
          {!! $isRu
            ? 'Частые симптомы: ручка не выдвигается, не фиксируется, дверь открывается только изнутри, либо на экране появляется ошибка доступа. Мы проводим компьютерную диагностику Tesla, проверяем привод, шлейф, микропереключатели и электронный блок, после чего согласовываем ремонт и сроки.'
            : 'Поширені симптоми: ручка не висувається, не фіксується, двері відкриваються лише зсередини, або на екрані з’являється помилка доступу. Ми проводимо комп’ютерну діагностику Tesla, перевіряємо привід, шлейф, мікроперемикачі та електронний блок, після чого погоджуємо ремонт і строки.'
          !!}
        </p>

        <p>
          {{ $isRu
            ? 'Для долгого ресурса узла важно не только заменить ручку Tesla, но и корректно выполнить калибровку, проверить геометрию двери и герметичность после работ. Это особенно актуально после ДТП, зимней эксплуатации и активного использования автодоводчиков.'
            : 'Для довгого ресурсу вузла важливо не лише замінити ручку Tesla, а й коректно виконати калібрування, перевірити геометрію дверей та герметичність після робіт. Це особливо актуально після ДТП, зимової експлуатації та активного використання автодоводчиків.'
          }}
        </p>

        <p>
          {!! $isRu
            ? 'Чтобы ручка служила дольше, после ремонта важно исключить перекос двери, проверить уплотнения и убрать люфты креплений. Мы всегда показываем, какие детали требуют замены сейчас, а какие можно оставить без лишних затрат.'
            : 'Щоб ручка служила довше, після ремонту важливо прибрати перекіс дверей, перевірити ущільнення й усунути люфти кріплень. Ми завжди показуємо, які деталі треба змінити зараз, а які можна залишити без зайвих витрат.'
          !!}
        </p>

        <h3>{{ $isRu ? 'Что входит в замену и ремонт ручки Tesla' : 'Що входить у заміну та ремонт ручки Tesla' }}</h3>

        <p>
          {!! $isRu
            ? 'Диагностика и ремонт ручки двери Tesla обычно включают проверку проводки в двери, восстановление механизма выдвижения, замену изношенных компонентов, сборку с корректным моментом затяжки и финальную калибровку работы ручки.'
            : 'Діагностика та ремонт ручки дверей Tesla зазвичай включають перевірку проводки у дверях, відновлення механізму висування, заміну зношених компонентів, складання з правильним моментом затягування та фінальне калібрування роботи ручки.'
          !!}
        </p>

        <p>
          {{ $isRu
            ? 'Ниже вы можете ознакомиться с нашим подходом к работе по ремонту ручки Tesla и этапами записи.'
            : 'Нижче ви можете ознайомитися з нашим підходом до ремонту ручки Tesla та етапами запису.'
          }}
        </p>

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

        <h2>{{ $isRu ? 'Ручка Tesla: типовые поломки и правильный ремонт' : 'Ручка Tesla: типові поломки та правильний ремонт' }}</h2>

        <p>
          {{ $isRu
            ? 'Чаще всего ручка двери Tesla выходит из строя из-за износа микропереключателя, растянутого троса, влаги внутри корпуса или повреждения привода. Симптомы знакомые: ручка не выдвигается, не фиксируется или срабатывает через раз.'
            : 'Найчастіше ручка дверей Tesla виходить з ладу через знос мікроперемикача, розтягнений трос, вологу всередині корпусу або пошкодження приводу. Симптоми знайомі: ручка не висувається, не фіксується або спрацьовує через раз.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Правильный ремонт начинается с дефектовки именно узла ручки: проверяем мотор, тягу, концевики, проводку и посадочные места. После восстановления обязательно калибруем ход ручки и проверяем работу центрального замка, чтобы дверь открывалась стабильно с первого касания.'
            : 'Правильний ремонт починається з дефектування саме вузла ручки: перевіряємо мотор, тягу, кінцевики, проводку та посадкові місця. Після відновлення обов’язково калібруємо хід ручки й перевіряємо роботу центрального замка, щоб двері відкривалися стабільно з першого дотику.'
          }}
        </p>

        <h3>{{ $isRu ? 'Что обычно меняют при ремонте ручки Tesla' : 'Що зазвичай змінюють під час ремонту ручки Tesla' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'микропереключатели и контакты ручки;' : 'мікроперемикачі та контакти ручки;' }}</li>
          <li>{{ $isRu ? 'трос/тягу механизма выдвижения;' : 'трос/тягу механізму висування;' }}</li>
          <li>{{ $isRu ? 'мотор привода и элементы крепления;' : 'мотор приводу та елементи кріплення;' }}</li>
          <li>{{ $isRu ? 'уплотнения и влагозащиту корпуса ручки.' : 'ущільнення та вологозахист корпусу ручки.' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Когда нужна полная замена ручки' : 'Коли потрібна повна заміна ручки' }}</h3>

        <p>
          {{ $isRu
            ? 'Если повреждён корпус, сорваны посадочные точки или ручка имеет сильный люфт после предыдущего ремонта, лучше поставить новый узел. Это быстрее и надёжнее, чем восстанавливать критически изношенные детали.'
            : 'Якщо пошкоджено корпус, зірвані посадкові точки або ручка має сильний люфт після попереднього ремонту, краще встановити новий вузол. Це швидше та надійніше, ніж відновлювати критично зношені деталі.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'После замены мы проверяем открытие снаружи и из салона, отсутствие ошибок по замку и равномерный ход ручки во всех режимах. В результате дверь Tesla работает предсказуемо, без закусываний и повторных отказов.'
            : 'Після заміни ми перевіряємо відкриття ззовні та з салону, відсутність помилок по замку й рівномірний хід ручки в усіх режимах. У результаті двері Tesla працюють передбачувано, без заклинювань і повторних відмов.'
          }}
        </p>

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
