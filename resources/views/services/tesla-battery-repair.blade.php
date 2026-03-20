@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Ремонт батареи Tesla в Киеве — NikolaCars'
  : 'Ремонт батареї Tesla у Києві — NikolaCars'
)

@section('description', $isRu
  ? 'Ремонт батарей Tesla в Киеве: диагностика, восстановление модулей, балансировка и обслуживание высоковольтной батареи.'
  : 'Ремонт батарей Tesla у Києві: діагностика, відновлення модулів, балансування та обслуговування високовольтної батареї.'
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
                {{ $isRu ? 'Ремонт батарей Tesla в Киеве' : 'Ремонт батарей Tesla у Києві' }}
              </h1>
              <div class="service-hero-subtitle">
                {{ $isRu
                  ? 'Диагностика HV батареи, ремонт модулей, замена элементов и восстановление производительности.'
                  : 'Діагностика HV батареї, ремонт модулів, заміна елементів та відновлення продуктивності.'
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

      <h2 class="service-article-h1">{{ $isRu ? 'РЕМОНТ БАТАРЕЙ TESLA В КИЕВЕ' : 'РЕМОНТ БАТАРЕЙ TESLA У КИЄВІ' }}</h2>

      <div class="service-article-lines">
        <span></span><span></span>
      </div>

      <div class="service-article-text">

        <p>
          {{ $isRu
            ? 'Предоставляем профессиональные услуги по <strong>ремонту высоковольтных батарей Tesla</strong> любой модели.'
            : 'Надаємо професійні послуги з <strong>ремонту високовольтних батарей Tesla</strong> будь-якої моделі.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Если батарея ушла в разбаланс — это не проблема. Не нужно спешить менять батарею целиком, ведь с другой может повториться такая же ситуация. Мы устраняем причину проблемы, а не её последствия.'
            : 'Якщо батарея пішла в розбаланс — це не проблема. Не потрібно поспішати міняти батарею на іншу, адже з нею може статися така ж ситуація. Ми усуваємо причину проблеми, а не її наслідки.'
          }}
        </p>

        <h3>{{ $isRu ? 'Что мы делаем' : 'Що ми робимо' }}</h3>

        <ul>
          <li>{{ $isRu ? 'Точно определяем проблемный элемент' : 'Точно визначаємо проблемний елемент' }}</li>
          <li>{{ $isRu ? 'Заменяем неисправный модуль' : 'Замінюємо несправний модуль' }}</li>
          <li>{{ $isRu ? 'Проводим полную диагностику батареи после ремонта' : 'Проводимо повну діагностику батареї після ремонту' }}</li>
          <li>{{ $isRu ? 'Предоставляем гарантию на выполненные работы' : 'Надаємо гарантію на виконані роботи' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Наш опыт' : 'Наш досвід' }}</h3>

        <ul>
          <li>{{ $isRu ? 'Более 200 отремонтированных батарей Tesla' : 'Понад 200 відремонтованих батарей Tesla' }}</li>
          <li>{{ $isRu ? '3 года практического опыта работы с HV-батареями' : '3 роки практичного досвіду роботи з HV-батареями' }}</li>
        </ul>

        <h3>{{ $isRu ? 'Работаем с ошибками BMS' : 'Працюємо з помилками BMS' }}</h3>

        <ul>
          <li>BMS_u029</li>
          <li>BMS_u018</li>
          <li>BMS_a062</li>
          <li>BMS_a064</li>
          <li>BMS_a142</li>
          <li>BMS_a027</li>
          <li>BMS_a079</li>
          <li>{{ $isRu ? 'и другими подобными ошибками' : 'та іншими подібними помилками' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'Если у вас появились ошибки BMS или батарея потеряла баланс — обращайтесь. Выполним профессиональный ремонт без замены всей батареи и с обязательной гарантией в нашем сервисе.'
            : 'Якщо у вас з’явилися помилки BMS або батарея втратила баланс — звертайтесь. Виконаємо професійний ремонт без заміни всієї батареї та з обов’язковою гарантією у нашому сервісі.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Звоните или пишите. Консультация бесплатная.'
            : 'Телефонуйте або пишіть. Консультація безкоштовна.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Примерное время ремонта: 3–5 дней.'
            : 'Приблизний час ремонту: 3-5 днів.'
          }}
        </p>

      </div>
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

        <h2>{{ $isRu ? 'Ремонт батарей Tesla: инструкция и реальность' : 'Ремонт батарей Tesla: інструкція та реальність' }}</h2>

        <p>
          {{ $isRu
            ? 'Ремонт HV-батареи Tesla — это точная техническая работа, где важно не просто убрать ошибку, а найти её первопричину. В NikolaCars мы начинаем с глубокой диагностики батареи, BMS и смежных систем, чтобы предложить именно тот ремонт, который нужен вашему автомобилю.'
            : 'Ремонт HV-батареї Tesla — це точна технічна робота, де важливо не просто прибрати помилку, а знайти її першопричину. У NikolaCars ми починаємо з глибокої діагностики батареї, BMS і суміжних систем, щоб запропонувати саме той ремонт, який потрібен вашому авто.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Чаще всего владельцы сталкиваются с разбалансом, падением запаса хода и ошибками BMS. Во многих случаях это решается без замены всей батареи: мы локализуем неисправный модуль, выполняем ремонт или замену элементов, после чего проводим контрольные тесты под нагрузкой.'
            : 'Найчастіше власники стикаються з розбалансом, падінням запасу ходу та помилками BMS. У багатьох випадках це вирішується без заміни всієї батареї: ми локалізуємо несправний модуль, виконуємо ремонт або заміну елементів, після чого проводимо контрольні тести під навантаженням.'
          }}
        </p>

        <p>
          {{ $isRu
            ? 'Мы фиксируем результаты диагностики и ремонта, согласовываем этапы работ и обязательно даём гарантию. Вы понимаете, что делается с батареей, сколько это занимает времени и какой результат получаете на выходе.'
            : 'Ми фіксуємо результати діагностики та ремонту, погоджуємо етапи робіт і обов’язково надаємо гарантію. Ви розумієте, що саме робиться з батареєю, скільки це займає часу та який результат отримуєте на виході.'
          }}
        </p>

        <h3>{{ $isRu ? 'Когда стоит обращаться на диагностику батареи Tesla' : 'Коли варто звертатися на діагностику батареї Tesla' }}</h3>

        <ul class="service-final-list">
          <li>{{ $isRu ? 'появились ошибки BMS на панели или в приложении;' : 'з’явилися помилки BMS на панелі або в застосунку;' }}</li>
          <li>{{ $isRu ? 'автомобиль начал быстрее терять заряд;' : 'автомобіль почав швидше втрачати заряд;' }}</li>
          <li>{{ $isRu ? 'увеличилось время зарядки или появились скачки по процентам;' : 'збільшився час заряджання або з’явилися стрибки по відсотках;' }}</li>
          <li>{{ $isRu ? 'после простоя машина показывает нестабильную работу батареи.' : 'після простою машина показує нестабільну роботу батареї.' }}</li>
        </ul>

        <p>
          {{ $isRu
            ? 'Если заметили один из этих признаков — не откладывайте сервис. Своевременный ремонт батареи Tesla почти всегда выгоднее, чем эксплуатация с нарастающей неисправностью и последующий дорогой капитальный ремонт.'
            : 'Якщо помітили хоча б одну з цих ознак — не відкладайте сервіс. Своєчасний ремонт батареї Tesla майже завжди вигідніший, ніж експлуатація з прогресуючою несправністю та подальший дорогий капітальний ремонт.'
          }}
        </p>

      </div>
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
