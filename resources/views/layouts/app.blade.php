<!doctype html>
<html lang="{{ ($locale ?? 'uk') === 'ru' ? 'ru' : 'uk' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>@yield('title', 'NikolaCars')</title>
  <meta name="description" content="@yield('description', (($locale ?? 'uk') === 'ru') ? 'Сервис Tesla, ремонт Tesla, СТО Tesla в Киеве.' : 'Сервіс Tesla, ремонт Tesla, СТО Tesla у Києві.')">

  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('favicon.png') }}">

  <link rel="stylesheet" href="/assets/css/app.css?v=7">
</head>

<body>

@php
  // Global locale flags for ALL partials (header/footer/widgets)
  $loc  = (($locale ?? 'uk') === 'ru') ? 'ru' : 'uk';
  $isRu = ($loc === 'ru');

  $t = [
    'uk' => [
      'home' => 'Головна',
      'services' => 'Послуги',
      'parts' => 'Запчастини',
      'reviews' => 'Відгуки',
      'news' => 'Новини',
      'contacts' => 'Контакти',
      'srv1' => 'Пригін Tesla із США',
      'srv2' => 'Обслуговування автомобілів Tesla',
      'srv3' => 'Відновлення сертифікатів Tesla',
      'srv4' => 'Прошивка авто',
    ],
    'ru' => [
      'home' => 'Главная',
      'services' => 'Услуги',
      'parts' => 'Запчасти',
      'reviews' => 'Отзывы',
      'news' => 'Новости',
      'contacts' => 'Контакты',
      'srv1' => 'Пригон Tesla из США',
      'srv2' => 'Обслуживание автомобилей Tesla',
      'srv3' => 'Восстановление сертификатов Tesla',
      'srv4' => 'Прошивка авто',
    ],
  ];

  $L = $t[$loc];

  $home = $loc === 'ru' ? '/ru/' : '/';

  // --- Language switch links (stay on the same page) ---
  $currentPath = '/'.ltrim(request()->path(), '/'); // ex: "/services/", "/ru/services/"
  $currentPath = rtrim($currentPath, '/') . '/';    // normalize: always ends with "/"
  if ($currentPath === '//' ) $currentPath = '/';

  if ($loc === 'ru') {
    // remove leading "/ru/"
    $uaUrl = preg_replace('#^/ru/#', '/', $currentPath);
    $ruUrl = $currentPath; // already ru
  } else {
    $uaUrl = $currentPath; // already ua
    $ruUrl = ($currentPath === '/' ? '/ru/' : '/ru' . $currentPath);
  }
@endphp

