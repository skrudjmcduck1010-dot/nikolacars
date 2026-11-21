(() => {
  const storageKey = 'nikolacars.parts.ad-click';
  const keys = ['gclid', 'gbraid', 'wbraid'];
  const valid = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,512}$/.test(value);
  const query = new URLSearchParams(location.search);
  const landing = Object.fromEntries(keys.map(key => [key, query.get(key)]).filter(([, value]) => valid(value)));
  let attribution = Object.keys(landing).length ? landing : undefined;
  try {
    if (attribution) {
      sessionStorage.setItem(storageKey, JSON.stringify(attribution));
    } else {
      const saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
      const restored = Object.fromEntries(keys.map(key => [key, saved?.[key]]).filter(([, value]) => valid(value)));
      if (Object.keys(restored).length) attribution = restored;
    }
  } catch (_) {
    // Keep a valid landing click available on this page if storage is unavailable.
  }
  window.ncPartsAttribution = () => attribution && { ...attribution };
})();
