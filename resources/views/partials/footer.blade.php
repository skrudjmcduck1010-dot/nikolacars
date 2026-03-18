@php
  // Use locale passed from controller/view if available, otherwise app locale.
  $loc  = (($locale ?? app()->getLocale() ?? 'uk') === 'ru') ? 'ru' : 'uk';
  $isRu = ($loc === 'ru');
@endphp

<footer class="site-footer">
  {{-- TOP SOCIAL BAR --}}
  <div class="footer-socialbar">
    <div class="container footer-socialbar-inner">

      <a class="footer-sociallink" href="https://www.facebook.com/nikolacarsua/" target="_blank" rel="noopener">
        <span class="footer-socialname">FACEBOOK</span>
        <span class="footer-socialicon" aria-hidden="true">
          <!-- facebook -->
          <svg viewBox="0 0 24 24"><path d="M13.5 22v-8h2.7l.4-3h-3.1V9.1c0-.9.3-1.6 1.6-1.6H16.7V4.8c-.3 0-1.4-.1-2.7-.1-2.6 0-4.4 1.6-4.4 4.6V11H7v3h2.6v8h3.9Z"/></svg>
        </span>
      </a>

      <a class="footer-sociallink" href="https://www.youtube.com/channel/UCkD7JjB6KBPMZN3BkhBFxtQ" target="_blank" rel="noopener">
        <span class="footer-socialname">YOUTUBE</span>
        <span class="footer-socialicon" aria-hidden="true">
          <!-- youtube -->
          <svg viewBox="0 0 24 24"><path d="M21.6 7.2a3.2 3.2 0 0 0-2.2-2.3C17.5 4.4 12 4.4 12 4.4s-5.5 0-7.4.5A3.2 3.2 0 0 0 2.4 7.2 33 33 0 0 0 2 12a33 33 0 0 0 .4 4.8 3.2 3.2 0 0 0 2.2 2.3c1.9.5 7.4.5 7.4.5s5.5 0 7.4-.5a3.2 3.2 0 0 0 2.2-2.3A33 33 0 0 0 22 12a33 33 0 0 0-.4-4.8ZM10 15.5v-7l6 3.5-6 3.5Z"/></svg>
        </span>
      </a>

      <a class="footer-sociallink" href="https://www.instagram.com/nikolacarskyiv/" target="_blank" rel="noopener">
        <span class="footer-socialname">INSTAGRAM</span>
        <span class="footer-socialicon" aria-hidden="true">
          <!-- instagram -->
          <svg viewBox="0 0 24 24"><path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9A5.5 5.5 0 0 1 16.5 22h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm0 2A3.5 3.5 0 0 0 4 7.5v9A3.5 3.5 0 0 0 7.5 20h9A3.5 3.5 0 0 0 20 16.5v-9A3.5 3.5 0 0 0 16.5 4h-9ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm5.6-.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0Z"/></svg>
        </span>
      </a>

      <a class="footer-sociallink" href="https://x.com/NikolaCars" target="_blank" rel="noopener">
        <span class="footer-socialname">TWITTER</span>
        <span class="footer-socialicon" aria-hidden="true">
          <!-- x -->
          <svg viewBox="0 0 24 24"><path d="M18.9 2H22l-6.9 7.9L23 22h-6.2l-4.9-6.4L6.3 22H2l7.5-8.6L1 2h6.4l4.5 5.9L18.9 2Zm-1.1 18h1.7L7.5 3.9H5.7L17.8 20Z"/></svg>
        </span>
      </a>

    </div>
  </div>

  {{-- MAIN FOOTER --}}
  <div class="footer-main">
    <div class="container footer-grid">

      {{-- Col 1 --}}
      <div class="footer-col">
        <div class="footer-text">
          {{ $isRu
            ? 'Наша компания предоставляет услуги поиска и покупки, а также ремонта любой сложности автомобилей Tesla'
            : 'Наша компанія надає послуги пошуку та купівлі, а також ремонту будь-якої складності автомобілів Tesla'
          }}
        </div>

        <div class="footer-contacts">
          <div class="footer-contact">
            <span class="footer-ico">📍</span>
            <span>{{ $isRu ? 'г. Киев, ул. Коллекторная, 30' : 'м. Київ, вул. Колекторна, 30' }}</span>
          </div>
          <div class="footer-contact">
            <span class="footer-ico">📞</span>
            <a href="tel:+380975120255">+38 (097) 512 02 55</a>
          </div>
          <div class="footer-contact">
            <span class="footer-ico">✉️</span>
            <a href="mailto:nikola.carsua@gmail.com">nikola.carsua@gmail.com</a>
          </div>
        </div>
      </div>