<div class="topbar">
  <div class="container topbar-inner">

    <div class="topbar-left">
      <span class="topbar-item">
        <span class="emoji">🕒</span>
        {{ $isRu ? 'с 9:00 до 19:00' : 'з 9:00 до 19:00' }}
      </span>

      <a class="topbar-item" href="mailto:nikola.carsua@gmail.com">
        <span class="emoji">✉️</span>
        nikola.carsua@gmail.com
      </a>
    </div>

    <div class="topbar-right">
      <a class="topbar-item" href="tel:+380975120255">
        <span class="emoji">📞</span>
        +38 (097) 512 02 55
      </a>

      <span class="topbar-item">
        <span class="emoji">📍</span>
        {{ $isRu ? 'г. Киев, ул. Коллекторная, 30' : 'м. Київ, вул. Колекторна, 30' }}
      </span>

      <div class="social" aria-label="{{ $isRu ? 'Соцсети' : 'Соцмережі' }}">
        <!-- Facebook -->
        <a href="https://www.facebook.com/nikolacarsua/" target="_blank" rel="noopener" aria-label="Facebook">
          <svg viewBox="0 0 24 24" role="img" aria-hidden="true">
            <path d="M22 12a10 10 0 1 0-11.56 9.88v-7H8.1V12h2.34V9.8c0-2.32 1.38-3.6 3.5-3.6 1.02 0 2.08.18 2.08.18v2.28h-1.17c-1.15 0-1.5.71-1.5 1.44V12h2.56l-.41 2.88h-2.15v7A10 10 0 0 0 22 12z"/>
          </svg>
        </a>

        <!-- Twitter (X) -->
        <a href="https://x.com/NikolaCars" target="_blank" rel="noopener" aria-label="Twitter/X">
          <svg viewBox="0 0 24 24" role="img" aria-hidden="true">
            <path d="M18.9 2H22l-6.8 7.78L23 22h-6.8l-5.33-6.93L4.8 22H2l7.33-8.4L1 2h6.97l4.82 6.28L18.9 2zm-1.2 18h1.88L7.2 3.9H5.2L17.7 20z"/>
          </svg>
        </a>

        <!-- Instagram -->
        <a href="https://www.instagram.com/nikolacarskyiv/" target="_blank" rel="noopener" aria-label="Instagram">
          <svg viewBox="0 0 24 24" role="img" aria-hidden="true">
            <path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm10 2H7a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3zm-5 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zM17.8 6.2a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/>
          </svg>
        </a>

        <!-- YouTube -->
        <a href="https://www.youtube.com/channel/UCkD7JjB6KBPMZN3BkhBFxtQ" target="_blank" rel="noopener" aria-label="YouTube">
          <svg viewBox="0 0 24 24" role="img" aria-hidden="true">
            <path d="M21.6 7.2a3 3 0 0 0-2.1-2.1C17.8 4.6 12 4.6 12 4.6s-5.8 0-7.5.5A3 3 0 0 0 2.4 7.2 31 31 0 0 0 2 12a31 31 0 0 0 .4 4.8 3 3 0 0 0 2.1 2.1c1.7.5 7.5.5 7.5.5s5.8 0 7.5-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 22 12a31 31 0 0 0-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z"/>
          </svg>
        </a>
      </div>
    </div>

  </div>
</div>

<header class="nav">
  <div class="container nav-inner">
    <a class="logo" href="{{ $home }}"><img src="{{ asset('images/logo.png') }}" alt="NikolaCars"></a>

    <!-- BURGER (mobile only by CSS) -->
    <button class="burger-btn" type="button"
            aria-label="{{ $isRu ? 'Открыть меню' : 'Відкрити меню' }}"
            aria-controls="mobileMenu"
            aria-expanded="false">
      <span></span><span></span><span></span>
    </button>

    <!-- MENU -->
    <nav class="menu" id="mobileMenu" aria-hidden="true">

      <!-- mobile-only top bar (shown only on mobile by CSS) -->
      <div class="mobile-menu-top">
        <div class="mobile-menu-title">NikolaCars</div>
        <button class="mobile-menu-close" type="button" aria-label="{{ $isRu ? 'Закрыть меню' : 'Закрити меню' }}">×</button>
      </div>

      <a href="{{ $home }}">{{ $L['home'] }}</a>

      <div class="dropdown">
        <a class="dropdown-toggle"
           href="{{ $loc === 'ru' ? '/ru/services/' : '/services/' }}"
           data-dd-toggle>
          {{ $L['services'] }} <span class="chev">▾</span>
        </a>

        <div class="dropdown-menu">
          <a href="{{ $loc === 'ru' ? '/ru/services/prigon-tesla-usa/' : '/services/prigon-tesla-usa/' }}">{{ $L['srv1'] }}</a>
          <a href="{{ $loc === 'ru' ? '/ru/services/tesla-service/' : '/services/tesla-service/' }}">{{ $L['srv2'] }}</a>
          <a href="{{ $loc === 'ru' ? '/ru/services/vidnovlennya-sertyfikativ-tesla/' : '/services/vidnovlennya-sertyfikativ-tesla/' }}">{{ $L['srv3'] }}</a>
          <a href="{{ $loc === 'ru' ? '/ru/services/firmware-auto/' : '/services/firmware-auto/' }}">{{ $L['srv4'] }}</a>
        </div>
      </div>

      <a href="https://nikolacars.com.ua/ua/">{{ $L['parts'] }}</a>
      <a href="{{ $loc === 'ru' ? '/ru/testimonial/' : '/testimonial/' }}">{{ $L['reviews'] }}</a>
      <a href="{{ $loc === 'ru' ? '/ru/news/' : '/news/' }}">{{ $L['news'] }}</a>
      <a href="{{ $loc === 'ru' ? '/ru/contacts/' : '/contacts/' }}">{{ $L['contacts'] }}</a>
    </nav>

    <div class="lang">
      <a class="pill {{ $loc === 'uk' ? 'active' : '' }}" href="{{ $uaUrl }}">UA</a>
      <a class="pill {{ $loc === 'ru' ? 'active' : '' }}" href="{{ $ruUrl }}">RU</a>
    </div>

  </div>
