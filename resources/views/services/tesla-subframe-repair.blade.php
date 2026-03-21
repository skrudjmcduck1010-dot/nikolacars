@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Замена и ремонт подрамника Tesla 🛠️ NikolaCars'
  : 'Заміна та ремонт підрамника Tesla 🛠️ NikolaCars'
)

@section('description', $isRu
  ? 'Замена и ремонт подрамника Tesla в Киеве: диагностика геометрии, восстановление креплений и профессиональная установка. Сервис NikolaCars.'
  : 'Заміна та ремонт підрамника Tesla у Києві: діагностика геометрії, відновлення кріплень і професійне встановлення. Сервіс NikolaCars.'
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
                {{ $isRu ? 'Замена и ремонт подрамника Tesla в Киеве' : 'Заміна та ремонт підрамника Tesla у Києві' }}
              </h1>
              <div class="service-hero-subtitle">
                {{ $isRu
                  ? 'Удары, коррозия или люфты? Восстановим подрамник Tesla и безопасную управляемость автомобиля.'
                  : 'Удари, корозія чи люфти? Відновимо підрамник Tesla та безпечну керованість автомобіля.'
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

      <h2 class="service-article-h1">{{ $isRu ? 'РЕМОНТ ПОДРАМНИКА TESLA В КИЕВЕ' : 'РЕМОНТ ПІДРАМНИКА TESLA У КИЄВІ' }}</h2>

      <div class="service-article-lines">
        <span></span><span></span>
      </div>

      <div class="service-article-text">

        <p>
          {!! $isRu
            ? 'Подрамник отвечает за точную геометрию подвески и безопасность на скорости. В NikolaCars мы выполняем <strong>ремонт подрамника Tesla</strong> и замену узла для Tesla Model 3, Tesla Model Y, Tesla Model X и Tesla Model S. Перед работами проводим замер геометрии, дефектовку креплений и проверку смежных элементов.'
            : 'Підрамник відповідає за точну геометрію підвіски та безпеку на швидкості. У NikolaCars ми виконуємо <strong>ремонт підрамника Tesla</strong> та заміну вузла для Tesla Model 3, Tesla Model Y, Tesla Model X і Tesla Model S. Перед роботами проводимо замір геометрії, дефектування кріплень та перевірку суміжних елементів.'
          !!}
        </p>

        <p>
          {!! $isRu
            ? 'Если после наезда на яму, бордюр или ДТП появились вибрации, увод автомобиля, неравномерный износ резины или стуки — это повод проверить подрамник и рычаги. Наши мастера используют профильный инструмент, стендовые измерения и корректные моменты затяжки, чтобы Tesla сохранила заводскую управляемость.'
            : 'Якщо після наїзду на яму, бордюр або ДТП з’явилися вібрації, відведення авто, нерівномірний знос гуми чи стуки — це привід перевірити підрамник і важелі. Наші майстри використовують профільний інструмент, стендові вимірювання та коректні моменти затягування, щоб Tesla зберегла заводську керованість.'
          !!}
        </p>

        <p>
          {!! $isRu
            ? 'После ремонта подрамника Tesla мы обязательно выполняем контрольный тест-драйв и рекомендуем развал-схождение. При необходимости подбираем усиленные элементы и антикоррозийную защиту, чтобы продлить ресурс узла в украинских дорожных условиях.'
            : 'Після ремонту підрамника Tesla ми обов’язково виконуємо контрольний тест-драйв і рекомендуємо розвал-сходження. За потреби підбираємо посилені елементи та антикорозійний захист, щоб подовжити ресурс вузла в українських дорожніх умовах.'
          !!}
        </p>

        <p>
          {!! $isRu
            ? 'Даже если внешне повреждение кажется небольшим, важно проверить геометрию креплений подрамника и сопряжённых деталей подвески. Это позволяет исключить скрытый перекос и повторный износ после ремонта.'
            : 'Навіть якщо зовні пошкодження здається невеликим, важливо перевірити геометрію кріплень підрамника та суміжних деталей підвіски. Це допомагає виключити прихований перекіс і повторний знос після ремонту.'
          !!}
        </p>

        <h3>{{ $isRu ? 'Что входит в ремонт подрамника Tesla' : 'Що входить у ремонт підрамника Tesla' }}</h3>

        <p>
          {!! $isRu
            ? 'Ремонт подрамника Tesla включает измерение геометрии, контроль состояния креплений, проверку рычагов и сайлентблоков, восстановление или замену деформированных элементов, а затем контрольную проверку подвески и поведения автомобиля в движении.'
            : 'Ремонт підрамника Tesla включає вимірювання геометрії, контроль стану кріплень, перевірку важелів і сайлентблоків, відновлення або заміну деформованих елементів, а далі контрольну перевірку підвіски та поведінки автомобіля в русі.'
          !!}
        </p>

        <p>
          {{ $isRu
            ? 'Ниже вы можете ознакомиться с нашим подходом к ремонту подрамника Tesla и этапами записи.'
            : 'Нижче ви можете ознайомитися з нашим підходом до ремонту підрамника Tesla та етапами запису.'
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

        <h2>{{ $isRu ? 'Подрамник Tesla: ремонт без потери геометрии и безопасности' : 'Підрамник Tesla: ремонт без втрати геометрії та безпеки' }}</h2>

        <p>
          {{ $isRu
            ? 'Подрамник Tesla воспринимает ударные нагрузки подвески и задаёт точное положение рычагов. После удара в яму, контакта с бордюром или ДТП он может получить скрытую деформацию: снаружи это выглядит как увод автомобиля, вибрация руля или быстрый износ шин.'
            : 'Підрамник Tesla сприймає ударні навантаження підвіски та задає точне положення важелів. Після удару в яму, контакту з бордюром або ДТП він може отримати приховану деформацію: зовні це виглядає як відведення авто, вібрація керма або швидкий знос шин.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Ремонт подрамника начинается с измерений: проверяем точки крепления, сварные зоны, геометрию лонжеронов и сопряжение с подвеской. Если восстановление безопасно — исправляем деформации и меняем повреждённые крепёжные элементы. Если узел критически повреждён, выполняем замену подрамника Tesla в сборе.'
            : 'Ремонт підрамника починається з вимірювань: перевіряємо точки кріплення, зварні зони, геометрію лонжеронів і спряження з підвіскою. Якщо відновлення безпечне — виправляємо деформації та змінюємо пошкоджені кріпильні елементи. Якщо вузол критично пошкоджений, виконуємо заміну підрамника Tesla у зборі.'
          }}
        </p>

        <h3>{{ $isRu ? 'Что включает ремонт подрамника Tesla' : 'Що включає ремонт підрамника Tesla' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'контроль геометрии и проверку посадочных размеров;' : 'контроль геометрії та перевірку посадкових розмірів;' }}</li>
          <li>{{ $isRu ? 'диагностику креплений, рычагов и сайлентблоков;' : 'діагностику кріплень, важелів і сайлентблоків;' }}</li>
          <li>{{ $isRu ? 'восстановление или замену повреждённых участков;' : 'відновлення або заміну пошкоджених ділянок;' }}</li>
          <li>{{ $isRu ? 'контрольную сборку и проверку подвески под нагрузкой.' : 'контрольне складання та перевірку підвіски під навантаженням.' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Почему нельзя откладывать ремонт подрамника' : 'Чому не можна відкладати ремонт підрамника' }}</h3>

        <p>
          {{ $isRu
            ? 'Даже небольшой перекос подрамника влияет на управляемость, торможение и ресурс шин. На скорости это может снижать стабильность автомобиля и увеличивать нагрузку на соседние элементы подвески.'
            : 'Навіть невеликий перекіс підрамника впливає на керованість, гальмування та ресурс шин. На швидкості це може знижувати стабільність автомобіля та збільшувати навантаження на сусідні елементи підвіски.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'После ремонта подрамника Tesla важно выполнить проверку углов установки колёс и контрольный тест-драйв. Это подтверждает, что геометрия восстановлена, а автомобиль снова едет ровно и предсказуемо.'
            : 'Після ремонту підрамника Tesla важливо виконати перевірку кутів встановлення коліс і контрольний тест-драйв. Це підтверджує, що геометрію відновлено, а автомобіль знову їде рівно та передбачувано.'
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
