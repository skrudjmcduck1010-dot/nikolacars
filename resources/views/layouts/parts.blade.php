<!doctype html>
<html lang="{{ $locale === 'ru' ? 'ru' : 'uk' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @php
    $seoPath = '/'.trim(request()->path(), '/').'/';
    $seoUaPath = preg_replace('#^/ru/#', '/', $seoPath);
    $seoRuPath = '/ru'.$seoUaPath;
    $seoPageQuery = ($seoPage ?? 1) > 1 ? '?page='.(int) $seoPage : '';
  @endphp
  <title>{{ $seoTitle ?? ($locale === 'ru' ? 'Запчасти Tesla — NikolaCars' : 'Запчастини Tesla — NikolaCars') }}</title>
  <meta name="description" content="{{ $seoDescription ?? ($locale === 'ru' ? 'Оригинальные запчасти Tesla в наличии в Киеве.' : 'Оригінальні запчастини Tesla в наявності у Києві.') }}">
  @if($seoNoindex ?? false)<meta name="robots" content="noindex, follow">@endif
  <link rel="canonical" href="https://nikolacars.kiev.ua{{ $locale === 'ru' ? $seoRuPath : $seoUaPath }}{{ $seoPageQuery }}">
  <link rel="alternate" hreflang="uk-UA" href="https://nikolacars.kiev.ua{{ $seoUaPath }}{{ $seoPageQuery }}">
  <link rel="alternate" hreflang="ru-UA" href="https://nikolacars.kiev.ua{{ $seoRuPath }}{{ $seoPageQuery }}">
  <link rel="alternate" hreflang="x-default" href="https://nikolacars.kiev.ua{{ $seoUaPath }}{{ $seoPageQuery }}">
  @foreach(($seoStructuredData ?? []) as $schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
  @endforeach
  <link rel="icon" href="{{ asset('favicon.ico') }}">
  <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v=12">
  <link rel="stylesheet" href="{{ asset('assets/css/parts.css') }}?v=10">
</head>
<body class="parts-page">
@php
  $loc = $locale === 'ru' ? 'ru' : 'uk';
  $isRu = $loc === 'ru';
  $home = $isRu ? '/ru/' : '/';
  $currentPath = '/'.ltrim(request()->path(), '/');
  $currentPath = rtrim($currentPath, '/').'/';
  $uaUrl = $isRu ? preg_replace('#^/ru/#', '/', $currentPath) : $currentPath;
  $ruUrl = $isRu ? $currentPath : '/ru'.$currentPath;
@endphp

<div class="topbar">
  <div class="container topbar-inner">
    <div class="topbar-left">
      <span class="topbar-item"><span class="emoji">🕒</span>{{ $isRu ? 'с 9:00 до 19:00' : 'з 9:00 до 19:00' }}</span>
      <a class="topbar-item" href="mailto:nikola.carsua@gmail.com"><span class="emoji">✉️</span>nikola.carsua@gmail.com</a>
    </div>
    <div class="topbar-right">
      <a class="topbar-item" href="tel:+380975120255"><span class="emoji">📞</span>+38 (097) 512 02 55</a>
      <span class="topbar-item"><span class="emoji">📍</span>{{ $isRu ? 'г. Киев, ул. Коллекторная, 30' : 'м. Київ, вул. Колекторна, 30' }}</span>
      <div class="social" aria-label="{{ $isRu ? 'Соцсети' : 'Соцмережі' }}">
        <a href="https://www.facebook.com/nikolacarsua/" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.56 9.88v-7H8.1V12h2.34V9.8c0-2.32 1.38-3.6 3.5-3.6 1.02 0 2.08.18 2.08.18v2.28h-1.17c-1.15 0-1.5.71-1.5 1.44V12h2.56l-.41 2.88h-2.15v7A10 10 0 0 0 22 12z"/></svg></a>
        <a href="https://x.com/NikolaCars" target="_blank" rel="noopener" aria-label="Twitter/X"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.78L23 22h-6.8l-5.33-6.93L4.8 22H2l7.33-8.4L1 2h6.97l4.82 6.28L18.9 2zm-1.2 18h1.88L7.2 3.9H5.2L17.7 20z"/></svg></a>
        <a href="https://www.instagram.com/nikolacarskyiv/" target="_blank" rel="noopener" aria-label="Instagram"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm10 2H7a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3zm-5 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zM17.8 6.2a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/></svg></a>
        <a href="https://www.youtube.com/channel/UCkD7JjB6KBPMZN3BkhBFxtQ" target="_blank" rel="noopener" aria-label="YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 7.2a3 3 0 0 0-2.1-2.1C17.8 4.6 12 4.6 12 4.6s-5.8 0-7.5.5A3 3 0 0 0 2.4 7.2 31 31 0 0 0 2 12a31 31 0 0 0 .4 4.8 3 3 0 0 0 2.1 2.1c1.7.5 7.5.5 7.5.5s5.8 0 7.5-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 22 12a31 31 0 0 0-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z"/></svg></a>
      </div>
    </div>
  </div>
</div>

<header class="nav">
  <div class="container nav-inner">
    <a class="logo" href="{{ $home }}"><img src="{{ asset('images/logo.png') }}" alt="NikolaCars"></a>
    <button class="burger-btn" type="button" aria-label="{{ $isRu ? 'Открыть меню' : 'Відкрити меню' }}" aria-controls="mobileMenu" aria-expanded="false"><span></span><span></span><span></span></button>
    <nav class="menu" id="mobileMenu" aria-hidden="true">
      <div class="mobile-menu-top"><div class="mobile-menu-title">NikolaCars</div><button class="mobile-menu-close" type="button" aria-label="{{ $isRu ? 'Закрыть меню' : 'Закрити меню' }}">×</button></div>
      <a href="{{ $home }}">{{ $isRu ? 'Главная' : 'Головна' }}</a>
      <div class="dropdown">
        <a class="dropdown-toggle" href="{{ $isRu ? '/ru/services/' : '/services/' }}" data-dd-toggle>{{ $isRu ? 'Услуги' : 'Послуги' }} <span class="chev">▾</span></a>
        <div class="dropdown-menu">
          <a href="{{ $isRu ? '/ru/services/prigon-tesla-usa/' : '/services/prigon-tesla-usa/' }}">{{ $isRu ? 'Пригон Tesla из США' : 'Пригін Tesla із США' }}</a>
          <a href="{{ $isRu ? '/ru/services/tesla-service/' : '/services/tesla-service/' }}">{{ $isRu ? 'Обслуживание автомобилей Tesla' : 'Обслуговування автомобілів Tesla' }}</a>
          <a href="{{ $isRu ? '/ru/services/vidnovlennya-sertyfikativ-tesla/' : '/services/vidnovlennya-sertyfikativ-tesla/' }}">{{ $isRu ? 'Восстановление сертификатов Tesla' : 'Відновлення сертифікатів Tesla' }}</a>
          <a href="{{ $isRu ? '/ru/services/firmware-auto/' : '/services/firmware-auto/' }}">{{ $isRu ? 'Прошивка авто' : 'Прошивка авто' }}</a>
        </div>
      </div>
      <a class="active" href="{{ $isRu ? '/ru/parts/' : '/parts/' }}">{{ $isRu ? 'Запчасти' : 'Запчастини' }}</a>
      <a href="{{ $isRu ? '/ru/testimonial/' : '/testimonial/' }}">{{ $isRu ? 'Отзывы' : 'Відгуки' }}</a>
      <a href="{{ $isRu ? '/ru/news/' : '/news/' }}">{{ $isRu ? 'Новости' : 'Новини' }}</a>
      <a href="{{ $isRu ? '/ru/contacts/' : '/contacts/' }}">{{ $isRu ? 'Контакты' : 'Контакти' }}</a>
    </nav>
    <div class="lang"><a class="pill {{ !$isRu ? 'active' : '' }}" href="{{ $uaUrl }}">UA</a><a class="pill {{ $isRu ? 'active' : '' }}" href="{{ $ruUrl }}">RU</a></div>
  </div>
</header>

<button type="button" class="parts-floating-cart" data-open-cart aria-label="{{ $isRu ? 'Открыть корзину' : 'Відкрити кошик' }}">
  {{ $isRu ? 'Корзина' : 'Кошик' }} <span data-cart-count>0</span>
</button>
  @yield('content')
  @stack('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const burger = document.querySelector('.burger-btn');
  const menu = document.getElementById('mobileMenu');
  const close = menu?.querySelector('.mobile-menu-close');
  const dropdown = menu?.querySelector('.dropdown');
  const dropdownToggle = menu?.querySelector('[data-dd-toggle]');
  if (!burger || !menu || !close) return;
  const closeMenu = () => {
    menu.classList.remove('is-open');
    burger.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-hidden', 'true');
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    dropdown?.classList.remove('open');
  };
  burger.addEventListener('click', () => {
    menu.classList.add('is-open');
    burger.setAttribute('aria-expanded', 'true');
    menu.setAttribute('aria-hidden', 'false');
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  });
  close.addEventListener('click', closeMenu);
  dropdownToggle?.addEventListener('click', event => {
    if (matchMedia('(max-width: 900px)').matches) { event.preventDefault(); dropdown?.classList.toggle('open'); }
  });
  menu.addEventListener('click', event => { if (event.target.closest('a:not([data-dd-toggle])')) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
});
</script>
</body>
</html>
