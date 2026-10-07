document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('partsCheckout');
  if (!root) return;
  const t = window.checkoutI18n;
  const $ = (selector, context = document) => context.querySelector(selector);
  const $$ = (selector, context = document) => [...context.querySelectorAll(selector)];
  const cartKey = 'nikolacars-parts-cart-v1';
  const readCart = () => { try { const value = JSON.parse(localStorage.getItem(cartKey) || '[]'); return Array.isArray(value) ? value.filter(item => item && Number.isInteger(item.id)) : []; } catch (_) { return []; } };
  let cart = readCart(), cityTimer, warehouseTimer;
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const money = value => new Intl.NumberFormat(root.dataset.locale === 'ru' ? 'ru-UA' : 'uk-UA', { maximumFractionDigits: 0 }).format(value) + ' грн';
  const render = () => {
    $('[data-page-checkout]').hidden = !cart.length;
    $('[data-page-empty]').hidden = cart.length > 0;
    $('[data-checkout-items]').innerHTML = cart.map(item => `<div class="checkout-page-item"><img src="${escapeHtml(item.thumbnail_url || item.image_url || '')}" alt=""><div>${escapeHtml(item.name)}<small>${item.quantity} × ${money(item.price_uah)}</small></div><strong>${money(item.quantity * item.price_uah)}</strong></div>`).join('');
    $('[data-checkout-total]').textContent = money(cart.reduce((sum,item) => sum + item.quantity * item.price_uah, 0));
    $$('[data-cart-count]').forEach(el => { el.textContent = cart.reduce((sum,item) => sum + item.quantity, 0); });
  };
  const saveCart = () => { localStorage.setItem(cartKey, JSON.stringify(cart)); render(); window.dispatchEvent(new Event('nc:cart-updated')); };
  window.addEventListener('nc:cart-edited', () => { cart = readCart(); render(); });
  window.addEventListener('storage', () => { cart = readCart(); render(); });
  $('[data-edit-basket]').addEventListener('click', () => window.ncCartModal.open());
  $('[data-open-cart]')?.addEventListener('click', () => window.ncCartModal.open());
  $$('input[name="delivery_method"]').forEach(input => input.addEventListener('change', () => {
    $('[data-np-fields]').hidden = input.form.delivery_method.value !== 'nova_poshta';
  }));

  async function suggestions(url, params, container, select) {
    const response = await fetch(`${url}?${new URLSearchParams(params)}`, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Directory error');
    container.innerHTML = data.map(item => `<button type="button" data-ref="${escapeHtml(item.ref)}" data-description="${escapeHtml(item.description)}">${escapeHtml(item.description)}</button>`).join('');
    $$('button', container).forEach(button => button.addEventListener('click', () => { select(button.dataset.ref, button.dataset.description); container.innerHTML = ''; }));
  }

  const cityInput = $('input[name="nova_poshta_city"]');
  const warehouseInput = $('input[name="nova_poshta_warehouse"]');
  cityInput.addEventListener('input', () => {
    cityInput.form.nova_poshta_city_ref.value = ''; warehouseInput.value = ''; warehouseInput.form.nova_poshta_warehouse_ref.value = ''; warehouseInput.disabled = true;
    clearTimeout(cityTimer); if (cityInput.value.trim().length < 2) return;
    cityTimer = setTimeout(() => suggestions(root.dataset.citiesUrl, { query: cityInput.value.trim() }, $('[data-city-suggestions]'), (ref, description) => {
      cityInput.value = description; cityInput.form.nova_poshta_city_ref.value = ref; warehouseInput.disabled = false; warehouseInput.focus();
    }).catch(() => {}), 300);
  });
  warehouseInput.addEventListener('input', () => {
    warehouseInput.form.nova_poshta_warehouse_ref.value = ''; clearTimeout(warehouseTimer);
    const cityRef = warehouseInput.form.nova_poshta_city_ref.value; if (!cityRef || warehouseInput.value.trim().length < 1) return;
    warehouseTimer = setTimeout(() => suggestions(root.dataset.warehousesUrl, { city_ref: cityRef, query: warehouseInput.value.trim() }, $('[data-warehouse-suggestions]'), (ref, description) => {
      warehouseInput.value = description; warehouseInput.form.nova_poshta_warehouse_ref.value = ref;
    }).catch(() => {}), 300);
  });

  $('[data-order-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget; const errorBox = $('[data-order-error]'); errorBox.hidden = true;
    if (!cart.length || !form.reportValidity()) return;
    if (form.delivery_method.value === 'nova_poshta' && (!form.nova_poshta_city_ref.value || !form.nova_poshta_warehouse_ref.value)) {
      errorBox.textContent = t.required; errorBox.hidden = false; return;
    }
    const submit = $('.checkout-submit', form); submit.disabled = true;
    const data = Object.fromEntries(new FormData(form).entries());
    data.request_id = crypto.randomUUID();
    data.items = cart.map(item => ({ product_id: item.id, quantity: item.quantity }));
    try {
      const response = await fetch(root.dataset.orderUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content }, body: JSON.stringify(data) });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || t.orderError);
      window.ncAnalytics?.lead('parts_order');
      cart = []; saveCart(); form.reset();
      $('[data-success-text]').textContent = t.success.replace(':number', result.number); $('[data-success]').hidden = false; $('[data-page-empty]').hidden = true;
    } catch (error) { errorBox.textContent = error.message || t.orderError; errorBox.hidden = false; }
    finally { submit.disabled = false; }
  });


  render();
});