{{-- Col 2 --}}
<div class="footer-col">
  <div class="footer-title">{{ $isRu ? 'ПОЛЕЗНЫЕ ССЫЛКИ' : 'КОРИСНІ ПОСИЛАННЯ' }}</div>
  <div class="footer-title-line"></div>

  <div class="footer-links">
    <a href="{{ $isRu ? '/ru/services/prigon-tesla-usa/' : '/services/prigon-tesla-usa/' }}">» {{ $isRu ? 'Пригон из США' : 'Пригін зі США' }}</a>
    <a href="{{ $isRu ? '/ru/services/tesla-service/' : '/services/tesla-service/' }}">» {{ $isRu ? 'Обслуживание авто' : 'Обслуговування авто' }}</a>
    <a href="{{ $isRu ? '/ru/services/vidnovlennya-sertyfikativ-tesla/' : '/services/vidnovlennya-sertyfikativ-tesla/' }}">» {{ $isRu ? 'Восстановление сертификатов' : 'Відновлення сертифікатів' }}</a>
    <a href="{{ $isRu ? '/ru/services/firmware-auto/' : '/services/firmware-auto/' }}">» {{ $isRu ? 'Прошивка авто' : 'Прошивка авто' }}</a>
    <a href="{{ $isRu ? '/ru/testimonial/' : '/testimonial/' }}">» {{ $isRu ? 'Отзывы' : 'Відгуки' }}</a>
    <a href="{{ $isRu ? '/ru/news/' : '/news/' }}">» {{ $isRu ? 'Новости' : 'Новини' }}</a>

    {{-- Privacy Policy --}}
    <a href="{{ $isRu ? '/ru/privacy-policy/' : '/privacy-policy/' }}">
      » {{ $isRu ? 'Политика конфиденциальности' : 'Політика конфіденційності' }}
    </a>
  </div>
</div>


      {{-- Col 3 --}}
      <div class="footer-col">
        <div class="footer-title">{{ $isRu ? 'ПОСЛЕДНИЕ НОВОСТИ' : 'НЕЩОДАВНІ НОВИНИ' }}</div>
        <div class="footer-title-line"></div>

        <div class="footer-news">
          <a href="{{ $isRu ? '/ru/news/tesla-crossover-usa/' : '/news/tesla-crossover-usa/' }}">
            {{ $isRu ? 'Tesla кроссовер под ключ из США' : 'Тесла кросовер під ключ із США' }}
          </a>
          <a href="{{ $isRu ? '/ru/news/cheap-model/' : '/news/cheap-model/' }}">
            {{ $isRu ? 'Сколько стоит самая дешёвая модель Tesla?' : 'Скільки коштує найдешевша модель Tesla?' }}
          </a>
          <a href="{{ $isRu ? '/ru/news/tesla-plaid/' : '/news/tesla-plaid/' }}">
            {{ $isRu ? 'Про Tesla Plaid' : 'Про Tesla Plaid' }}
          </a>
        </div>
      </div>

    </div>
  </div>

  <div class="footer-bottom">
    <div class="container footer-bottom-inner">
      <div>© {{ date('Y') }} NikolaCars. All rights reserved.</div>
    </div>
  </div>
</footer>
