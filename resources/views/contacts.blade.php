@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu
  ? 'Контакты NikolaCars 📞 Сервис и продажа авто Tesla в Киеве'
  : 'Контакти NikolaCars 📞 Сервіс та продаж авто Tesla в Києві'
)

@section('description', $isRu
  ? 'Контакты NikolaCars 📱 Подбор и доставка автомобилей Tesla в Украину под ключ. Обслуживание на нашем СТО, прошивка авто. Ваши желания — наши решения.'
  : 'Контакти NikolaCars 📱 Підбір та доставка автомобілів Tesla в Україну під ключ. Обслуговування на нашому СТО, Прошивка авто. Ваші бажання — Наші рішення.'
)

@section('content')

{{-- ================= CONTACTS (как в CSS .contacts) ================= --}}
<section class="contacts">
  <div class="container">

    <div class="contacts-head">
      <div class="contacts-kicker">
        {{ $isRu ? 'Мы всегда на связи' : 'Ми завжди на зв’язку' }}
      </div>
      <h1 class="contacts-title">
        {{ $isRu ? 'КОНТАКТЫ' : 'КОНТАКТИ' }}
      </h1>
      <div class="contacts-lines"><span></span><span></span></div>
    </div>

    <div class="contacts-grid">

      {{-- LEFT CARD --}}
      <div class="contacts-card">

        <div class="contact-item">
          <div class="contact-icon">🗺️</div>
          <div>
            <div class="contact-label">{{ $isRu ? 'АДРЕС' : 'АДРЕСА' }}</div>
            <div class="contact-value">{{ $isRu ? 'г. Киев, ул. Коллекторная, 30' : 'м. Київ, вул. Колекторна, 30' }}</div>
          </div>
        </div>

        <a class="contact-item" href="tel:+380975120255">
          <div class="contact-icon">📱</div>
          <div>
            <div class="contact-label">ТЕЛЕФОН</div>
            <div class="contact-value">+38 (097) 512 02 55</div>
          </div>
        </a>

        <a class="contact-item" href="tel:+380990698380">
          <div class="contact-icon">📱</div>
          <div>
            <div class="contact-label">
              {{ $isRu ? 'ТЕЛЕФОН (ЗАПЧАСТИ)' : 'ТЕЛЕФОН (ЗАПЧАСТИНИ)' }}
            </div>
            <div class="contact-value">+38 (099) 069 83 80</div>
          </div>
        </a>

        {{-- EMAIL УБРАЛИ --}}

      </div>

      {{-- MAP --}}
      <div class="contacts-map">
        <iframe
          src="https://www.google.com/maps?q=вулиця%20Колекторна%2030/1%20Київ&output=embed"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          allowfullscreen></iframe>
      </div>

    </div>
  </div>
</section>


{{-- ================= FORM SECTION (новая секция) ================= --}}
<section class="contact-us">
  <div class="container">

    <div class="contact-us-grid">

      {{-- FORM --}}
      <div class="contact-us-left">
        <div class="contact-us-kicker">
          {{ $isRu ? 'Мы хотели бы услышать от вас' : 'Ми хотіли б почути від вас' }}
        </div>
        <div class="contact-us-title">
          {{ $isRu ? 'СВЯЗАТЬСЯ!' : "ЗВ'ЯЗАТИСЯ!" }}
        </div>
        <div class="contact-us-lines"><span></span><span></span></div>

        <form id="contactForm" class="contact-us-form" method="post" action="{{ route('lead.callback') }}">
          @csrf
          <input type="hidden" name="source" value="contacts">

          <input class="contact-us-input" type="text" name="name" placeholder="{{ $isRu ? 'Ваше полное имя*' : "Ваше повне ім'я*" }}" required>

          <input
            class="contact-us-input"
            type="tel"
            name="phone"
            id="phoneInput"
            value="+380"
            pattern="^\+380\d{9}$"
            maxlength="13"
            placeholder="+380XXXXXXXXX"
            required
          >

          <textarea class="contact-us-textarea" name="message" placeholder="{{ $isRu ? 'Сообщение*' : 'Повідомлення*' }}" rows="7" required></textarea>

          <button class="contact-us-submit" type="submit">
            {{ $isRu ? 'Отправить' : 'Надіслати' }}
          </button>

          <div id="contactFormStatus" class="contact-us-status" aria-live="polite"></div>
        </form>
      </div>

      {{-- RIGHT SIDE --}}
      <aside class="contact-us-right">
        <div class="contact-us-note-title">
          {{ $isRu
            ? 'МЫ ВЫПОЛНИЛИ БОЛЕЕ 1000+ ЗАКАЗОВ ДЛЯ КЛИЕНТОВ. С РАДОСТЬЮ ВЫПОЛНИМ ВАШ ЗАКАЗ.'
            : 'МИ ВИКОНАЛИ ПОНАД 1000+ ЗАМОВЛЕНЬ ДЛЯ КЛІЄНТІВ. З РАДІСТЮ ВИКОНАЄМО ВАШЕ ЗАМОВЛЕННЯ.'
          }}
        </div>
        <div class="contact-us-note-text">
          {{ $isRu
            ? 'Если у вас есть вопросы — не стесняйтесь написать нам, и мы ответим в течение часа!'
            : 'Якщо у вас є які-небудь питання, не соромтеся надіслати нам повідомлення, і ми відповімо протягом години!'
          }}
        </div>

        <div class="worktime-card">
          <div class="worktime-title">{{ $isRu ? 'ГРАФИК РАБОТЫ' : 'ГРАФІК РОБОТИ' }}</div>
          <div class="worktime-row"><span>{{ $isRu ? 'понедельник' : 'понеділок' }}</span><span>{{ $isRu ? 'с 10:00 до 18:00' : 'з 10:00 до 18:00' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'вторник' : 'вівторок' }}</span><span>{{ $isRu ? 'с 10:00 до 18:00' : 'з 10:00 до 18:00' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'среда' : 'Середа' }}</span><span>{{ $isRu ? 'с 10:00 до 18:00' : 'з 10:00 до 18:00' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'четверг' : 'четвер' }}</span><span>{{ $isRu ? 'с 10:00 до 18:00' : 'з 10:00 до 18:00' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'пятница' : "П'ятниця" }}</span><span>{{ $isRu ? 'с 10:00 до 18:00' : 'з 10:00 до 18:00' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'суббота' : 'Субота' }}</span><span>{{ $isRu ? 'выходной' : 'вихідний' }}</span></div>
          <div class="worktime-row"><span>{{ $isRu ? 'воскресенье' : 'неділя' }}</span><span>{{ $isRu ? 'выходной' : 'вихідний' }}</span></div>
        </div>
      </aside>

    </div>
  </div>
