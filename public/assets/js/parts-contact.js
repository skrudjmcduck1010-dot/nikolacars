(() => {
  const widget = document.querySelector('[data-parts-contact]');
  const toggle = widget?.querySelector('[data-parts-contact-toggle]');
  const panel = widget?.querySelector('#partsContactPanel');
  const form = widget?.querySelector('[data-parts-callback-form]');
  const openCallback = widget?.querySelector('[data-parts-callback-open]');
  if (!toggle || !panel) return;
  const online = widget.querySelector('#partsContactOnline');
  const updateOnline = () => {
    let isOpen = false;
    try {
      // Match callback handling: Monday-Friday, 10:00-18:00 in Kyiv, including DST.
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Kyiv', weekday: 'short', hour: '2-digit', hourCycle: 'h23',
      }).formatToParts(new Date());
      const weekday = parts.find(part => part.type === 'weekday')?.value;
      const hour = Number(parts.find(part => part.type === 'hour')?.value);
      isOpen = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].includes(weekday) && hour >= 10 && hour < 18;
    } catch (_) {}
    if (online) {
      online.hidden = !isOpen;
      if (isOpen) toggle.setAttribute('aria-describedby', online.id);
      else toggle.removeAttribute('aria-describedby');
    }
  };
  const refreshOnline = () => {
    updateOnline();
    window.setTimeout(refreshOnline, 60000 - Date.now() % 60000 + 50);
  };
  refreshOnline();
  document.addEventListener('visibilitychange', updateOnline);
  window.addEventListener('pageshow', updateOnline);
  const phone = form?.querySelector('input');
  const submit = form?.querySelector('[type="submit"]');
  const status = form?.querySelector('[role="status"]');
  let pending = false;
  let attempt = null;
  const storageKey = 'nc-parts-callback-attempt';
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey));
    if (saved && /^[0-9a-f-]{36}$/i.test(saved.id) && /^\+380\d{9}$/.test(saved.phone)) {
      attempt = saved;
      if (phone) { phone.value = saved.phone.slice(4); phone.readOnly = true; }
    }
  } catch (_) {}
  const setView = view => {
    for (const [element, name] of [[panel, 'contacts'], [form, 'callback']]) {
      if (!element) continue;
      element.hidden = view !== name;
      element.classList.toggle('active', view === name);
    }
    toggle.setAttribute('aria-expanded', String(Boolean(view)));
    toggle.setAttribute('aria-controls', view === 'callback' ? 'partsCallbackPanel' : 'partsContactPanel');
  };
  toggle.addEventListener('click', () => setView(panel.hidden && (!form || form.hidden) ? 'contacts' : null));
  openCallback?.addEventListener('click', () => { setView('callback'); phone?.focus(); });
  form?.querySelector('[data-parts-callback-close]')?.addEventListener('click', () => { setView('contacts'); openCallback?.focus(); });
  document.addEventListener('click', event => { if (!widget.contains(event.target)) setView(null); });
  panel.addEventListener('click', event => { if (event.target.closest('a')) setView(null); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && (!panel.hidden || (form && !form.hidden))) {
      setView(null); toggle.focus();
    }
  });
  phone?.addEventListener('input', () => {
    let digits = phone.value.replace(/\D/g, '');
    if (digits.length >= 12 && digits.startsWith('380')) digits = digits.slice(3);
    else if (digits.length >= 10 && digits.startsWith('0')) digits = digits.slice(1);
    phone.value = digits.slice(0, 9);
  });
  form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending) return;
    const fullPhone = '+380' + phone.value;
    const show = (message, error = false) => {
      status.hidden = false; status.textContent = message;
      status.toggleAttribute('data-error', error);
    };
    if (!/^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/.test(fullPhone)) {
      show(form.dataset.invalid, true); phone.focus(); return;
    }
    attempt ||= {id: crypto.randomUUID(), phone: fullPhone};
    try { sessionStorage.setItem(storageKey, JSON.stringify(attempt)); } catch (_) {}
    pending = true; submit.disabled = true; phone.readOnly = true; status.hidden = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({request_id: attempt.id, phone: attempt.phone, page: location.origin + location.pathname}),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok !== true) { show(form.dataset.error, true); return; }
      attempt = null;
      try { sessionStorage.removeItem(storageKey); } catch (_) {}
      phone.value = ''; phone.readOnly = false;
      show(form.dataset.success);
    } catch (_) {
      show(form.dataset.error, true);
    } finally {
      pending = false; submit.disabled = false;
      phone.readOnly = Boolean(attempt);
    }
  });
})();
