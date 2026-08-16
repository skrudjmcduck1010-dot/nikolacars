@php
  $partsMenuLocale = ($locale ?? 'uk') === 'ru' ? 'ru' : 'uk';
  $partsMenuBase = $partsMenuLocale === 'ru' ? '/ru/parts' : '/parts';
  $partsMenuModels = [
    ['slug' => 'model-3-06-2017-12-2023', 'label' => 'Model 3 06.2017–12.2023'],
    ['slug' => 'model-3-highland-01-2024', 'label' => 'Model 3 Highland 01.2024–'],
    ['slug' => 'model-s-02-2012-03-2016', 'label' => 'Model S 02.2012–03.2016'],
    ['slug' => 'model-s-04-2016-01-2021', 'label' => 'Model S 04.2016–01.2021'],
    ['slug' => 'model-x-09-2015-02-2021', 'label' => 'Model X 09.2015–02.2021'],
    ['slug' => 'model-y-01-2020-01-2025', 'label' => 'Model Y 01.2020–01.2025'],
  ];
@endphp
<div class="dropdown parts-nav-dropdown">
  <a class="dropdown-toggle {{ ($active ?? false) ? 'active' : '' }}"
     href="{{ $partsMenuBase }}/"
     data-dd-toggle
     aria-haspopup="true">
    {{ $partsMenuLocale === 'ru' ? 'Запчасти' : 'Запчастини' }} <span class="chev">▾</span>
  </a>
  <div class="dropdown-menu">
    <a href="{{ $partsMenuBase }}/">{{ $partsMenuLocale === 'ru' ? 'Все запчасти Tesla' : 'Усі запчастини Tesla' }}</a>
    @foreach($partsMenuModels as $model)
      <a href="{{ $partsMenuBase }}/{{ $model['slug'] }}/">{{ $model['label'] }}</a>
    @endforeach
  </div>
</div>
