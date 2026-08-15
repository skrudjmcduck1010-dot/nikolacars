<!doctype html>
<html lang="{{ $locale === 'ru' ? 'ru' : 'uk' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla' }} — NikolaCars</title>
  <meta name="description" content="{{ $locale === 'ru' ? 'Оригинальные запчасти Tesla в наличии в Киеве.' : 'Оригінальні запчастини Tesla в наявності у Києві.' }}">
  <link rel="canonical" href="https://nikolacars.kiev.ua{{ $locale === 'ru' ? '/ru/parts/' : '/parts/' }}">
  <link rel="alternate" hreflang="uk-UA" href="https://nikolacars.kiev.ua/parts/">
  <link rel="alternate" hreflang="ru-UA" href="https://nikolacars.kiev.ua/ru/parts/">
  <link rel="icon" href="{{ asset('favicon.ico') }}">
  <link rel="stylesheet" href="{{ asset('assets/css/parts.css') }}?v=2">
</head>
<body class="parts-page">
  <header class="parts-header">
    <div class="parts-container parts-header-inner">
      <a href="{{ $locale === 'ru' ? '/ru/' : '/' }}" class="parts-brand">
        <img src="{{ asset('images/logo.png') }}" alt="NikolaCars">
        <span>{{ $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla' }}</span>
      </a>
      <nav class="parts-header-actions">
        <a class="parts-lang" href="{{ $locale === 'ru' ? '/parts/' : '/ru/parts/' }}">{{ $locale === 'ru' ? 'UA' : 'RU' }}</a>
        <button type="button" class="parts-nav-button" data-scroll-catalog>{{ $locale === 'ru' ? 'Каталог' : 'Каталог' }}</button>
        <button type="button" class="parts-nav-button parts-cart-button" data-open-cart>
          {{ $locale === 'ru' ? 'Корзина' : 'Кошик' }} <span data-cart-count>0</span>
        </button>
      </nav>
    </div>
  </header>
  @yield('content')
  @stack('scripts')
</body>
</html>