</section>


{{-- AJAX (если хочешь как на сервисах — без перезагрузки) --}}
<script>
  (function () {
    const isRu = @json((($locale ?? 'uk') === 'ru'));

    const form = document.getElementById('contactForm');
    if (!form) return;

    const status = document.getElementById('contactFormStatus');
    const phoneInput = document.getElementById('phoneInput');

    // UA phone: +380 + 9 digits (мобильные коды)
    const uaPhoneRegex = /^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/;

    function t(ru, uk) { return isRu ? ru : uk; }

    function normalizePhoneValue(v) {
      v = (v || '').trim();

      // ensure prefix
      if (!v.startsWith('+380')) {
        const digits = v.replace(/\D/g, '');
        if (digits.startsWith('380')) v = '+380' + digits.slice(3);
        else if (digits.startsWith('0')) v = '+380' + digits.slice(1);
        else v = '+380' + digits;
      }

      // keep only digits after +380
      let tail = v.slice(4).replace(/\D/g, '').slice(0, 9);
      return '+380' + tail;
    }

    if (phoneInput) {
      if (!phoneInput.value) phoneInput.value = '+380';

      phoneInput.addEventListener('input', function () {
        this.value = normalizePhoneValue(this.value);
      });

      phoneInput.addEventListener('focus', function () {
        if (!this.value || this.value.trim() === '') this.value = '+380';
        if (!this.value.startsWith('+380')) this.value = '+380';
      });

      phoneInput.addEventListener('keydown', function (e) {
        const pos = this.selectionStart || 0;
        if ((e.key === 'Backspace' && pos <= 4) || (e.key === 'Delete' && pos < 4)) {
          e.preventDefault();
          this.value = normalizePhoneValue(this.value);
          this.setSelectionRange(4, 4);
        }
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (status) status.textContent = t('Отправка...', 'Відправка...');

      // validate phone before send
      if (phoneInput) {
        phoneInput.value = normalizePhoneValue(phoneInput.value);
        if (!uaPhoneRegex.test(phoneInput.value)) {
          if (status) status.textContent = t('❌ Введите корректный номер: +380XXXXXXXXX', '❌ Введіть коректний номер: +380XXXXXXXXX');
          phoneInput.focus();
          return;
        }
      }

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(form),
        });

        const json = await res.json().catch(() => null);

        if (res.ok && json && (json.ok === true || json.success === true)) {
          if (status) status.textContent = t('✅ Отправлено! Мы свяжемся с вами.', '✅ Надіслано! Ми зв’яжемося з вами.');
          form.reset();
          if (phoneInput) phoneInput.value = '+380';
        } else {
          if (status) status.textContent = t('❌ Ошибка отправки. Попробуйте позже.', '❌ Помилка відправки. Спробуйте пізніше.');
        }
      } catch (err) {
        if (status) status.textContent = t('❌ Ошибка сети. Попробуйте позже.', '❌ Помилка мережі. Спробуйте пізніше.');
      }
    });
  })();
</script>

@endsection