</header>

<main>
  @yield('content')
</main>

<script src="/assets/js/slider.js" defer></script>
@stack('scripts')

@include('partials.footer')

<!-- FLOAT CONTACT WIDGET -->
<div class="contact-widget" id="contactWidget">

  <div class="contact-panel" id="contactPanel">
    <a href="viber://chat?number=%2B380975120255" class="contact-item">
      <div class="contact-icon viber">📞</div>
      <div>
        <div class="contact-title">Viber</div>
        <div class="contact-sub">+380975120255</div>
      </div>
    </a>

    <a href="https://t.me/Nikolacarskyiv" target="_blank" class="contact-item">
      <div class="contact-icon telegram">✈️</div>
      <div>
        <div class="contact-title">Telegram</div>
        <div class="contact-sub">@Nikolacarskyiv</div>
      </div>
    </a>

    <a href="mailto:nikola.carsua@gmail.com" class="contact-item">
      <div class="contact-icon mail">✉️</div>
      <div>
        <div class="contact-title">{{ $isRu ? 'Написать нам' : 'Написати нам' }}</div>
        <div class="contact-sub">nikola.carsua@gmail.com</div>
      </div>
    </a>

    <button type="button" class="contact-item contact-item-btn" id="openCallbackWidget">
      <div class="contact-icon phone">📞</div>
      <div>
        <div class="contact-title">{{ $isRu ? 'Перезвоните мне' : 'Передзвоніть мені' }}</div>
        <div class="contact-sub">{{ $isRu ? 'В течение 30 секунд' : 'Протягом 30 секунд' }}</div>
      </div>
    </button>
  </div>

  <!-- Callback mini-form -->
  <div class="callback-panel" id="callbackPanel" aria-hidden="true">
    <button type="button" class="callback-close" id="closeCallbackWidget" aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button>

    <div class="callback-title">
      {!! $isRu ? 'Введите ваш номер телефона<br>и мы перезвоним в течение 30 секунд' : 'Введіть ваш номер телефону<br>і ми передзвонимо протягом 30 секунд' !!}
    </div>

    <div class="callback-phone">
      <span class="callback-prefix">+380</span>
      <input
        type="tel"
        id="callbackPhone"
        class="callback-input"
        inputmode="numeric"
        maxlength="9"
        placeholder="XXXXXXXXX"
        autocomplete="tel"
      >
    </div>

    <button type="button" class="callback-submit" id="sendCallbackWidget">
      {{ $isRu ? 'Жду звонка' : 'Чекаю дзвінка' }}
    </button>
  </div>

  <button class="contact-toggle" id="contactToggle">
    💬
  </button>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const widget = document.getElementById('contactWidget');
  const toggle = document.getElementById('contactToggle');
  const panel = document.getElementById('contactPanel');

  const openCb = document.getElementById('openCallbackWidget');
  const cbPanel = document.getElementById('callbackPanel');
  const closeCb = document.getElementById('closeCallbackWidget');

  const phoneInput = document.getElementById('callbackPhone');
  const sendBtn = document.getElementById('sendCallbackWidget');

  if (!widget || !toggle || !panel) return;

  const leadUrl = "{{ route('lead.callback') }}";
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const i18n = {
    invalidPhone: @json($isRu ? 'Введите корректный украинский номер (9 цифр после +380)' : 'Введіть коректний український номер (9 цифр після +380)'),
    sendFail: @json($isRu ? 'Не удалось отправить заявку. Попробуйте ещё раз.' : 'Не вдалося відправити заявку. Спробуйте ще раз.'),
    thanks: @json($isRu ? 'Спасибо! Мы скоро вам перезвоним.' : 'Дякуємо! Ми скоро вам передзвонимо.'),
    netErr: @json($isRu ? 'Ошибка сети. Попробуйте ещё раз.' : 'Помилка мережі. Спробуйте ще раз.'),
    leadName: @json($isRu ? 'Виджет: Перезвоните мне' : 'Віджет: Передзвоніть мені'),
    leadDetails: @json($isRu ? 'Заявка из плавающего виджета (callback)' : 'Заявка з плаваючого віджета (callback)'),
  };

  function closeAll(){
    panel.classList.remove('active');
    cbPanel?.classList.remove('active');
  }

  function openContacts(){
    panel.classList.add('active');
    cbPanel?.classList.remove('active');
  }

  function openCallback(){
    panel.classList.remove('active');
    cbPanel?.classList.add('active');
    phoneInput?.focus();
  }

  toggle.addEventListener('click', function(){
    const isOpen = panel.classList.contains('active') || (cbPanel && cbPanel.classList.contains('active'));
    isOpen ? closeAll() : openContacts();
  });

  openCb?.addEventListener('click', openCallback);
  closeCb?.addEventListener('click', openContacts);

  document.addEventListener('click', function(e){
    if (!e.target.closest('.contact-widget')) closeAll();
  });

  phoneInput?.addEventListener('input', function(){
    this.value = this.value.replace(/\D/g,'').slice(0,9);
  });

  async function sendCallback(){
    if (!phoneInput || !sendBtn) return;

    const digits = phoneInput.value.replace(/\D/g,'');
    const fullPhone = '+380' + digits;

    const uaPhoneRegex = /^\+380(39|50|63|66|67|68|73|91|92|93|94|95|96|97|98|99)\d{7}$/;
    if (!uaPhoneRegex.test(fullPhone)) {
      alert(i18n.invalidPhone);
      phoneInput.focus();
      return;
    }

    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('name', i18n.leadName);
    fd.append('phone', fullPhone);
    fd.append('details', i18n.leadDetails);
    fd.append('page', window.location.href);

    const params = new URLSearchParams(window.location.search);
    const utmKeys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];
    const utmPairs = [];
    utmKeys.forEach(k => { if (params.get(k)) utmPairs.push(`${k}=${params.get(k)}`); });
    fd.append('utm', utmPairs.join('&'));

    try {
      const res = await fetch(leadUrl, {
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
        alert(i18n.sendFail);
        return;
      }

      phoneInput.value = '';
      alert(i18n.thanks);
      closeAll();
    } catch (err) {
      console.error(err);
      alert(i18n.netErr);
    } finally {
      sendBtn.disabled = false;
    }
  }

  sendBtn?.addEventListener('click', sendCallback);
});
</script>

