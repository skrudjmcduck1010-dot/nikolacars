@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'NikolaCars 🛠️ Сервис СТО Tesla и ремонт Tesla в Киеве'
  : 'NikolaCars 🛠️ Сервіс СТО Тесла та ремонт авто Tesla в Києві'
)

@section('description', $isRu
  ? 'NikolaCars 🚘 Подбор и доставка автомобилей Tesla в Украину под ключ. Обслуживание на нашем СТО, прошивка авто, восстановление сертификатов. Ваши желания — наши решения.'
  : 'NikolaCars 🚘 Підбір та доставка автомобілів Tesla в Україну під ключ. Обслуговування на нашому СТО, Прошивка авто, відновлення сертифікатів. Ваші бажання — Наші рішення.'
)

@section('content')

<section class="hero">
  <div class="hero-wrap">
    <div class="hero-slider" id="heroSlider" aria-label="Hero slider">
      <button class="hero-arrow prev" type="button" aria-label="Previous slide">‹</button>
      <button class="hero-arrow next" type="button" aria-label="Next slide">›</button>

      <div class="hero-track">
        @php
          $slides = $isRu ? [
            [
              'image' => 'images/hero/import.webp',
              'service' => 'import',
              'title' => 'Пригон Tesla из США под ключ',
              'subtitle' => 'Поиск, покупка, доставка, растаможка и подготовка Tesla из Америки',
            ],
            [
              'image' => 'images/hero/service.webp',
              'service' => 'service',
              'title' => 'Профессиональное обслуживание Tesla',
              'subtitle' => 'СТО Tesla в Киеве — диагностика, ТО и ремонт',
            ],
            [
              'image' => 'images/hero/firmware.webp',
              'service' => 'fw',
              'title' => 'Прошивка и обновление Tesla',
              'subtitle' => 'Активация функций, обновление ПО и настройка электроники',
            ],
            [
              'image' => 'images/hero/cert.webp',
              'service' => 'cert',
              'title' => 'Восстановление сертификатов Tesla',
              'subtitle' => 'Возврат Tesla App, обновлений и полного функционала автомобиля',
            ],
          ] : [
            [
              'image' => 'images/hero/import.webp',
              'service' => 'import',
              'title' => 'Пригін Tesla із США під ключ',
              'subtitle' => 'Пошук, купівля, доставка, розмитнення та відновлення Tesla з Америки',
            ],
            [
              'image' => 'images/hero/service.webp',
              'service' => 'service',
              'title' => 'Професійне обслуговування Tesla',
              'subtitle' => 'СТО Tesla у Києві — діагностика, техобслуговування та ремонт',
            ],
            [
              'image' => 'images/hero/firmware.webp',
              'service' => 'fw',
              'title' => 'Прошивка та оновлення Tesla',
              'subtitle' => 'Активація функцій, оновлення ПЗ та налаштування електроніки',
            ],
            [
              'image' => 'images/hero/cert.webp',
              'service' => 'cert',
              'title' => 'Відновлення сертифікатів Tesla',
              'subtitle' => 'Повернення Tesla App, оновлень та повного функціоналу авто',
            ],
          ];
        @endphp

        @foreach($slides as $index => $slide)
          @php
            $callFormId = 'callForm_'.$index;
            $phoneInputId = 'phoneInput_'.$index;
          @endphp

          <div class="hero-slide"
               data-index="{{ $index }}"
               data-service="{{ $slide['service'] }}"
               style="background-image: url('{{ asset($slide['image']) }}');">

            <div class="hero-inner">
              <div class="hero-content">

                <div>
                  <h1 class="hero-title">{{ $slide['title'] }}</h1>
                  <div class="hero-subtitle">{{ $slide['subtitle'] }}</div>
                </div>

                {{-- FORM (как на prigon-usa) --}}
                <form class="call-form"
      id="{{ $callFormId }}"
      action="{{ route('lead.callback') }}"
      method="post"
      novalidate
      data-call-form="1">
  @csrf

  <input type="hidden" name="source_page" value="home">
  <input type="hidden" name="slide_title" value="{{ $slide['title'] }}">


                  <div class="call-form-title">{{ $isRu ? 'ЗАКАЗАТЬ ЗВОНОК' : 'ЗАМОВИТИ ДЗВІНОК' }}</div>
                  <div class="call-form-desc">
                    {{ $isRu
                      ? 'Оставьте свои контактные данные — наш менеджер свяжется с вами и ответит на все вопросы.'
                      : "Залиште свої контактні дані, після чого наш менеджер зв'яжеться з Вами і відповість на всі запитання."
                    }}
                  </div>

                  <div class="call-form-grid">
                    <input class="call-input"
                           type="text"
                           name="name"
                           placeholder="{{ $isRu ? 'Ваше имя*' : "Ваше ім'я*" }}"
                           required>

                    <div class="phone-field">
                      <span class="phone-prefix">+380</span>
                      <input
                        class="call-input phone-input"
                        type="tel"
                        name="phone_digits"
                        id="{{ $phoneInputId }}"
                        maxlength="9"
                        inputmode="numeric"
                        placeholder="XXXXXXXXX"
                        required
                        autocomplete="tel"
                      >
                    </div>

                    <textarea class="call-textarea"
                              name="details"
                              rows="5"
                              placeholder="{{ $isRu ? 'Детали запроса' : 'Деталі запиту' }}"></textarea>

                    <button class="call-submit" type="submit">{{ $isRu ? 'Перезвонить' : 'Передзвонити' }}</button>
                  </div>

                  <div class="call-success">
                    {{ $isRu ? 'Спасибо! Мы перезвоним вам в течение нескольких минут' : 'Дякуємо, ми Вам передзвонимо через декілька хвилин' }}
                  </div>
                </form>

              </div>
            </div>
          </div>
        @endforeach
      </div>

      <div class="hero-dots" aria-label="Slider dots">
        <button class="hero-dot active" type="button" data-dot="0" aria-label="Slide 1"></button>
        <button class="hero-dot" type="button" data-dot="1" aria-label="Slide 2"></button>
        <button class="hero-dot" type="button" data-dot="2" aria-label="Slide 3"></button>
        <button class="hero-dot" type="button" data-dot="3" aria-label="Slide 4"></button>
      </div>
    </div>
  </div>
