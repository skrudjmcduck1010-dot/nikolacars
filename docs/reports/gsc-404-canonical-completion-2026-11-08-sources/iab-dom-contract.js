// Read-only DOM extraction after ordinary IAB navigation. No fetch, XHR or mutation.
() => {
  const script = Array.from(document.scripts).map(s => s.textContent || '').find(t => t.includes('window.initialPartsCatalog')) || '';
  const match = script.match(/window\.initialPartsCatalog\s*=\s*(\{[^\r\n]*\});/);
  const catalog = match ? JSON.parse(match[1]) : null;
  const productText = document.querySelector('script#productData')?.textContent;
  const product = productText ? JSON.parse(productText) : null;
  const ids = catalog ? (catalog.products || []).map(p => p.id) : null;
  return {
    observed_utc: new Date().toISOString(),
    final_url: location.href,
    title: document.title,
    h1: document.querySelector('h1')?.textContent?.trim() || '',
    canonical: Array.from(document.querySelectorAll('link[rel="canonical"]')).map(x => x.href),
    hreflang: Object.fromEntries(Array.from(document.querySelectorAll('link[hreflang]')).map(x => [x.hreflang, x.href])),
    robots: Array.from(document.querySelectorAll('meta[name="robots"],meta[name="googlebot"]')).map(x => x.content),
    ids,
    unique_id_count: ids ? new Set(ids).size : null,
    pagination: catalog?.pagination || null,
    selection: catalog?.selection || null,
    category_navigation: catalog?.category_navigation || null,
    category_links: Array.from(new Set(Array.from(document.querySelectorAll('aside a[href]')).map(x => x.href).filter(u => u.includes('/parts/')))),
    product: product ? { id: product.id, availability_status: product.availability_status, quantity: product.quantity } : null,
    sold_product_layout: Boolean(document.querySelector('.product-detail.is-sold')),
    // HTTP codes must come from a separate exposed browser response, never inferred from this text.
    noncatalog_body_excerpt: !catalog && !product ? document.body.innerText.slice(0, 700) : null
  };
}
