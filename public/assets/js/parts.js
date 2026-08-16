document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('partsCatalog');
  if (!root) return;

  const t = window.partsI18n || {};
  const $ = (selector, context = document) => context.querySelector(selector);
  const $$ = (selector, context = document) => [...context.querySelectorAll(selector)];
  const initialParams = new URLSearchParams(location.search);
  const state = {
    model: initialParams.get('model') || '',
    category: initialParams.get('category') || '',
    modelSlug: root.dataset.modelSlug || '',
    categorySlug: root.dataset.categorySlug || '',
    q: initialParams.get('q') || '',
    sort: initialParams.get('sort') || 'newest',
    page: Math.max(1, Number(initialParams.get('page')) || 1), lastPage: 1, models: [], categories: [], products: [], total: 0
  };
  const cartKey = 'nikolacars-parts-cart-v1';
  let cart = JSON.parse(localStorage.getItem(cartKey) || '[]');
  let cityTimer;
  let warehouseTimer;

  const money = value => `${new Intl.NumberFormat(root.dataset.locale === 'ru' ? 'ru-UA' : 'uk-UA', { maximumFractionDigits: 0 }).format(value)} ${t.uah}`;
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const saveCart = () => { localStorage.setItem(cartKey, JSON.stringify(cart)); renderCart(); renderProducts(); };
  const sectionUrl = (modelSlug = '', categorySlug = '') => {
    const base = root.dataset.catalogBase;
    if (modelSlug && categorySlug) return `${base}/${modelSlug}/${categorySlug}/`;
    if (modelSlug) return `${base}/${modelSlug}/`;
    if (categorySlug) return `${base}/category/${categorySlug}/`;
    return `${base}/`;
  };

  function renderLoading() {
    $('[data-products]').innerHTML = Array.from({ length: 6 }, () => `
      <div class="part-card part-skeleton" aria-hidden="true">
        <div class="part-image"></div>
        <div class="part-card-body"><i></i><i></i><i></i><i></i></div>
      </div>`).join('');
  }

  function applyCatalog(data, append = false) {
    state.models = data.models || [];
    state.categories = data.categories || [];
    state.model = data.selection?.model || state.model;
    state.category = data.selection?.category || state.category;
    state.modelSlug = data.selection?.model_slug || state.modelSlug;
    state.categorySlug = data.selection?.category_slug || state.categorySlug;
    state.products = append ? [...state.products, ...(data.products || [])] : (data.products || []);
    state.total = data.pagination?.total || 0;
    state.page = data.pagination?.page || state.page;
    state.lastPage = data.pagination?.last_page || 1;
    render();
  }

  async function loadCatalog(append = false) {
    const params = new URLSearchParams({ page: state.page, per_page: 24, sort: state.sort });
    if (state.model) params.set('model', state.model);
    if (state.category) params.set('category', state.category);
    if (state.modelSlug) params.set('model_slug', state.modelSlug);
    if (state.categorySlug) params.set('category_slug', state.categorySlug);
    if (state.q) params.set('q', state.q);
    if (!append && state.products.length === 0) renderLoading();
    $('[data-products]').classList.add('loading');
    try {
      const response = await fetch(`${root.dataset.catalogUrl}?${params}`, { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || t.loadError);
      applyCatalog(data, append);
    } catch (error) {
      if (!append) $('[data-products]').innerHTML = '';
      $('[data-empty]').hidden = false;
      $('[data-empty]').textContent = error.message || t.loadError;
    } finally {
      $('[data-products]').classList.remove('loading');
    }
  }

  function render() {
    renderModels();
    renderCategories();
    renderProducts();
    renderPagination();
    $('[data-model-total]').textContent = state.categories.reduce((sum, item) => sum + Number(item.count), 0);
    $('[data-results-title]').textContent = state.category || state.model || t.allProducts;
    $('[data-more]').hidden = state.page >= state.lastPage;
    $('[data-empty]').hidden = state.products.length > 0;
    if (!state.products.length) $('[data-empty]').textContent = t.empty;
    document.title = [t.seoBase, state.category, state.model, t.seoSuffix].filter(Boolean).join(' — ');
  }

  function paginationUrl(page) {
    const params = new URLSearchParams();
    if (page > 1) params.set('page', page);
    if (state.q) params.set('q', state.q);
    if (state.sort !== 'newest') params.set('sort', state.sort);
    const query = params.toString();
    return `${location.pathname}${query ? `?${query}` : ''}`;
  }

  function renderPagination() {
    const pagination = $('[data-pagination]');
    if (!pagination) return;
    pagination.hidden = state.lastPage <= 1;
    if (state.lastPage <= 1) {
      pagination.innerHTML = '';
      return;
    }

    const pages = [...new Set([1, state.lastPage, state.page - 2, state.page - 1, state.page, state.page + 1, state.page + 2])]
      .filter(page => page >= 1 && page <= state.lastPage)
      .sort((a, b) => a - b);
    let previous = null;
    const links = [];
    if (state.page > 1) links.push(`<a href="${paginationUrl(state.page - 1)}" rel="prev">← ${root.dataset.locale === 'ru' ? 'Назад' : 'Назад'}</a>`);
    pages.forEach(page => {
      if (previous !== null && page > previous + 1) links.push('<span class="parts-pagination-gap">…</span>');
      links.push(page === state.page
        ? `<span class="active" aria-current="page">${page}</span>`
        : `<a href="${paginationUrl(page)}">${page}</a>`);
      previous = page;
    });
    if (state.page < state.lastPage) links.push(`<a href="${paginationUrl(state.page + 1)}" rel="next">${root.dataset.locale === 'ru' ? 'Далее' : 'Далі'} →</a>`);
    pagination.innerHTML = links.join('');
  }

  function renderModels() {
    const modelLabelParts = label => {
      const match = String(label || '').trim().match(/^(.*?)(\d{2}\.\d{4}\s*-\s*(?:\d{2}\.\d{4})?)$/);
      return match
        ? { name: match[1].trim(), years: match[2].trim().replace(/\s*-\s*/g, '-') }
        : { name: String(label || '').trim(), years: '' };
    };
    const html = [{ value: '', label: t.allModels, slug: '', count: null }, ...state.models].map(item => {
      const label = modelLabelParts(item.label);
      return `
      <a href="${sectionUrl(item.slug, state.categorySlug)}" class="parts-model-tab ${item.value === state.model ? 'active' : ''}">
        <span class="parts-model-copy"><span class="parts-model-name">${escapeHtml(label.name)}</span>${label.years ? `<span class="parts-model-years">${escapeHtml(label.years)}</span>` : ''}</span>
        ${item.count === null ? '' : `<span class="parts-model-count">${item.count}</span>`}
      </a>`;
    }).join('');
    $('[data-models]').innerHTML = html;
  }

  function renderCategories() {
    $('[data-sidebar-title]').textContent = state.model || t.catalog;
    $('[data-all-parts]').href = sectionUrl(state.modelSlug);
    $('[data-all-parts]').classList.toggle('active', state.category === '');
    $('[data-categories]').innerHTML = state.categories.map(item => `
      <a href="${sectionUrl(state.modelSlug, item.slug)}" class="parts-category ${state.category === item.value ? 'active' : ''}">
        <span>${escapeHtml(item.label)}</span><b>${item.count}</b>
      </a>`).join('');
  }

  function renderProducts() {
    $('[data-products]').innerHTML = state.products.map(product => {
      const inCart = cart.some(item => item.id === product.id);
      const imageUrl = product.thumbnail_url || product.image_url;
      const image = imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(product.name)}" loading="lazy" decoding="async" onerror="this.closest('.part-image').classList.add('no-image');this.remove()">` : '';
      const productUrl = `${root.dataset.productBase}/${product.id}/`;
      return `<article class="part-card">
        <a href="${productUrl}" class="part-image ${image ? '' : 'no-image'}">${image}<span>NIKOLACARS</span></a>
        <div class="part-card-body">
          <h3><a href="${productUrl}">${escapeHtml(product.name)}</a></h3>
          <div class="part-codes">${escapeHtml([product.part_number, product.sku, product.vin].filter(Boolean).join(' · '))}</div>
          <div class="part-category-path">${escapeHtml(product.category_path)}</div>
          <div class="part-price">${money(product.price_uah)}</div>
          <div class="part-stock">${t.inStock}: ${product.quantity}</div>
          <button type="button" class="add-cart ${inCart ? 'added' : ''}" data-add-cart="${product.id}">${inCart ? t.inCart : t.toCart}</button>
        </div>
      </article>`;
    }).join('');
  }

  function renderCart() {
    $('[data-cart-count]').textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
    $('[data-cart-empty]').hidden = cart.length > 0;
    $('[data-checkout]').hidden = cart.length === 0;
    $('[data-cart-lines]').innerHTML = cart.map(item => {
      const imageUrl = item.thumbnail_url || item.image_url;
      return `
      <div class="cart-line">
        <div class="cart-line-image">${imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="">` : ''}</div>
        <div class="cart-line-info"><b>${escapeHtml(item.name)}</b><span>${money(item.price_uah)}</span>
          <div class="cart-quantity"><button type="button" data-cart-minus="${item.id}">−</button><span>${item.quantity}</span><button type="button" data-cart-plus="${item.id}">+</button><button class="cart-remove" type="button" data-cart-remove="${item.id}">×</button></div>
        </div>
      </div>`;
    }).join('');
    $('[data-cart-total]').textContent = money(cart.reduce((sum, item) => sum + item.price_uah * item.quantity, 0));
  }

  function openCart() {
    $('[data-cart-drawer]').classList.add('open');
    $('[data-cart-drawer]').setAttribute('aria-hidden', 'false');
    $('[data-overlay]').hidden = false;
    document.body.classList.add('cart-open');
  }
  function closeCart() {
    $('[data-cart-drawer]').classList.remove('open');
    $('[data-cart-drawer]').setAttribute('aria-hidden', 'true');
    $('[data-overlay]').hidden = true;
    document.body.classList.remove('cart-open');
  }

  document.addEventListener('click', event => {
    const add = event.target.closest('[data-add-cart]');
    if (add) {
      const product = state.products.find(item => item.id === Number(add.dataset.addCart));
      if (product && !cart.some(item => item.id === product.id)) cart.push({ ...product, available: product.quantity, quantity: 1 });
      saveCart(); openCart();
    }
    const minus = event.target.closest('[data-cart-minus]');
    if (minus) { const item = cart.find(row => row.id === Number(minus.dataset.cartMinus)); if (item) item.quantity = Math.max(1, item.quantity - 1); saveCart(); }
    const plus = event.target.closest('[data-cart-plus]');
    if (plus) { const item = cart.find(row => row.id === Number(plus.dataset.cartPlus)); if (item) item.quantity = Math.min(item.quantity + 1, item.available || item.quantity + 1); saveCart(); }
    const remove = event.target.closest('[data-cart-remove]');
    if (remove) { cart = cart.filter(row => row.id !== Number(remove.dataset.cartRemove)); saveCart(); }
    if (event.target.closest('[data-open-cart]')) openCart();
    if (event.target.closest('[data-close-cart]') || event.target.matches('[data-overlay]')) closeCart();
    if (event.target.closest('[data-scroll-catalog]')) root.scrollIntoView({ behavior: 'smooth' });
    if (event.target.closest('[data-success-close]')) $('[data-success]').hidden = true;
  });

  $('[data-filter-form]').addEventListener('submit', event => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    state.q = String(data.get('q') || '').trim(); state.sort = data.get('sort') || 'newest'; state.page = 1;
    history.replaceState(null, '', paginationUrl(1));
    loadCatalog();
  });
  $('[data-sort]')?.addEventListener('change', event => {
    state.sort = event.currentTarget.value || 'newest';
    state.page = 1;
    history.replaceState(null, '', paginationUrl(1));
    loadCatalog();
  });
  $('[data-more]').addEventListener('click', () => { state.page += 1; loadCatalog(true); });

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
    if (!form.reportValidity()) return;
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
      cart = []; saveCart(); form.reset(); closeCart();
      $('[data-success-text]').textContent = t.success.replace(':number', result.number); $('[data-success]').hidden = false;
    } catch (error) { errorBox.textContent = error.message || t.orderError; errorBox.hidden = false; }
    finally { submit.disabled = false; }
  });

  cart = cart.filter(item => item && Number.isInteger(item.id));
  $('[data-filter-form] input[name="q"]').value = state.q;
  if ($(`[data-sort] option[value="${CSS.escape(state.sort)}"]`)) {
    $('[data-sort]').value = state.sort;
  }
  renderCart();
  if (window.initialPartsCatalog) applyCatalog(window.initialPartsCatalog);
  else loadCatalog();
  if (initialParams.get('cart') === 'open') openCart();
});