</section>

{{-- дальше твой код без изменений --}}
<section class="about">
  <div class="container about-inner">
    <div class="about-left">
      <div class="about-kicker">{{ $isRu ? 'Идем в ногу со временем' : 'Йдемо в ногу з часом' }}</div>
      <h2 class="about-title">{{ $isRu ? 'NikolaCars — СТО Tesla в Киеве' : 'NikolaCars - СТО Тесла в Києві' }}</h2>
      <div class="about-lines">
        <span></span><span></span>
      </div>
    </div>

    <div class="about-right">
      <p>
        {{ $isRu
          ? 'Добро пожаловать в NikolaCars — вашего надежного партнёра в мире технологий и инноваций для электромобилей Tesla.'
          : 'Ласкаво просимо до Nikola Cars – вашого надійного партнера у світі технологій та інновацій для електричних автомобілів Tesla.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'NikolaCars предоставляет полный спектр услуг по обслуживанию и покупке Tesla в Украине. Наша миссия — сделать вашу эксплуатацию электромобиля максимально удобной, безопасной и приятной.'
          : 'NikolaCars надає всі можливі кваліфіковані послуги з обслуговування та продажу автомобілів Tesla в Україні. Наша місія – зробити вашу електромобільну подорож максимально зручною, безпечною і захопливою.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'У нас вы получите диагностику, техобслуживание, ремонт, дооснащение и индивидуальный подход к вашему автомобилю. Наши специалисты постоянно обновляют знания и используют современное оборудование.'
          : 'З NikolaCars ви отримаєте повний спектр послуг для автомобілів Tesla, включаючи технічне обслуговування, ремонт, модифікації та індивідуальний підхід до вашого транспортного засобу. Наші кваліфіковані та сертифіковані фахівці готові задовольнити навіть найвибагливіші потреби наших клієнтів.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'Мы гордимся глубокой экспертизой в сфере Tesla и вниманием к деталям. Обращайтесь к нам по любым вопросам — поможем и подскажем.'
          : 'Ми пишаємося нашою глибокою експертизою в галузі технологій Tesla. Наші фахівці постійно оновлюють свої знання, щоб бути в курсі останніх розробок та вдосконалень у світі електричних автомобілів.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'Спасибо, что выбираете NikolaCars — вместе мы делаем будущее электромобильности комфортным и безопасным!'
          : 'Дякуємо, що обрали Nikola Cars – разом ми робимо майбутнє електромобільності захопливим та безпечним!'
        }}
      </p>
    </div>
  </div>
</section>

