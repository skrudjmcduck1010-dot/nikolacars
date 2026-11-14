(() => {
  'use strict';
  const send = (name, params = {}) => {
    try {
      if (typeof window.gtag === 'function') {
        window.gtag('event', name, { ...params, send_to: 'G-5GRKRBQXET' });
      }
    } catch (_) { /* Analytics must never interrupt a customer action. */ }
  };
  const item = product => ({
    item_id: String(product.id),
    item_name: String(product.name || '').slice(0, 100),
    item_brand: 'Tesla',
    price: Number(product.price_uah) || 0,
    quantity: 1
  });
  window.ncAnalytics = {
    lead: formId => send('generate_lead', { form_id: formId, lead_source: 'website' }),
    addToCart: product => send('add_to_cart', {
      currency: 'UAH', value: Number(product.price_uah) || 0, items: [item(product)]
    }),
    search: (query, count) => {
      // Free-text searches can contain phone numbers or email addresses.
      const term = String(query || '').trim();
      const isTeslaPartNumber = /^\d{7}-?\d{2}-?[a-z]$/i.test(term);
      if (!term || /@/.test(term) || (!isTeslaPartNumber && /(?:\+?\d[\s()-]*){9,}/.test(term))) return;
      send('search', { search_term: term.slice(0, 100), results_count: Number(count) || 0 });
    }
  };
  document.addEventListener('DOMContentLoaded', () => {
    let product;
    try { product = JSON.parse(document.getElementById('productData')?.textContent || 'null'); } catch (_) {}
    if (product?.id) send('view_item', {
      currency: 'UAH', value: Number(product.price_uah) || 0, items: [item(product)]
    });
    document.addEventListener('click', event => {
      const link = event.target.closest?.('a[href]');
      if (!link) return;
      const url = new URL(link.href, location.href);
      let method;
      if (url.protocol === 'tel:') method = 'phone';
      else if (url.protocol === 'mailto:') method = 'email';
      else if (url.protocol === 'viber:') method = 'viber';
      else if (url.protocol === 'tg:' || ['t.me', 'telegram.me'].includes(url.hostname)) method = 'telegram';
      else if (url.protocol === 'whatsapp:' || ['wa.me', 'api.whatsapp.com'].includes(url.hostname)) method = 'whatsapp';
      if (!method) return;
      const params = { contact_method: method };
      if (product?.id) params.item_id = String(product.id);
      send(method === 'phone' ? 'click_phone' : method === 'email' ? 'click_email' : 'click_messenger', params);
    });
  });
})();
