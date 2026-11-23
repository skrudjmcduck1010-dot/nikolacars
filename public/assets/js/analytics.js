(() => {
  'use strict';
  const send = (name, params = {}) => {
    try {
      if (typeof window.gtag === 'function') {
        window.gtag('event', name, { ...params, send_to: 'G-5GRKRBQXET' });
      }
    } catch (_) { /* Analytics must never interrupt a customer action. */ }
  };
  const emitLeadOnce = (name, params) => {
    // Claim before enqueueing. A lost analytics hit is safer than a duplicate conversion.
    // Refuse unidentifiable/unstorable events rather than sending customer data or duplicate hits.
    if (!/^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(params.request_id || '')) return;
    try {
      if (typeof window.gtag !== 'function') return;
      const key = 'nc-lead-event-v1:' + name + ':' + params.request_id;
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, 'claimed');
      send(name, params);
    } catch (_) { /* Storage/analytics must never block intake. */ }
  };
  const item = product => ({
    item_id: String(product.id),
    item_name: String(product.name || '').slice(0, 100),
    item_brand: 'Tesla',
    price: Number(product.price_uah) || 0,
    quantity: 1
  });
  window.ncAnalytics = {
    lead: (formId, result = {}) => {
      const business = formId === 'parts_order' ? 'parts'
        : ['sto', 'import', 'general'].includes(result.business_unit) ? result.business_unit : 'general';
      const params = { form_id: formId, lead_source: 'website', business_unit: business,
        request_id: result.request_id, delivery_status: result.crm_saved === true ? 'crm' : 'accepted' };
      // Keep the existing imported action during migration. Never make both events primary.
      if (!result.request_id) {
        // Compatibility with stale form JS. It cannot supply a safe reconciliation identity.
        send('generate_lead', { form_id: formId, lead_source: 'website', business_unit: business });
        return;
      }
      emitLeadOnce('generate_lead', params);
      if (business === 'parts' || (business === 'sto' && result.crm_saved === true)) {
        emitLeadOnce(business === 'parts' ? 'parts_generate_lead' : 'sto_generate_lead', params);
      }
    },
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
