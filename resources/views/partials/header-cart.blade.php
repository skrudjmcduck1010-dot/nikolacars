@php($headerCartRu = ($loc ?? $locale ?? 'uk') === 'ru')
<a class="header-cart" href="{{ $headerCartRu ? '/ru/parts/?cart=open' : '/parts/?cart=open' }}" data-header-cart aria-label="{{ $headerCartRu ? 'Открыть корзину' : 'Відкрити кошик' }}" data-cart-label="{{ $headerCartRu ? 'Открыть корзину' : 'Відкрити кошик' }}">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h2l2.4 12.4a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6"/><circle cx="10" cy="21" r="1"/><circle cx="19" cy="21" r="1"/></svg>
  <span class="header-cart-count" data-header-cart-count aria-live="polite" hidden>0</span>
</a>
<style>
header.nav .lang{align-items:center;flex-shrink:0}
.header-cart{position:relative;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:38px;height:38px;margin-right:4px;border:1px solid currentColor;border-radius:50%;background:transparent;color:var(--muted);text-decoration:none}
.header-cart:hover{background:transparent;color:var(--text)}
.header-cart:focus-visible{outline:2px solid currentColor;outline-offset:3px}
.header-cart svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.header-cart-count{position:absolute;top:-5px;right:-6px;min-width:19px;height:19px;padding:0 4px;border-radius:10px;background:#256b36;color:#fff;font:700 11px/19px system-ui;text-align:center}
.header-cart-count[hidden]{display:none}
@media(max-width:400px){header.nav .nav-inner{gap:6px}header.nav .lang{gap:4px}header.nav .burger-btn{margin-left:0;flex-shrink:0;width:32px}header.nav .logo img{max-width:125px;height:auto}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
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
    if (document.getElementById('partsCatalog')) {
      event.preventDefault();
      document.querySelector('.parts-floating-cart[data-open-cart]')?.click();
    }
  });
  window.addEventListener('nc:cart-updated', refresh);
  window.addEventListener('storage', refresh);
  window.addEventListener('pageshow', refresh);
  refresh();
});
</script>