<section class="services">
  <div class="container">

    <div class="services-head">
      <div class="services-kicker">{{ $isRu ? 'Перечень услуг нашей компании' : 'Перелік послуг нашої компанії' }}</div>
      <h2 class="services-title">{{ $isRu ? 'НАШИ УСЛУГИ' : 'НАШІ ПОСЛУГИ' }}</h2>
      <div class="services-lines">
        <span></span><span></span>
      </div>
    </div>

    <div class="services-grid">
      <a href="{{ $isRu ? '/ru/services/prigon-tesla-usa/' : '/services/prigon-tesla-usa/' }}" class="service-card">
        <div class="service-icon">🚗</div>
        <div class="service-title">{{ $isRu ? 'ПРИГОН ИЗ США' : 'ПРИГІН З США' }}</div>
        <div class="service-text">
          {{ $isRu
            ? 'Компания NikolaCars поможет выбрать и привезти автомобиль Tesla из США под ключ.'
            : 'Наша компанія NikolaCars допоможе вибрати і привезти автомобіль Tesla з США під ключ.'
          }}
        </div>
        <div class="service-more">{{ $isRu ? 'Читать далее' : 'Читати далі' }}</div>
      </a>

      <a href="{{ $isRu ? '/ru/services/tesla-service/' : '/services/tesla-service/' }}" class="service-card">
        <div class="service-icon">🛠️</div>
        <div class="service-title">{{ $isRu ? 'ОБСЛУЖИВАНИЕ TESLA' : 'ОБСЛУГОВУВАННЯ АВТО' }}</div>
        <div class="service-text">
          {{ $isRu
            ? 'Электромобили требуют минимального объёма работ для поддержания идеального состояния.'
            : 'Електричні автомобілі потребують меншого обсягу робіт для підтримання гарного стану.'
          }}
        </div>
        <div class="service-more">{{ $isRu ? 'Читать далее' : 'Читати далі' }}</div>
      </a>

      <a href="{{ $isRu ? '/ru/services/firmware-auto/' : '/services/firmware-auto/' }}" class="service-card">
        <div class="service-icon">⚡</div>
        <div class="service-title">{{ $isRu ? 'ПРОШИВКА TESLA' : 'ПРОШИВКА АВТО' }}</div>
        <div class="service-text">
          {{ $isRu
            ? 'Обновление прошивки электрокара Tesla до актуальной версии — услуги в Киеве от наших специалистов.'
            : 'Оновлення прошивки електрокара Tesla до актуальної версії — послуги в Києві від наших фахівців.'
          }}
        </div>
        <div class="service-more">{{ $isRu ? 'Читать далее' : 'Читати далі' }}</div>
      </a>

      <a href="{{ $isRu ? '/ru/services/vidnovlennya-sertyfikativ-tesla/' : '/services/vidnovlennya-sertyfikativ-tesla/' }}" class="service-card">
        <div class="service-icon">📄</div>
        <div class="service-title">{{ $isRu ? 'ВОССТАНОВЛЕНИЕ СЕРТИФИКАТОВ' : 'ВІДНОВЛЕННЯ СЕРТИФІКАТІВ' }}</div>
        <div class="service-text">
          {{ $isRu
            ? 'Официальное восстановление сертификатов Tesla через сервис Tesla с гарантией стабильной работы.'
            : 'Офіційне відновлення сертифікатів Tesla через сервіс Tesla з гарантією стабільної роботи.'
          }}
        </div>
        <div class="service-more">{{ $isRu ? 'Читать далее' : 'Читати далі' }}</div>
      </a>
    </div>
  </div>
</section>

<section class="contacts">
  <div class="container">
    <div class="contacts-head">
      <div class="contacts-kicker">{{ $isRu ? 'Связь с нами' : 'Звʼязок з нами' }}</div>
      <h2 class="contacts-title">{{ $isRu ? 'НАШИ КОНТАКТЫ' : 'НАШІ КОНТАКТИ' }}</h2>
      <div class="contacts-lines">
        <span></span><span></span>
      </div>
    </div>

    <div class="contacts-grid">
      <div class="contacts-card">
        <div class="contact-item">
          <div class="contact-icon">📍</div>
          <div>
            <div class="contact-label">{{ $isRu ? 'АДРЕС' : 'АДРЕСА' }}</div>
            <div class="contact-value">м. Київ, вул. Колекторна, 30</div>
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

      <div class="contacts-map">
        <iframe
          src="https://www.google.com/maps?q=Київ,+вулиця+Колекторна,+30&amp;output=embed"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          title="{{ ($locale ?? 'uk') === 'ru'
            ? 'Карта расположения NikolaCars в Киеве, улица Коллекторная, 30'
            : 'Карта розташування NikolaCars у Києві, вулиця Колекторна, 30' }}">
        </iframe>
      </div>
    </div>
  </div>
</section>

