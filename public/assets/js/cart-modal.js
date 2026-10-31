// Remove disallowed catalog photos from baskets saved before the storefront filter.
(() => {
  const blocked = value => {
    if (typeof value !== 'string') return false;
    let url = value.toLowerCase();
    try { url = decodeURIComponent(url); } catch (_) {}
    return /(?:^|[/.])tesla\.com(?:[/:?#]|$)/.test(url) || url.includes('tesla-official/') || url.includes('competitor-catalog/tesla_official/');
  };
  try {
    const key = 'nikolacars-parts-cart-v1', cart = JSON.parse(localStorage.getItem(key) || '[]');
    if (!Array.isArray(cart)) return;
    let changed = false;
    for (const item of cart) {
      if (!item || typeof item !== 'object') continue;
      if (Array.isArray(item.images)) { const photos = item.images.filter(url => !blocked(url)); if (photos.length !== item.images.length) { item.images = photos; changed = true; } }
      if (blocked(item.image_url)) { item.image_url = item.images?.[0] || null; item.thumbnail_url = item.image_url; changed = true; }
      if (blocked(item.thumbnail_url)) { item.thumbnail_url = item.image_url || null; changed = true; }
    }
    if (changed) localStorage.setItem(key, JSON.stringify(cart));
  } catch (_) {}
})();
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.querySelector('[data-basket-modal]');
  if (!modal) return;
  const key = 'nikolacars-parts-cart-v1';
  const ru = modal.dataset.locale === 'ru';
  const read = () => { try { const data = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(data) ? data.filter(item => item && Number.isInteger(item.id)) : []; } catch (_) { return []; } };
  const money = amount => new Intl.NumberFormat(ru ? 'ru-UA' : 'uk-UA', { maximumFractionDigits: 0 }).format(amount) + ' грн';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  let focusBefore;
  const render = () => {
    const cart = read();
    modal.querySelector('[data-basket-items]').innerHTML = cart.map(item => `<div class="basket-item"><img src="${escape(item.thumbnail_url || item.image_url || '')}" alt=""><div class="basket-name">${escape(item.name)}<small>${escape(item.part_number || item.sku || '')}</small></div><div class="basket-quantity"><button type="button" data-basket-minus="${item.id}" aria-label="${ru ? 'Уменьшить количество' : 'Зменшити кількість'}" ${item.quantity <= 1 ? 'disabled' : ''}>−</button><output>${item.quantity}</output><button type="button" data-basket-plus="${item.id}" aria-label="${ru ? 'Увеличить количество' : 'Збільшити кількість'}" ${item.available && item.quantity >= item.available ? 'disabled' : ''}>+</button></div><div class="basket-price">${money(item.price_uah * item.quantity)}</div><button type="button" class="basket-remove" data-basket-remove="${item.id}" aria-label="${ru ? 'Удалить товар' : 'Видалити товар'}">×</button></div>`).join('');
    modal.querySelector('[data-basket-empty]').hidden = cart.length > 0;
    modal.querySelector('[data-basket-summary]').hidden = cart.length === 0;
    modal.querySelector('[data-basket-total]').textContent = money(cart.reduce((sum, item) => sum + item.price_uah * item.quantity, 0));
  };
  const open = () => { render(); if (!modal.open) { focusBefore = document.activeElement; modal.showModal(); document.body.classList.add('basket-open'); } };
  const close = () => modal.close();
  modal.addEventListener('close', () => { document.body.classList.remove('basket-open'); if (focusBefore?.isConnected) focusBefore.focus(); });
  modal.addEventListener('click', event => {
    if (event.target.closest('[data-basket-close]')) close();
    if (event.target === modal) { const r = modal.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close(); }
    const button = event.target.closest('[data-basket-minus],[data-basket-plus],[data-basket-remove]');
    if (button) {
      let cart = read();
      const id = Number(button.dataset.basketMinus || button.dataset.basketPlus || button.dataset.basketRemove);
      const item = cart.find(row => row.id === id);
      if (!item) return;
      if (button.hasAttribute('data-basket-remove')) cart = cart.filter(row => row.id !== id);
      else item.quantity = button.hasAttribute('data-basket-minus') ? Math.max(1, item.quantity - 1) : Math.min(item.quantity + 1, item.available || item.quantity + 1);
      localStorage.setItem(key, JSON.stringify(cart));
      window.dispatchEvent(new Event('nc:cart-edited'));
      window.dispatchEvent(new Event('nc:cart-updated'));
      render();
    }
    if (event.target.closest('[data-basket-checkout]')) {
      close();
      if (!document.getElementById('partsCheckout')) location.href = ru ? '/ru/parts/checkout/' : '/parts/checkout/';
    }
  });
  window.ncCartModal = { open };
  window.addEventListener('nc:cart-updated', () => { if (modal.open) render(); });
});
