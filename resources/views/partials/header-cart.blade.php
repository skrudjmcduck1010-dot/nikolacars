@php($headerCartRu = ($loc ?? $locale ?? 'uk') === 'ru')
<a class="header-phone" href="tel:+380501753084" data-header-phone aria-label="{{ $headerCartRu ? 'Позвонить' : 'Зателефонувати' }}">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z"/></svg>
</a>
<a class="header-cart" href="{{ $headerCartRu ? '/ru/parts/?cart=open' : '/parts/?cart=open' }}" data-header-cart aria-label="{{ $headerCartRu ? 'Открыть корзину' : 'Відкрити кошик' }}" data-cart-label="{{ $headerCartRu ? 'Открыть корзину' : 'Відкрити кошик' }}">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h2l2.4 12.4a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6"/><circle cx="10" cy="21" r="1"/><circle cx="19" cy="21" r="1"/></svg>
  <span class="header-cart-count" data-header-cart-count aria-live="polite" hidden>0</span>
</a>
<style>
header.nav .lang{align-items:center;flex-shrink:0}
.header-phone{display:none}
.header-phone svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.header-phone:focus-visible{outline:2px solid currentColor;outline-offset:3px}
@media(max-width:900px){.topbar{display:none}.header-phone{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:38px;height:38px;border:1px solid #338546;border-radius:50%;background:#256b36;color:#fff;box-shadow:0 0 0 3px rgba(74,175,97,.18);text-decoration:none}.header-phone:hover{background:#2f8042}}

.header-cart{position:relative;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:38px;height:38px;margin-right:4px;border:1px solid currentColor;border-radius:50%;background:transparent;color:var(--muted);text-decoration:none}
.header-cart:hover{background:transparent;color:var(--text)}
.header-cart:focus-visible{outline:2px solid currentColor;outline-offset:3px}
.header-cart svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.header-cart-count{position:absolute;top:-5px;right:-6px;min-width:19px;height:19px;padding:0 4px;border-radius:10px;background:#256b36;color:#fff;font:700 11px/19px system-ui;text-align:center}
.header-cart-count[hidden]{display:none}
@media(max-width:400px){header.nav .nav-inner{gap:6px}header.nav .lang{gap:4px}header.nav .burger-btn{margin-left:0;flex-shrink:0;width:32px}header.nav .logo img{max-width:125px;height:auto}}
@media(max-width:360px){header.nav .logo img{max-width:105px}.header-phone{width:34px;height:34px}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const phone = document.querySelector('[data-header-phone]');
  const firstPhone = document.querySelector('.topbar a[href^="tel:"]');
  const contactPhones = document.querySelectorAll('.topbar a[href^="tel:"]');
  document.querySelectorAll('[data-menu-phone]').forEach(menuPhone => {
    const source = contactPhones[Number(menuPhone.dataset.menuPhone)];
    if (!source) return;
    const updateMenuPhone = () => {
      menuPhone.setAttribute('href', source.getAttribute('href'));
      menuPhone.querySelector('[data-menu-phone-label]').textContent = source.textContent.replace(/[^\d+()\s-]/g, '').trim();
    };
    updateMenuPhone();
    new MutationObserver(updateMenuPhone).observe(source, { attributes: true, attributeFilter: ['href'], childList: true, characterData: true, subtree: true });
  });
  if (phone && firstPhone) {
    const updatePhone = () => phone.setAttribute('href', firstPhone.getAttribute('href'));
    updatePhone();
    new MutationObserver(updatePhone).observe(firstPhone, { attributes: true, attributeFilter: ['href'] });
  }
  const link = document.querySelector('[data-header-cart]');
  const badge = link.querySelector('[data-header-cart-count]');
  const refresh = () => {
    let cart = [];
    try { const stored = JSON.parse(localStorage.getItem('nikolacars-parts-cart-v1') || '[]'); if (Array.isArray(stored)) cart = stored; } catch (_) {}
    const count = cart.reduce((sum, item) => sum + (item && Number.isInteger(item.id) ? Math.max(0, Number(item.quantity) || 0) : 0), 0);
    badge.textContent = count;
    badge.hidden = count === 0;
    link.setAttribute('aria-label', link.dataset.cartLabel + (count ? ': ' + count : ''));
  };
  link.addEventListener('click', event => {
    if (typeof window.ncCartModal?.open === 'function') {
      event.preventDefault();
      window.ncCartModal.open();
    }
  });
  window.addEventListener('nc:cart-updated', refresh);
  window.addEventListener('storage', refresh);
  window.addEventListener('pageshow', refresh);
  refresh();
});
</script>
