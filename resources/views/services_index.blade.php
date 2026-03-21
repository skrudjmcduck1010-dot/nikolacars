@extends('layouts.app')

@php
  $isRu = (($locale ?? 'uk') === 'ru');
@endphp

@section('title', $isRu ? 'Услуги NikolaCars — сервис Tesla в Киеве' : 'Послуги NikolaCars — сервіс Tesla в Києві')

@section('description', $isRu
  ? 'Пригон Tesla из США, сервис Tesla, прошивка авто и восстановление сертификатов. Профессиональное СТО Tesla в Киеве.'
  : 'Пригін Tesla із США, сервіс Tesla, прошивка авто та відновлення сертифікатів. Професійне СТО Tesla в Києві.'
)

@section('content')
<section class="page-simple">
  <div class="container">
    <div class="page-simple-card">

      <div class="services-header">
        <p class="services-subtitle">
          {{ $isRu ? 'Перечень услуг нашей компании' : 'Перелік послуг нашої компанії' }}
        </p>
        <h1 class="services-title">
          {{ $isRu ? 'НАШИ УСЛУГИ' : 'НАШІ ПОСЛУГИ' }}
        </h1>
        <div class="services-divider"></div>
      </div>

      <div class="services-grid">

        <!-- Пригон -->
        <a href="{{ $isRu ? '/ru/services/prigon-tesla-usa/' : '/services/prigon-tesla-usa/' }}" class="service-box">
          <div class="service-icon">🚗</div>
          <h3>{{ $isRu ? 'ПРИГОН ИЗ США' : 'ПРИГІН З США' }}</h3>
          <p>
            {{ $isRu
              ? 'Компания NikolaCars поможет выбрать и привезти автомобиль Tesla из США под ключ.'
              : 'Наша компанія NikolaCars допоможе вибрати і привезти автомобіль Tesla з США під ключ.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Обслуживание -->
        <a href="{{ $isRu ? '/ru/services/tesla-service/' : '/services/tesla-service/' }}" class="service-box">
          <div class="service-icon">🛠️</div>
          <h3>{{ $isRu ? 'ОБСЛУЖИВАНИЕ TESLA' : 'ОБСЛУГОВУВАННЯ АВТО' }}</h3>
          <p>
            {{ $isRu
              ? 'Электромобили требуют минимального объёма работ для поддержания идеального состояния.'
              : 'Електричні автомобілі потребують меншого обсягу робіт для підтримання гарного стану.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Прошивка -->
        <a href="{{ $isRu ? '/ru/services/firmware-auto/' : '/services/firmware-auto/' }}" class="service-box">
          <div class="service-icon">💻</div>
          <h3>{{ $isRu ? 'ПРОШИВКА TESLA' : 'ПРОШИВКА АВТО' }}</h3>
          <p>
            {{ $isRu
              ? 'Обновление прошивки электрокара Tesla до актуальной версии — услуги в Киеве от наших специалистов.'
              : 'Оновлення прошивки електрокара Tesla до актуальної версії — послуги в Києві від наших фахівців.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Сертификаты -->
        <a href="{{ $isRu ? '/ru/services/vidnovlennya-sertyfikativ-tesla/' : '/services/vidnovlennya-sertyfikativ-tesla/' }}" class="service-box">
          <div class="service-icon">🔐</div>
          <h3>{{ $isRu ? 'ВОССТАНОВЛЕНИЕ СЕРТИФИКАТОВ' : 'ВІДНОВЛЕННЯ СЕРТИФІКАТІВ' }}</h3>
          <p>
            {{ $isRu
              ? 'Официальное восстановление сертификатов Tesla через сервис Tesla с гарантией стабильной работы.'
              : 'Офіційне відновлення сертифікатів Tesla через сервіс Tesla з гарантією стабільної роботи.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Ремонт батарей -->
        <a href="{{ $isRu ? '/ru/services/tesla-battery-repair' : '/services/tesla-battery-repair' }}" class="service-box">
          <div class="service-icon">🔋</div>
          <h3>{{ $isRu ? 'РЕМОНТ БАТАРЕЙ TESLA' : 'РЕМОНТ БАТАРЕЙ TESLA' }}</h3>
          <p>
            {{ $isRu
              ? 'Диагностика и ремонт высоковольтной батареи Tesla: проверка модулей, балансировка и восстановление ресурса.'
              : 'Діагностика та ремонт високовольтної батареї Tesla: перевірка модулів, балансування та відновлення ресурсу.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Ремонт ручки -->
        <a href="{{ $isRu ? '/ru/services/repair-tesla-door-handle/' : '/services/repair-tesla-door-handle/' }}" class="service-box">
          <div class="service-icon">🚪</div>
          <h3>{{ $isRu ? 'РЕМОНТ РУЧКИ TESLA' : 'РЕМОНТ РУЧКИ TESLA' }}</h3>
          <p>
            {{ $isRu
              ? 'Замена и ремонт ручки двери Tesla: устранение заеданий, восстановление привода и калибровка механизма.'
              : 'Заміна та ремонт ручки дверей Tesla: усунення заїдань, відновлення приводу та калібрування механізму.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Ремонт подрамника -->
        <a href="{{ $isRu ? '/ru/services/tesla-subframe-repair/' : '/services/tesla-subframe-repair/' }}" class="service-box">
          <div class="service-icon">🧰</div>
          <h3>{{ $isRu ? 'РЕМОНТ ПОДРАМНИКА TESLA' : 'РЕМОНТ ПІДРАМНИКА TESLA' }}</h3>
          <p>
            {{ $isRu
              ? 'Диагностика и ремонт подрамника Tesla после ударов и износа: проверка геометрии и восстановление ходовой.'
              : 'Діагностика та ремонт підрамника Tesla після ударів і зносу: перевірка геометрії та відновлення ходової.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Читать далее' : 'Читати далі' }}
          </span>
        </a>

        <!-- Запчасти -->
        <a href="https://nikolacars.com.ua/ua/" class="service-box" target="_blank" rel="noopener noreferrer">
          <div class="service-icon">🧩</div>
          <h3>{{ $isRu ? 'ЗАПЧАСТИ TESLA' : 'ЗАПЧАСТИНИ TESLA' }}</h3>
          <p>
            {{ $isRu
              ? 'Оригинальные и проверенные запчасти Tesla: быстрый подбор, консультация и доставка по Украине.'
              : 'Оригінальні та перевірені запчастини Tesla: швидкий підбір, консультація та доставка по Україні.'
            }}
          </p>
          <span class="service-link">
            {{ $isRu ? 'Перейти в каталог' : 'Перейти в каталог' }}
          </span>
        </a>

      </div>

    </div>
  </div>
</section>
@endsection