<section class="service-final">
  <div class="container">
    <div class="service-final-content">
      <h2>
        {{ $isRu
          ? 'Сервис Tesla в Киеве — профессиональное СТО Tesla от NikolaCars'
          : 'Сервіс Tesla в Києві — професійне СТО Tesla від NikolaCars'
        }}
      </h2>

      <p>
        {{ $isRu
          ? 'NikolaCars — специализированный сервис Tesla в Киеве. Мы выполняем диагностику, техобслуживание и ремонт Tesla любой сложности, а также программные работы и активации.'
          : 'NikolaCars — це спеціалізований сервіс Tesla у Києві, який надає повний комплекс послуг для електромобілів. Ми пропонуємо якісний Tesla сервіс, технічне обслуговування, комп’ютерну діагностику, оновлення програмного забезпечення та професійний ремонт Tesla.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'Наше СТО Tesla оснащено современным оборудованием для работы с высоковольтными системами. Специалисты имеют большой опыт обслуживания Model S, Model 3, Model X и Model Y.'
          : 'Наше СТО Tesla оснащене сучасним обладнанням та спеціалізованими інструментами для роботи з високовольтними системами. Фахівці компанії мають великий практичний досвід обслуговування моделей Model S, Model 3, Model X та Model Y.'
        }}
      </p>

      <p>{{ $isRu ? 'Наши услуги:' : 'До наших послуг входить:' }}</p>

      <ul class="service-final-list">
        <li>{{ $isRu ? 'Комплексная диагностика и проверка батареи' : 'Комплексна діагностика та перевірка батареї' }}</li>
        <li>{{ $isRu ? 'Обслуживание ходовой части' : 'Обслуговування ходової частини' }}</li>
        <li>{{ $isRu ? 'Восстановление сертификатов и программных функций' : 'Відновлення сертифікатів та програмних функцій' }}</li>
        <li>{{ $isRu ? 'Обновление и прошивка программного обеспечения' : 'Оновлення та прошивка програмного забезпечення' }}</li>
        <li>{{ $isRu ? 'Ремонт электроники и систем автопилота' : 'Ремонт електроніки та систем автопілоту' }}</li>
      </ul>

      <h3>{{ $isRu ? 'Почему выбирают наше СТО Tesla?' : 'Чому обирають наше СТО Tesla?' }}</h3>

      <p>
        {{ $isRu
          ? 'Мы работаем быстро и прозрачно: точная диагностика, понятная стоимость и ответственность за результат. NikolaCars — команда, которая специализируется на Tesla.'
          : 'Наш сервіс Tesla у Києві відрізняється індивідуальним підходом, точністю діагностики та відповідальністю за результат. NikolaCars — це команда експертів, яка спеціалізується виключно на електромобілях.'
        }}
      </p>

      <p>
        {{ $isRu
          ? 'Обращайтесь в NikolaCars, если нужен надежный ремонт Tesla или регулярное обслуживание. Мы позаботимся о вашем автомобиле так же внимательно, как и вы.'
          : 'Звертайтесь до NikolaCars, якщо вам потрібен надійний ремонт Tesla або комплексний Tesla сервіс. Ми подбаємо про ваш автомобіль так само уважно, як і ви.'
        }}
      </p>
    </div>
  </div>
</section>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  // ====== helper: utm ======
  function collectUtm() {
    const params = new URLSearchParams(window.location.search);
    const utmKeys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];
    const pairs = [];
    utmKeys.forEach(k => { if (params.get(k)) pairs.push(`${k}=${params.get(k)}`); });
    return pairs.join('&');
  }

  // ====== PHONE validation (UA mobile) ======
  const uaPhoneRegex = /^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/;

  // ====== Bind each call form (one per slide) ======
  document.querySelectorAll('form.call-form[data-call-form="1"]').forEach(form => {
    const submitBtn = form.querySelector('.call-submit');
    const csrf = form.querySelector('input[name="_token"]')?.value || '';

    const phoneInput = form.querySelector('input[name="phone_digits"]');

    // digits only (9 digits after +380)
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

      const digits = (phoneInput.value || '').replace(/\D/g, '');
      const fullPhone = '+380' + digits;

      if (!uaPhoneRegex.test(fullPhone)) {
        alert(@json($isRu ? 'Введите корректный украинский номер (9 цифр после +380)' : 'Введіть коректний український номер (9 цифр після +380)'));
        phoneInput.focus();
        return;
      }

      // UI
      form.classList.remove('is-success');
      if (submitBtn) submitBtn.disabled = true;

      const fd = new FormData(form);

      // phone in full format
      fd.set('phone', fullPhone);
      fd.delete('phone_digits');

      // page + utm
      fd.set('page', window.location.href);
      fd.set('utm', collectUtm());

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
          alert(@json($isRu ? 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.' : 'Не вдалося надіслати заявку. Спробуйте ще раз або зателефонуйте нам.'));
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
});
</script>
@endpush

@endsection
