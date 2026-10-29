@php
  // Messenger links use the department's real number, not a dynamic tracking number.
  $partsContactPhone = '+380990698380';
@endphp
<div class="contact-widget parts-contact-widget" data-parts-contact>
  <div class="contact-panel" id="partsContactPanel" hidden>
    <a href="viber://chat?number={{ rawurlencode($partsContactPhone) }}" class="contact-item">
      <span class="contact-icon viber" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 32 32" fill="none" focusable="false"><path fill="#7360f2" d="M25.6 3.8C20.2.7 8.2 1.1 4.8 5.7 2.3 9.2 2 18.4 4.5 22.4c1.2 1.8 3.1 2.8 5.5 3.4v4.1c0 .7.8 1 1.3.5l4-4.1c3.9.2 8-.4 10.3-2.2 4.8-3.7 5.3-17.1 0-20.3Z"/><path stroke="#fff" stroke-width="1.5" stroke-linecap="round" d="M17.2 7.8c4.2.2 6.7 2.9 6.8 7M17.5 10.9c2.4.2 3.5 1.4 3.7 3.7M17.8 13.7c.7.1 1 .4 1.1 1.1"/><path fill="#fff" d="M11.6 8.7c-.5-.4-1.1-.4-1.6-.1L8.6 9.7c-.7.5-.8 1.3-.6 2.2 1.5 5.7 5.2 9.3 10.8 10.8.8.2 1.6.1 2.1-.6l1.1-1.5c.4-.5.3-1.1-.1-1.5l-2.6-2.2c-.5-.4-1.1-.4-1.6 0l-1 .8c-1.9-.9-3.3-2.3-4.2-4.2l.8-1c.4-.5.4-1.1 0-1.6l-1.7-2.2Z"/></svg></span>
      <span><span class="contact-title">Viber</span><span class="contact-sub">{{ $partsContactPhone }}</span></span>
    </a>
    <a href="https://t.me/{{ $partsContactPhone }}" target="_blank" rel="noopener" class="contact-item">
      <span class="contact-icon telegram" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 32 32" fill="none" focusable="false"><circle cx="16" cy="16" r="15" fill="#2AABEE"/><path fill="#fff" d="m7.5 15.4 16-6.2c.8-.3 1.5.2 1.2 1.3l-2.7 12.7c-.2.9-.7 1.1-1.4.7l-4.1-3-2 1.9c-.2.2-.4.4-.8.4l.3-4.2 7.7-7c.3-.3-.1-.5-.5-.2l-9.5 6-4.1-1.3c-.9-.3-.9-.9-.1-1.1Z"/></svg></span>
      <span><span class="contact-title">Telegram</span><span class="contact-sub">{{ $partsContactPhone }}</span></span>
    </a>
    <a href="mailto:nikola.carsua@gmail.com" class="contact-item">
      <span class="contact-icon mail" aria-hidden="true">✉️</span>
      <span><span class="contact-title">{{ $isRu ? 'Написать нам' : 'Написати нам' }}</span><span class="contact-sub">nikola.carsua@gmail.com</span></span>
    </a>
    <button type="button" class="contact-item contact-item-btn" data-parts-callback-open>
      <span class="contact-icon phone" aria-hidden="true">📞</span>
      <span><span class="contact-title">{{ $isRu ? 'Перезвоните мне' : 'Передзвоніть мені' }}</span><span class="contact-sub">{{ $isRu ? 'В рабочее время' : 'У робочий час' }}</span></span>
    </button>
  </div>
  <form class="callback-panel" id="partsCallbackPanel" hidden data-parts-callback-form novalidate
    action="{{ route('parts.callback') }}" method="post"
    data-invalid="{{ $isRu ? 'Введите корректный номер: 9 цифр после +380.' : 'Введіть коректний номер: 9 цифр після +380.' }}"
    data-error="{{ $isRu ? 'Не удалось подтвердить заявку. Повторите отправку — повторная попытка не создаст дубль.' : 'Не вдалося підтвердити заявку. Повторіть відправлення — повторна спроба не створить дубль.' }}"
    data-success="{{ $isRu ? 'Спасибо! Заявка принята. Мы перезвоним в рабочее время: пн–пт, 10:00–18:00.' : 'Дякуємо! Заявку прийнято. Ми передзвонимо у робочий час: пн–пт, 10:00–18:00.' }}">
    <button type="button" class="callback-close" data-parts-callback-close aria-label="{{ $isRu ? 'Закрыть' : 'Закрити' }}">×</button>
    <label class="callback-title" for="partsCallbackPhone">{!! $isRu ? 'Введите ваш номер телефона<br>и мы перезвоним в рабочее время' : 'Введіть ваш номер телефону<br>і ми передзвонимо у робочий час' !!}</label>
    <div class="callback-phone"><span class="callback-prefix">+380</span><input type="tel" id="partsCallbackPhone" class="callback-input" inputmode="numeric" required placeholder="XXXXXXXXX" autocomplete="tel-national" aria-describedby="partsCallbackStatus"></div>
    <button type="submit" class="callback-submit">{{ $isRu ? 'Жду звонка' : 'Чекаю дзвінка' }}</button>
    <p id="partsCallbackStatus" role="status" aria-live="polite" hidden></p>
  </form>
  <button type="button" class="contact-toggle" aria-label="{{ $isRu ? 'Связаться с разборкой' : 'Зв’язатися з розбіркою' }}" aria-controls="partsContactPanel" aria-expanded="false" aria-describedby="partsContactOnline" data-parts-contact-toggle><svg width="34" height="34" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 3h16a5 5 0 0 1 5 5v22l-6-5H8a5 5 0 0 1-5-5V8a5 5 0 0 1 5-5Z"/><circle cx="10" cy="14" r="1.4" fill="#8b1822"/><circle cx="16" cy="14" r="1.4" fill="#8b1822"/><circle cx="22" cy="14" r="1.4" fill="#8b1822"/></svg></button>
  <span class="parts-contact-online" id="partsContactOnline"><span class="parts-contact-online-dot" aria-hidden="true"></span>{{ $isRu ? 'Мы онлайн' : 'Ми онлайн' }}</span>
</div>
<script src="{{ asset('assets/js/parts-contact.js') }}?v=20261027-callback-1" defer></script>
