import { chromium } from 'playwright-core';

const vin = '5YJ3E1EB8JF091651';
const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
const context = browser.contexts()[0];
const page = context.pages().find(p => p.url().includes('parts.tesla.com')) ?? context.pages()[0];
const url = new URL(page.url());
const catalogExternalReference = url.searchParams.get('catalogExternalReference');
console.error({url: page.url(), catalogExternalReference, vin});
const cookies = await context.cookies(['https://parts.tesla.com', 'https://epcapi.tesla.com']);
const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
const api = async (path) => {
  const res = await fetch(`https://epcapi.tesla.com/${path}`, {
    headers: {
      'accept': 'application/json, text/plain, */*',
      'cookie': cookieHeader,
      'origin': 'https://parts.tesla.com',
      'referer': page.url(),
      'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148 Safari/537.36',
    },
  });
  const text = await res.text();
  let payload;
  try { payload = JSON.parse(text); } catch { payload = {raw: text.slice(0, 500)}; }
  return {status: res.status, payload};
};
const result = await api(`api/catalogs/${encodeURIComponent(catalogExternalReference)}/categories?VIN=${encodeURIComponent(vin)}`);
console.log(JSON.stringify({status: result.status, keys: Object.keys(result.payload ?? {}), first: Array.isArray(result.payload?.responseObject) ? result.payload.responseObject[0] : result.payload}, null, 2));
