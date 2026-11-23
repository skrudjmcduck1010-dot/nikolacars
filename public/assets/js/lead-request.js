(() => {
  'use strict';
  const pending = new Map();
  const preparing = new Map();
  const key = form => 'nc-lead-request-v1:' + location.pathname + ':' + form;
  const canonical = value => {
    if (Array.isArray(value)) return value.map(canonical);
    if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])]));
    return value;
  };
  const digest = async (payload, salt) => {
    const data = payload instanceof FormData ? Object.fromEntries(payload.entries()) : { ...payload };
    for (const field of ['_token', 'request_id', 'utm']) delete data[field];
    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(salt + JSON.stringify(canonical(data))));
    return Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('');
  };
  const prepareId = async (form, payload) => {
    try {
      const saved = pending.get(form) || JSON.parse(sessionStorage.getItem(key(form)) || 'null');
      if (saved && /^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(saved.id || '') && saved.digest === await digest(payload, saved.id)) {
        return saved.id;
      }
      const id = crypto.randomUUID();
      const record = { id, digest: await digest(payload, id) };
      // Changed content is a new explicit intent; unchanged retries/reloads retain the UUID.
      // Salted digest only: never store form contents, names or a phone in the browser.
      sessionStorage.setItem(key(form), JSON.stringify(record));
      pending.set(form, record);
      return id;
    } catch (_) {
      // Forms still work without storage; the server uses its legacy session/payload identity.
      return null;
    }
  };
  const requestId = (form, payload) => {
    const task = (preparing.get(form) || Promise.resolve()).then(() => prepareId(form, payload));
    preparing.set(form, task.catch(() => null));
    return task;
  };
  window.ncLeadRequest = {
    requestId,
    post: async (url, options, form) => {
      const id = await requestId(form, options.body);
      if (id) options.body.set('request_id', id);
      return fetch(url, options);
    },
    complete: (form, result) => {
      if (result?.ok !== true) return;
      try { sessionStorage.removeItem(key(form)); } catch (_) {}
      pending.delete(form);
    }
  };
})();