<!-- MOBILE MENU JS -->
<script>
document.addEventListener('DOMContentLoaded', function () {

  const burger = document.querySelector('.burger-btn');
  const menu = document.getElementById('mobileMenu');
  const closeBtn = menu?.querySelector('.mobile-menu-close');

  if (!burger || !menu || !closeBtn) return;

  const dropdown = menu.querySelector('.dropdown');
  const dropdownToggle = menu.querySelector('[data-dd-toggle]');

  function openMenu(){
    menu.classList.add('is-open');
    burger.setAttribute('aria-expanded', 'true');
    menu.setAttribute('aria-hidden', 'false');
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  function closeMenu(){
    menu.classList.remove('is-open');
    burger.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-hidden', 'true');
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    dropdown?.classList.remove('open');
  }

  burger.addEventListener('click', function(){
    menu.classList.contains('is-open') ? closeMenu() : openMenu();
  });

  closeBtn.addEventListener('click', closeMenu);

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && menu.classList.contains('is-open')) closeMenu();
  });

  // "Услуги" — раскрытие только на мобилке
  dropdownToggle?.addEventListener('click', function(e){
    if (window.matchMedia('(max-width: 900px)').matches) {
      e.preventDefault();
      dropdown?.classList.toggle('open');
    }
  });

  // Клик по ссылкам закрывает меню, НО не по "Услуги"
  menu.addEventListener('click', function(e){
    const a = e.target.closest('a');
    if (!a) return;

    if (a.hasAttribute('data-dd-toggle')) return;

    if (menu.classList.contains('is-open')) closeMenu();
  });

});
</script>

</body>
</html>
