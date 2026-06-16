import { chromium, firefox } from 'playwright-core';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const defaultProfileDir = path.join(root, 'storage', 'app', 'tesla-official-browser-profile');
const defaultFirefoxProfileDir = path.join(root, 'storage', 'app', 'tesla-official-firefox-profile');
const defaultEdgeProfileDir = path.join(root, 'storage', 'app', 'tesla-official-edge-profile');
const defaultOperaProfileDir = path.join(root, 'storage', 'app', 'tesla-official-opera-profile');
const defaultStorageStatePath = path.join(root, 'storage', 'app', 'tesla-official-storage-state.json');
const defaultChromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const defaultEdgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const defaultOperaPath = path.join(process.env.LOCALAPPDATA ?? '', 'Programs', 'Opera', 'opera.exe');

function argValue(name, fallback = null) {
  const prefix = `--${name}=`;
  const arg = process.argv.find((value) => value.startsWith(prefix));
  return arg ? arg.slice(prefix.length) : fallback;
}

function hasArg(name) {
  return process.argv.includes(`--${name}`);
}

async function evaluateWithNavigationRetry(page, fn, arg, attempts = 3) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      return await page.evaluate(fn, arg);
    } catch (error) {
      lastError = error;
      const message = String(error?.message ?? error);
      const isNavigationRace = message.includes('Execution context was destroyed')
        || message.includes('Cannot find context with specified id');
      const isTransientFetchError = message.includes('Failed to fetch')
        || message.includes('net::ERR_')
        || message.includes('Load failed');

      if ((!isNavigationRace && !isTransientFetchError) || attempt === attempts) {
        throw error;
      }

      await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
      await page.waitForTimeout(isTransientFetchError ? 5000 * attempt : 1500 * attempt);
    }
  }

  throw lastError;
}

async function browserContextJsonRequest(context, url, attempts = 6) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const response = await context.request.get(url, {
        headers: {
          accept: 'application/json, text/plain, */*',
          referer: 'https://parts.tesla.com/en-US/landingpage',
        },
        timeout: 60000,
      });
      const text = await response.text();
      let payload = null;
      try {
        payload = JSON.parse(text);
      } catch {
        payload = { raw: text };
      }

      return {
        status: response.status(),
        payload,
      };
    } catch (error) {
      lastError = error;
      if (attempt === attempts) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, 5000 * attempt));
    }
  }

  throw lastError;
}

async function browserPageJsonRequest(page, url, attempts = 6) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const text = await page.locator('body').innerText({ timeout: 15000 });

      let payload = null;
      try {
        payload = JSON.parse(text);
      } catch {
        payload = { raw: text };
      }

      return {
        status: response?.status() ?? 0,
        payload,
      };
    } catch (error) {
      lastError = error;
      if (attempt === attempts) {
        throw error;
      }

      await page.waitForTimeout(5000 * attempt).catch(() => {});
    }
  }

  throw lastError;
}

function normalizePartNumber(value) {
  return String(value ?? '').replace(/[^a-z0-9]/gi, '').toUpperCase();
}

function partUrl(match, partNumber) {
  const catalog = match.catalogExternalReference ?? '';
  const group = match.systemGroupExternalReference ?? '';
  if (!catalog || !group) {
    return null;
  }

  const query = new URLSearchParams({
    partNumber,
    catalogExternalReference: catalog,
    vin: '',
  });

  return `https://parts.tesla.com/en-US/catalogs/${catalog}/systemGroups/${group}?${query}`;
}

function rowPartUrl(row, partNumber) {
  const catalog = row.catalogExternalReference ?? row.catalogExternalRef ?? row.catalogExternalReferenceId ?? '';
  const group = row.systemGroupExternalReference ?? row.systemGroupExternalRef ?? row.systemGroupExternalReferenceId ?? '';

  if (!catalog || !group) {
    return null;
  }

  const query = new URLSearchParams({
    partNumber,
    catalogExternalReference: catalog,
    vin: '',
  });

  return `https://parts.tesla.com/en-US/catalogs/${catalog}/systemGroups/${group}?${query}`;
}

function refsFromPartUrl(value) {
  if (!value) {
    return {};
  }

  try {
    const url = new URL(value, 'https://parts.tesla.com');
    const pathMatch = url.pathname.match(/\/catalogs\/([^/]+)\/systemGroups\/([^/?#]+)/i);

    return {
      catalogExternalReference: url.searchParams.get('catalogExternalReference') ?? pathMatch?.[1] ?? null,
      systemGroupExternalReference: pathMatch?.[2] ?? url.searchParams.get('systemGroupExternalReference') ?? null,
    };
  } catch {
    return {};
  }
}

function absoluteResourceUrl(value) {
  const url = String(value ?? '').trim();
  if (!url) {
    return null;
  }

  if (/^https?:\/\//i.test(url)) {
    return url;
  }

  return `https://epc.tesla.com/${url.replace(/^\/+/, '')}`;
}

function payloadImageUrls(payload) {
  let images = payload?.partImageURLs
    ?? payload?.partImageUrls
    ?? payload?.images
    ?? payload?.systemGroupImages
    ?? [];

  if (typeof images === 'string') {
    try {
      images = JSON.parse(images.trim());
    } catch {
      images = [];
    }
  }

  if (!Array.isArray(images)) {
    return [];
  }

  return [...new Set(images
    .map((item) => {
      if (typeof item === 'string') {
        return item;
      }

      if (!item || typeof item !== 'object') {
        return null;
      }

      return item.ImageURL ?? item.imageURL ?? item.imageUrl ?? item.url ?? null;
    })
    .map(absoluteResourceUrl)
    .filter(Boolean))];
}

function normalizeResponseRows(payload) {
  const body = payload?.responseObject ?? payload ?? {};
  const candidates = [
    body.parts,
    body.data,
    body.results,
    body.items,
    Array.isArray(body) ? body : null,
  ];

  for (const candidate of candidates) {
    if (Array.isArray(candidate)) {
      return candidate;
    }
  }

  return [];
}

function normalizePartRow(row, requestedPartNumber, source = 'network') {
  const partNumber = row.partNumber ?? row.part_number ?? row.PartNumber ?? row.number ?? null;

  return {
    source,
    visibility: source === 'page' ? 'visible' : 'hidden',
    part_number: partNumber,
    title: row.title ?? row.partTitle ?? row.name ?? row.description ?? row.Description ?? null,
    description: row.description ?? row.Description ?? row.title ?? row.partTitle ?? row.name ?? null,
    localized_description: row.localizedDescription ?? row.LocalizedDescription ?? null,
    model: row.model ?? row.modelName ?? row.catalogName ?? row.Model ?? null,
    category: row.categoryTitle ?? row.category ?? row.Category ?? null,
    subcategory: row.subcategoryTitle ?? row.subcategory ?? row.Subcategory ?? null,
    group: row.systemGroupTitle ?? row.group ?? row.Group ?? null,
    catalogExternalReference: row.catalogExternalReference ?? row.catalogExternalRef ?? row.catalogExternalReferenceId ?? null,
    categoryExternalReference: row.categoryExternalReference ?? row.categoryExternalRef ?? row.categoryExternalReferenceId ?? null,
    subcategoryExternalReference: row.subcategoryExternalReference ?? row.subcategoryExternalRef ?? row.subcategoryExternalReferenceId ?? null,
    systemGroupExternalReference: row.systemGroupExternalReference ?? row.systemGroupExternalRef ?? row.systemGroupExternalReferenceId ?? null,
    url: partUrl(row, partNumber ?? requestedPartNumber) ?? rowPartUrl(row, partNumber ?? requestedPartNumber),
  };
}

function partNumberPrefix(value) {
  const normalized = normalizePartNumber(value);
  return normalized.length >= 7 ? normalized.slice(0, 7) : '';
}

function extractRowsFromTableText(text) {
  const lines = String(text ?? '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  const rows = [];

  for (let index = 0; index < lines.length; index += 1) {
    const tabCells = lines[index].split('\t').map((cell) => cell.trim());
    if (/^[0-9]{7}-[A-Z0-9]{2}-[A-Z0-9]$/i.test(tabCells[0] ?? '') && tabCells.length > 1) {
      rows.push({
        partNumber: tabCells[0],
        description: tabCells[1] ?? null,
        localizedDescription: tabCells[2] ?? null,
        model: tabCells[3] ?? null,
        category: tabCells[4] ?? null,
        subcategory: tabCells[5] ?? null,
        group: tabCells[6] ?? null,
      });
      continue;
    }

    if (!/^[0-9]{7}-[A-Z0-9]{2}-[A-Z0-9]$/i.test(lines[index])) {
      continue;
    }

    rows.push({
      partNumber: lines[index],
      description: lines[index + 1] ?? null,
      model: lines[index + 2] ?? null,
      category: lines[index + 3] ?? null,
      subcategory: lines[index + 4] ?? null,
      group: lines[index + 5] ?? null,
    });
  }

  return rows;
}

function blockedPageReason({ url = '', title = '', text = '' } = {}) {
  const haystack = `${url}\n${title}\n${text}`.toLowerCase();

  if (haystack.includes('access denied')
    || haystack.includes('errors.edgesuite.net')
    || haystack.includes("you don't have permission to access")) {
    return 'access_denied';
  }

  if (haystack.includes('blocking access to required security tools')
    || haystack.includes('disable your vpn and connect to a trusted network')
    || haystack.includes('required security tools')) {
    return 'security_blocked';
  }

  if (haystack.includes('http failure response')
    || (haystack.includes('unknown error') && haystack.includes('partsearch'))
    || haystack.includes('api/catalogs/partsearch')) {
    return 'api_error';
  }

  if (haystack.includes('auth.tesla.com')
    || haystack.includes('tesla auth - sign in')
    || (haystack.includes('sign in') && haystack.includes('email') && haystack.includes('tesla'))) {
    return 'auth_required';
  }

  return null;
}

function errorStatus(errors) {
  if (errors.some((error) => error?.error === 'auth_required')) {
    return 'auth_required';
  }

  if (errors.some((error) => error?.error === 'security_blocked')) {
    return 'security_blocked';
  }

  return 'api_error';
}

function uniqueMatches(rows) {
  const seen = new Set();
  const unique = [];

  for (const row of rows) {
    const key = JSON.stringify([
      normalizePartNumber(row.part_number),
      row.title,
      row.model,
      row.category,
      row.subcategory,
      row.group,
      row.url,
    ]);

    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    unique.push(row);
  }

  return unique;
}

async function readPartNumbers() {
  const partNumberArgs = process.argv
    .filter((value) => value.startsWith('--part-number='))
    .map((value) => value.slice('--part-number='.length).trim())
    .filter(Boolean);

  const inputPath = argValue('input');
  if (!inputPath) {
    return partNumberArgs;
  }

  const raw = await fs.readFile(path.resolve(inputPath), 'utf8');
  const parsed = JSON.parse(raw);
  const fileParts = Array.isArray(parsed)
    ? parsed
    : Array.isArray(parsed.part_numbers)
      ? parsed.part_numbers
      : [];

  return [...partNumberArgs, ...fileParts.map((value) => String(value).trim()).filter(Boolean)];
}

async function launchContext({ headless }) {
  const profileDir = argValue('profile-dir', defaultProfileDir);
  const executablePath = argValue('chrome-path', defaultChromePath);

  await fs.mkdir(profileDir, { recursive: true });

  return chromium.launchPersistentContext(profileDir, {
    executablePath,
    headless,
    viewport: { width: 1440, height: 950 },
    locale: 'en-US',
    args: [
      '--disable-blink-features=AutomationControlled',
      '--no-first-run',
      '--no-default-browser-check',
    ],
  });
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function launchSearchContext({ headless }) {
  const storageStatePath = argValue('storage-state', defaultStorageStatePath);
  const executablePath = argValue('chrome-path', defaultChromePath);

  if (await fileExists(storageStatePath)) {
    const browser = await chromium.launch({
      executablePath,
      headless,
      args: [
        '--disable-blink-features=AutomationControlled',
        '--no-first-run',
        '--no-default-browser-check',
      ],
    });
    const context = await browser.newContext({
      storageState: storageStatePath,
      viewport: { width: 1440, height: 950 },
      locale: 'en-US',
    });

    return { browser, context };
  }

  const context = await launchContext({ headless });
  return { browser: null, context };
}

async function login() {
  const context = await launchContext({ headless: false });
  const page = context.pages()[0] ?? await context.newPage();

  await page.goto('https://parts.tesla.com/en-US/catalogs', { waitUntil: 'domcontentloaded', timeout: 60000 });

  console.log('');
  console.log('Tesla official browser profile is open.');
  console.log('Log in manually if Tesla asks for it, choose any catalog once, then return here and press Enter.');
  console.log('Profile:', argValue('profile-dir', defaultProfileDir));

  await new Promise((resolve) => {
    process.stdin.resume();
    process.stdin.once('data', resolve);
  });

  const storageStatePath = argValue('storage-state', defaultStorageStatePath);
  await fs.mkdir(path.dirname(storageStatePath), { recursive: true });
  await context.storageState({ path: storageStatePath });
  await context.close();
  console.log('Saved Tesla browser session profile.');
  console.log('Saved storage state:', storageStatePath);
}

async function firefoxLogin() {
  const profileDir = argValue('profile-dir', defaultFirefoxProfileDir);
  await fs.mkdir(profileDir, { recursive: true });

  const context = await firefox.launchPersistentContext(profileDir, {
    headless: false,
    viewport: { width: 1440, height: 950 },
    locale: 'en-US',
  });
  const page = context.pages()[0] ?? await context.newPage();

  await page.goto('https://parts.tesla.com/en-US/find-part', { waitUntil: 'domcontentloaded', timeout: 60000 });

  console.log('');
  console.log('Tesla official Firefox parser profile is open.');
  console.log('Log in manually if Tesla asks for it, open Find Part once, then return here and press Enter.');
  console.log('Profile:', profileDir);

  await new Promise((resolve) => {
    process.stdin.resume();
    process.stdin.once('data', resolve);
  });

  await context.close();
  console.log('Saved Tesla Firefox parser profile.');
}

async function searchViaApi(context, page, partNumber, countries) {
  const normalized = normalizePartNumber(partNumber);
  const matches = [];
  const similarMatches = [];
  const errors = [];

  for (const country of countries) {
    try {
      const params = new URLSearchParams({
        Term: partNumber,
        CatalogExternalReference: '',
        SessionID: crypto.randomUUID(),
        CountryCode: country,
      });
      const response = await context.request.get(`https://epcapi.tesla.com/api/catalogs/partSearch?${params}`, {
        headers: {
          Accept: 'application/json, text/plain, */*',
          'Accept-Language': 'en-US,en;q=0.9',
          Authorization: 'Bearer 123',
          Origin: 'https://parts.tesla.com',
          Referer: 'https://parts.tesla.com/',
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
          'X-Correlation-ID': crypto.randomUUID(),
        },
        timeout: 25000,
      });
      const text = await response.text();
      const payload = response.ok()
        ? { ok: true, json: JSON.parse(text) }
        : { ok: false, status: response.status(), text: text.slice(0, 160) };

      if (!payload.ok) {
        errors.push({ country, status: payload.status, error: payload.text });
        continue;
      }

      for (const part of normalizeResponseRows(payload.json)) {
        const match = {
          country,
          ...normalizePartRow(part, partNumber, 'api'),
        };

        if (normalizePartNumber(match.part_number) === normalized) {
          matches.push(match);
        } else if (match.part_number) {
          similarMatches.push(match);
        }
      }
    } catch (error) {
      errors.push({ country, error: String(error.message ?? error).slice(0, 160) });
    }

    await page.waitForTimeout(Number(argValue('sleep-ms', 250)));
  }

  return {
    part_number: partNumber,
    method: 'api',
    found: matches.length > 0,
    matches: uniqueMatches(matches).slice(0, 10),
    similar_matches: uniqueMatches(similarMatches).slice(0, 10),
    errors,
  };
}

async function searchViaFindPartPage(page, partNumber) {
  const normalized = normalizePartNumber(partNumber);
  const url = `https://parts.tesla.com/en-US/find-part?${new URLSearchParams({ searchTerm: partNumber })}`;
  const networkRows = [];
  const errors = [];

  const responsePromise = page.waitForResponse(
    (response) => response.url().includes('/api/catalogs/partSearch') && response.url().includes('Term='),
    { timeout: 30000 },
  ).catch((error) => {
    errors.push({ stage: 'network', error: String(error.message ?? error).slice(0, 160) });
    return null;
  });

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const response = await responsePromise;

  if (response) {
    try {
      const text = await response.text();

      if (!response.ok()) {
        errors.push({ stage: 'network', status: response.status(), error: text.slice(0, 160) });
      } else {
        const payload = JSON.parse(text);
        for (const row of normalizeResponseRows(payload)) {
          networkRows.push(normalizePartRow(row, partNumber, 'network'));
        }
      }
    } catch (error) {
      errors.push({ stage: 'network_parse', error: String(error.message ?? error).slice(0, 160) });
    }
  }

  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(Number(argValue('sleep-ms', 250)));

  let bodyText = '';
  try {
    bodyText = await page.locator('body').innerText({ timeout: 10000 });
  } catch (error) {
    errors.push({ stage: 'dom', error: String(error.message ?? error).slice(0, 160) });
  }

  const blockReason = blockedPageReason({
    url: page.url(),
    title: await page.title().catch(() => ''),
    text: bodyText,
  });
  if (blockReason) {
    errors.push({ stage: 'page', error: blockReason });
  }

  const visibleRows = extractRowsFromTableText(bodyText).map((row) => normalizePartRow(row, partNumber, 'page'));
  const rows = [...networkRows, ...visibleRows];
  const matches = rows.filter((row) => normalizePartNumber(row.part_number) === normalized);
  const similarMatches = rows.filter((row) => row.part_number && normalizePartNumber(row.part_number) !== normalized);

  return {
    part_number: partNumber,
    method: 'find-part-page',
    url,
    status: matches.length > 0
      ? 'exact'
      : similarMatches.length > 0
        ? 'similar'
        : errors.length > 0
          ? errorStatus(errors)
          : 'not_found',
    found: matches.length > 0,
    matches: uniqueMatches(matches).slice(0, 10),
    similar_matches: uniqueMatches(similarMatches).slice(0, 10),
    errors,
  };
}

async function search() {
  const partNumbers = [...new Set((await readPartNumbers()).map((value) => value.trim()).filter(Boolean))];
  const method = argValue('method', 'find-part-page');
  const countries = argValue('countries', 'US,CA,MX,DE,NO,GB')
    .split(',')
    .map((value) => value.trim().toUpperCase())
    .filter(Boolean);
  const headless = !hasArg('headed');
  const { browser, context } = await launchSearchContext({ headless });
  const page = context.pages()[0] ?? await context.newPage();

  await page.goto('https://parts.tesla.com/en-US/landingpage', { waitUntil: 'domcontentloaded', timeout: 60000 });

  const results = [];
  for (const partNumber of partNumbers) {
    results.push(method === 'api'
      ? await searchViaApi(context, page, partNumber, countries)
      : await searchViaFindPartPage(page, partNumber));
  }

  await context.close();
  await browser?.close();
  console.log(JSON.stringify(results, null, 2));
}

async function catalogSnapshot() {
  const headless = !hasArg('headed');
  const maxCatalogs = Number(argValue('max-catalogs', 1));
  const maxSystemGroups = Number(argValue('max-system-groups', 3));
  const maxParts = Number(argValue('max-parts', 20));
  const sleepMs = Number(argValue('sleep-ms', 100));
  const countryCode = argValue('country', 'US');
  const { browser, context } = await launchSearchContext({ headless });
  const page = context.pages()[0] ?? await context.newPage();
  let authorizationHeader = 'Bearer 123';

  page.on('request', async (request) => {
    if (!request.url().includes('https://epcapi.tesla.com/')) {
      return;
    }

    try {
      const headers = await request.allHeaders();
      if (typeof headers.authorization === 'string' && headers.authorization.startsWith('Bearer ')) {
        authorizationHeader = headers.authorization;
      }
    } catch {
      // Best effort only: the fallback public token is enough for some endpoints.
    }
  });

  await page.goto('https://parts.tesla.com/en-US/landingpage', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(1500);

  const ensureAuthorization = async (catalogExternalReference, systemGroupExternalReference) => {
    if (authorizationHeader !== 'Bearer 123') {
      return;
    }

    const url = `https://parts.tesla.com/en-US/catalogs/${catalogExternalReference}/systemGroups/${systemGroupExternalReference}?catalogExternalReference=${catalogExternalReference}&vin=`;
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(2500);
  };

  const apiGet = async (pathName, { authenticated = false } = {}) => {
    const response = await context.request.get(`https://epcapi.tesla.com/${pathName}`, {
      headers: {
        Accept: 'application/json, text/plain, */*',
        'Accept-Language': 'en-US,en;q=0.9',
        Authorization: authenticated ? authorizationHeader : 'Bearer 123',
        Origin: 'https://parts.tesla.com',
        Referer: 'https://parts.tesla.com/',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'X-Correlation-ID': crypto.randomUUID(),
      },
      timeout: 30000,
    });
    const text = await response.text();

    if (!response.ok()) {
      throw new Error(`GET ${pathName} failed ${response.status()}: ${text.slice(0, 180)}`);
    }

    const json = JSON.parse(text);
    return json?.responseObject ?? json;
  };

  const catalogsPayload = await apiGet(`api/catalogs?countryCode=${encodeURIComponent(countryCode)}`);
  const vehicleCatalogs = (Array.isArray(catalogsPayload) ? catalogsPayload : [])
    .filter((catalog) => Number(catalog.catalogModelTypeId ?? 0) === 1)
    .filter((catalog) => String(catalog.name ?? '').toLowerCase() !== 'roadster')
    .slice(0, maxCatalogs > 0 ? maxCatalogs : undefined);

  const catalogs = [];
  let savedParts = 0;
  let fetchedGroups = 0;

  for (const catalog of vehicleCatalogs) {
    const catalogExternalReference = String(catalog.externalReference ?? '').trim();
    if (!catalogExternalReference) {
      continue;
    }

    const treePayload = await apiGet(`api/catalogs/${encodeURIComponent(catalogExternalReference)}/categories?CountryCode=${encodeURIComponent(countryCode)}`);
    const categories = Array.isArray(treePayload?.categories)
      ? treePayload.categories
      : Array.isArray(treePayload)
        ? treePayload
        : [];
    const snapshotCatalog = {
      catalog,
      tree: treePayload,
      categories,
      system_group_details: [],
    };

    for (const category of categories) {
      for (const subcategory of (category.subCategories ?? category.subcategories ?? [])) {
        for (const systemGroup of (subcategory.systemGroups ?? subcategory.systemgroups ?? [])) {
          if (maxSystemGroups > 0 && fetchedGroups >= maxSystemGroups) {
            break;
          }
          if (maxParts > 0 && savedParts >= maxParts) {
            break;
          }

          const systemGroupExternalReference = String(systemGroup.externalReference ?? systemGroup.id ?? '').trim();
          if (!systemGroupExternalReference) {
            continue;
          }

          await ensureAuthorization(catalogExternalReference, systemGroupExternalReference);

          const details = await apiGet(
            `api/catalogs/${encodeURIComponent(catalogExternalReference)}/systemgroups/${encodeURIComponent(systemGroupExternalReference)}`,
            { authenticated: true },
          );
          fetchedGroups += 1;
          savedParts += Array.isArray(details?.parts) ? details.parts.length : 0;
          snapshotCatalog.system_group_details.push({
            category_external_reference: category.externalReference ?? category.id ?? null,
            subcategory_external_reference: subcategory.externalReference ?? subcategory.id ?? null,
            system_group_external_reference: systemGroupExternalReference,
            details,
          });

          if (sleepMs > 0) {
            await page.waitForTimeout(sleepMs);
          }
        }
        if ((maxSystemGroups > 0 && fetchedGroups >= maxSystemGroups) || (maxParts > 0 && savedParts >= maxParts)) {
          break;
        }
      }
      if ((maxSystemGroups > 0 && fetchedGroups >= maxSystemGroups) || (maxParts > 0 && savedParts >= maxParts)) {
        break;
      }
    }

    catalogs.push(snapshotCatalog);
  }

  await context.close();
  await browser?.close();

  console.log(JSON.stringify({
    country_code: countryCode,
    catalogs,
  }));
}

async function cdpFindPartSearch() {
  const endpoint = argValue('cdp', 'http://127.0.0.1:9222');
  const partNumbers = [...new Set((await readPartNumbers()).map((value) => value.trim()).filter(Boolean))];
  const delayMs = Number(argValue('delay-ms', 7000));
  const browser = await chromium.connectOverCDP(endpoint);
  const context = browser.contexts()[0] ?? await browser.newContext();
  const page = context.pages().find((candidate) => candidate.url().includes('parts.tesla.com'))
    ?? context.pages()[0]
    ?? await context.newPage();
  const results = [];

  for (const partNumber of partNumbers) {
    const normalized = normalizePartNumber(partNumber);
    const url = `https://parts.tesla.com/en-US/find-part?${new URLSearchParams({ searchTerm: partNumber })}`;
    const errors = [];
    const rows = [];
    const apiRows = [];

    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(Number(argValue('page-wait-ms', 8000)));

      const initialText = await page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
      const blockReason = blockedPageReason({
        url: page.url(),
        title: await page.title().catch(() => ''),
        text: initialText,
      });
      if (blockReason) {
        errors.push({ stage: 'page', error: blockReason });
        throw new Error(blockReason);
      }

      const apiPayload = await evaluateWithNavigationRetry(page, async ({ partNumber }) => {
        const response = await fetch(`https://epcapi.tesla.com/api/catalogs/partSearch?${new URLSearchParams({
          Term: partNumber,
          CatalogExternalReference: '',
          SessionID: crypto.randomUUID(),
          CountryCode: 'US',
        })}`, {
          credentials: 'include',
          headers: {
            Accept: 'application/json, text/plain, */*',
          },
        });

        return {
          status: response.status,
          payload: await response.json().catch(async () => ({ raw: await response.text() })),
        };
      }, { partNumber }).catch((error) => {
        errors.push({ stage: 'part_search_api', error: String(error.message ?? error).slice(0, 180) });
        return null;
      });

      if (apiPayload && apiPayload.status >= 200 && apiPayload.status < 300) {
        for (const row of normalizeResponseRows(apiPayload.payload)) {
          apiRows.push(normalizePartRow(row, partNumber, 'cdp-api'));
        }
      } else if (apiPayload) {
        errors.push({ stage: 'part_search_api', status: apiPayload.status });
      }

      const tableRows = await page.locator('table tbody tr').all();
      for (const row of tableRows) {
        const cells = await row.locator('td').allInnerTexts().catch(() => []);
        if (cells.length < 7) {
          continue;
        }
        const href = await row.locator('td').first().locator('a').first().getAttribute('href').catch(() => null);
        const refs = refsFromPartUrl(href);
        const rowPartNumber = cells[0]?.trim() || null;

        rows.push({
          source: 'page',
          visibility: 'visible',
          part_number: rowPartNumber,
          description: cells[1]?.trim() || null,
          localized_description: cells[2]?.trim() || null,
          model: cells[3]?.trim() || null,
          category: cells[4]?.trim() || null,
          subcategory: cells[5]?.trim() || null,
          group: cells[6]?.trim() || null,
          catalogExternalReference: refs.catalogExternalReference ?? null,
          systemGroupExternalReference: refs.systemGroupExternalReference ?? null,
          url: href ? new URL(href, 'https://parts.tesla.com').toString() : null,
        });
      }

      if (rows.length === 0) {
        const bodyText = await page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
        const fallbackBlockReason = blockedPageReason({
          url: page.url(),
          title: await page.title().catch(() => ''),
          text: bodyText,
        });
        if (fallbackBlockReason) {
          errors.push({ stage: 'page', error: fallbackBlockReason });
        }
        for (const row of extractRowsFromTableText(bodyText)) {
          const normalizedRow = normalizePartRow(row, partNumber, 'page');
          const matchingApiRow = apiRows.find((apiRow) => JSON.stringify([
            normalizePartNumber(apiRow.part_number),
            apiRow.model,
            apiRow.category,
            apiRow.subcategory,
            apiRow.group,
          ]) === JSON.stringify([
            normalizePartNumber(normalizedRow.part_number),
            normalizedRow.model,
            normalizedRow.category,
            normalizedRow.subcategory,
            normalizedRow.group,
          ]));

          rows.push({
            ...normalizedRow,
            catalogExternalReference: normalizedRow.catalogExternalReference ?? matchingApiRow?.catalogExternalReference ?? null,
            categoryExternalReference: normalizedRow.categoryExternalReference ?? matchingApiRow?.categoryExternalReference ?? null,
            subcategoryExternalReference: normalizedRow.subcategoryExternalReference ?? matchingApiRow?.subcategoryExternalReference ?? null,
            systemGroupExternalReference: normalizedRow.systemGroupExternalReference ?? matchingApiRow?.systemGroupExternalReference ?? null,
            url: normalizedRow.url ?? matchingApiRow?.url ?? null,
          });
        }
      }
    } catch (error) {
      errors.push({ error: String(error.message ?? error).slice(0, 180) });
    }

    const visibleKeys = new Set(rows.map((row) => JSON.stringify([
      normalizePartNumber(row.part_number),
      row.model,
      row.category,
      row.subcategory,
      row.group,
    ])));
    const hiddenRows = apiRows
      .filter((row) => !visibleKeys.has(JSON.stringify([
        normalizePartNumber(row.part_number),
        row.model,
        row.category,
        row.subcategory,
        row.group,
      ])))
      .map((row) => ({
        ...row,
        visibility: 'hidden',
      }));
    const mergedRows = uniqueMatches([...rows, ...hiddenRows]);

    for (const row of mergedRows) {
      const catalogExternalReference = row.catalogExternalReference ?? refsFromPartUrl(row.url).catalogExternalReference;
      const systemGroupExternalReference = row.systemGroupExternalReference ?? refsFromPartUrl(row.url).systemGroupExternalReference;

      if (!catalogExternalReference || !systemGroupExternalReference || !row.part_number) {
        continue;
      }

      row.catalogExternalReference = catalogExternalReference;
      row.systemGroupExternalReference = systemGroupExternalReference;
      row.url = row.url ?? `https://parts.tesla.com/en-US/catalogs/${catalogExternalReference}/systemGroups/${systemGroupExternalReference}?${new URLSearchParams({
        partNumber: row.part_number,
        catalogExternalReference,
      })}`;

      const detailPayload = await evaluateWithNavigationRetry(page, async ({ catalogExternalReference, systemGroupExternalReference }) => {
        const response = await fetch(`https://epcapi.tesla.com/api/catalogs/${catalogExternalReference}/systemgroups/${systemGroupExternalReference}`, {
          credentials: 'include',
          headers: {
            Accept: 'application/json, text/plain, */*',
          },
        });

        return {
          status: response.status,
          payload: await response.json().catch(async () => ({ raw: await response.text() })),
        };
      }, { catalogExternalReference, systemGroupExternalReference }).catch((error) => {
        errors.push({ stage: 'system_group_api', part_number: row.part_number, error: String(error.message ?? error).slice(0, 180) });
        return null;
      });

      if (!detailPayload || detailPayload.status < 200 || detailPayload.status >= 300) {
        if (detailPayload) {
          errors.push({ stage: 'system_group_api', part_number: row.part_number, status: detailPayload.status });
        }
        continue;
      }

      const details = detailPayload.payload?.responseObject ?? detailPayload.payload ?? {};
      row.system_group_image_urls = payloadImageUrls(details);
      const detailPart = (details.parts ?? []).find((part) => normalizePartNumber(part.partNumber ?? part.catalogPartNumber) === normalizePartNumber(row.part_number));
      if (detailPart) {
        row.detail = detailPart;
        row.price = detailPart.price ?? null;
        row.currency = detailPart.currencyCode ?? detailPart.currency ?? null;
        row.part_restriction = detailPart.partRestrictionMessage ?? detailPart.partRestriction ?? null;
      }
    }

    const exactMatches = mergedRows.filter((row) => normalizePartNumber(row.part_number) === normalized);
    const similarMatches = mergedRows.filter((row) => {
      const rowPartNumber = normalizePartNumber(row.part_number);
      return rowPartNumber !== '' && rowPartNumber !== normalized;
    });

    results.push({
      part_number: partNumber,
      method: 'cdp-find-part-page',
      url,
      status: exactMatches.length > 0
        ? 'exact'
        : similarMatches.length > 0
          ? 'similar'
          : errors.length > 0
            ? errorStatus(errors)
            : 'not_found',
      found: exactMatches.length > 0,
      matches: exactMatches,
      similar_matches: similarMatches,
      related_matches: mergedRows,
      related_part_numbers: mergedRows.map((row) => row.part_number).filter(Boolean),
      same_prefix_related_part_numbers: mergedRows
        .map((row) => row.part_number)
        .filter((value) => value && partNumberPrefix(value) === partNumberPrefix(partNumber)),
      errors,
    });

    if (delayMs > 0) {
      await page.waitForTimeout(delayMs).catch(() => {});
    }
  }

  console.log(JSON.stringify(results, null, 2));
  if (typeof browser.disconnect === 'function') {
    await browser.disconnect();
  }
}

async function browserFindPartSearch({ browserName = 'firefox' } = {}) {
  const partNumbers = [...new Set((await readPartNumbers()).map((value) => value.trim()).filter(Boolean))];
  const delayMs = Number(argValue('delay-ms', 7000));
  const pageWaitMs = Number(argValue('page-wait-ms', 8000));
  const browserDefaults = {
    chrome: { profileDir: defaultProfileDir, browserType: chromium, executablePath: defaultChromePath },
    edge: { profileDir: defaultEdgeProfileDir, browserType: chromium, executablePath: defaultEdgePath },
    opera: { profileDir: defaultOperaProfileDir, browserType: chromium, executablePath: defaultOperaPath },
    firefox: { profileDir: defaultFirefoxProfileDir, browserType: firefox, executablePath: null },
  };
  const browserConfig = browserDefaults[browserName] ?? browserDefaults.firefox;
  const profileDir = argValue('profile-dir', browserConfig.profileDir);
  const executablePath = argValue(`${browserName}-path`, browserConfig.executablePath);

  await fs.mkdir(profileDir, { recursive: true });

  const launchOptions = {
    headless: !hasArg('headed'),
    viewport: { width: 1440, height: 950 },
    locale: 'en-US',
  };

  if (executablePath) {
    launchOptions.executablePath = executablePath;
  }

  const context = await browserConfig.browserType.launchPersistentContext(profileDir, launchOptions);
  const page = context.pages()[0] ?? await context.newPage();
  const results = [];

  for (const partNumber of partNumbers) {
    const normalized = normalizePartNumber(partNumber);
    const url = `https://parts.tesla.com/en-US/find-part?${new URLSearchParams({ searchTerm: partNumber })}`;
    const errors = [];
    const rows = [];

    try {
      const responsePromise = page.waitForResponse(
        (response) => response.url().includes('/api/catalogs/partSearch') && response.url().includes('Term='),
        { timeout: 30000 },
      ).catch((error) => {
        errors.push({ stage: 'network', error: String(error.message ?? error).slice(0, 180) });
        return null;
      });

      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const response = await responsePromise;
      let searchResponse = response;

      if (!searchResponse) {
        const manualSearchResponsePromise = page.waitForResponse(
          (response) => response.url().includes('/api/catalogs/partSearch') && response.url().includes('Term='),
          { timeout: 30000 },
        ).catch((error) => {
          errors.push({ stage: 'manual_search_network', error: String(error.message ?? error).slice(0, 180) });
          return null;
        });

        const searchInput = page.locator('#find-part-search-input, input[type="search"], input[name="tds-search"]').first();
        if (await searchInput.count().catch(() => 0)) {
          await searchInput.click();
          await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
          await page.keyboard.type(partNumber, { delay: 60 });
          await page.waitForTimeout(500);
          await page.keyboard.press('Enter');
          searchResponse = await manualSearchResponsePromise;
        }
      }

      if (searchResponse) {
        try {
          const text = await searchResponse.text();

          if (!searchResponse.ok()) {
            errors.push({ stage: 'network', status: searchResponse.status(), error: text.slice(0, 180) });
          } else {
            const payload = JSON.parse(text);
            for (const row of normalizeResponseRows(payload)) {
              rows.push(normalizePartRow(row, partNumber, 'network'));
            }
          }
        } catch (error) {
          errors.push({ stage: 'network_parse', error: String(error.message ?? error).slice(0, 180) });
        }
      }

      await page.waitForTimeout(pageWaitMs);

      const initialText = await page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
      const blockReason = blockedPageReason({
        url: page.url(),
        title: await page.title().catch(() => ''),
        text: initialText,
      });
      if (blockReason) {
        errors.push({ stage: 'page', error: blockReason });
        throw new Error(blockReason);
      }

      const tableRows = await page.locator('table tbody tr').all();
      for (const row of tableRows) {
        const cells = await row.locator('td').allInnerTexts().catch(() => []);
        if (cells.length < 7) {
          continue;
        }
        const href = await row.locator('td').first().locator('a').first().getAttribute('href').catch(() => null);
        const refs = refsFromPartUrl(href);
        const rowPartNumber = cells[0]?.trim() || null;

        rows.push({
          source: 'page',
          visibility: 'visible',
          part_number: rowPartNumber,
          description: cells[1]?.trim() || null,
          localized_description: cells[2]?.trim() || null,
          model: cells[3]?.trim() || null,
          category: cells[4]?.trim() || null,
          subcategory: cells[5]?.trim() || null,
          group: cells[6]?.trim() || null,
          catalogExternalReference: refs.catalogExternalReference ?? null,
          systemGroupExternalReference: refs.systemGroupExternalReference ?? null,
          url: href ? new URL(href, 'https://parts.tesla.com').toString() : null,
        });
      }

      if (rows.length === 0) {
        const bodyText = await page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
        const fallbackBlockReason = blockedPageReason({
          url: page.url(),
          title: await page.title().catch(() => ''),
          text: bodyText,
        });
        if (fallbackBlockReason) {
          errors.push({ stage: 'page', error: fallbackBlockReason });
        }
        for (const row of extractRowsFromTableText(bodyText)) {
          rows.push(normalizePartRow(row, partNumber, 'page'));
        }
      }
    } catch (error) {
      errors.push({ error: String(error.message ?? error).slice(0, 180) });
    }

    const mergedRows = uniqueMatches(rows);

    for (const row of mergedRows) {
      const catalogExternalReference = row.catalogExternalReference ?? refsFromPartUrl(row.url).catalogExternalReference;
      const systemGroupExternalReference = row.systemGroupExternalReference ?? refsFromPartUrl(row.url).systemGroupExternalReference;

      if (!catalogExternalReference || !systemGroupExternalReference || !row.part_number) {
        continue;
      }

      row.catalogExternalReference = catalogExternalReference;
      row.systemGroupExternalReference = systemGroupExternalReference;
      row.url = row.url ?? `https://parts.tesla.com/en-US/catalogs/${catalogExternalReference}/systemGroups/${systemGroupExternalReference}?${new URLSearchParams({
        partNumber: row.part_number,
        catalogExternalReference,
      })}`;

      const detailPayload = await evaluateWithNavigationRetry(page, async ({ catalogExternalReference, systemGroupExternalReference }) => {
        const response = await fetch(`https://epcapi.tesla.com/api/catalogs/${catalogExternalReference}/systemgroups/${systemGroupExternalReference}`, {
          credentials: 'include',
          headers: {
            Accept: 'application/json, text/plain, */*',
          },
        });

        return {
          status: response.status,
          payload: await response.json().catch(async () => ({ raw: await response.text() })),
        };
      }, { catalogExternalReference, systemGroupExternalReference }).catch((error) => {
        errors.push({ stage: 'system_group_api', part_number: row.part_number, error: String(error.message ?? error).slice(0, 180) });
        return null;
      });

      if (!detailPayload || detailPayload.status < 200 || detailPayload.status >= 300) {
        if (detailPayload) {
          errors.push({ stage: 'system_group_api', part_number: row.part_number, status: detailPayload.status });
        }
        continue;
      }

      const details = detailPayload.payload?.responseObject ?? detailPayload.payload ?? {};
      row.system_group_image_urls = payloadImageUrls(details);
      const detailPart = (details.parts ?? []).find((part) => normalizePartNumber(part.partNumber ?? part.catalogPartNumber) === normalizePartNumber(row.part_number));
      if (detailPart) {
        row.detail = detailPart;
        row.price = detailPart.price ?? null;
        row.currency = detailPart.currencyCode ?? detailPart.currency ?? null;
        row.part_restriction = detailPart.partRestrictionMessage ?? detailPart.partRestriction ?? null;
      }
    }

    const exactMatches = mergedRows.filter((row) => normalizePartNumber(row.part_number) === normalized);
    const similarMatches = mergedRows.filter((row) => {
      const rowPartNumber = normalizePartNumber(row.part_number);
      return rowPartNumber !== '' && rowPartNumber !== normalized;
    });

    results.push({
      part_number: partNumber,
      method: `${browserName}-find-part-page`,
      url,
      status: exactMatches.length > 0
        ? 'exact'
        : similarMatches.length > 0
          ? 'similar'
          : errors.length > 0
            ? errorStatus(errors)
            : 'not_found',
      found: exactMatches.length > 0,
      matches: exactMatches,
      similar_matches: similarMatches,
      related_matches: mergedRows,
      related_part_numbers: mergedRows.map((row) => row.part_number).filter(Boolean),
      same_prefix_related_part_numbers: mergedRows
        .map((row) => row.part_number)
        .filter((value) => value && partNumberPrefix(value) === partNumberPrefix(partNumber)),
      errors,
    });

    if (delayMs > 0) {
      await page.waitForTimeout(delayMs);
    }
  }

  console.log(JSON.stringify(results, null, 2));
  await context.close();
}

async function cdpVinCatalogSnapshot() {
  const endpoint = argValue('cdp', 'http://127.0.0.1:9222');
  const vin = String(argValue('vin', '') ?? '').trim().toUpperCase();
  const maxSystemGroups = Math.max(0, Number(argValue('max-system-groups', 0)));
  const maxParts = Math.max(0, Number(argValue('max-parts', 0)));
  const sleepMs = Math.max(0, Number(argValue('sleep-ms', 1000)));

  if (vin === '') {
    throw new Error('Missing --vin');
  }

  const browser = await chromium.connectOverCDP(endpoint);
  const context = browser.contexts()[0] ?? await browser.newContext();
  const page = context.pages().find((candidate) => candidate.url().includes('parts.tesla.com'))
    ?? context.pages()[0]
    ?? await context.newPage();

  await page.goto('https://parts.tesla.com/en-US/landingpage', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  await page.locator('input[type="text"]').first().fill(vin);
  await page.getByRole('button', { name: 'Search Catalog' }).first().click();
  await page.waitForURL(/catalogExternalReference=/, { timeout: 60000 });
  await page.waitForTimeout(3000);

  const currentUrl = new URL(page.url());
  const catalogExternalReference = currentUrl.searchParams.get('catalogExternalReference');
  if (!catalogExternalReference) {
    throw new Error(`Could not resolve catalogExternalReference for VIN ${vin}`);
  }

  const apiPage = await context.newPage();
  const treePayload = await browserPageJsonRequest(
    apiPage,
    `https://epcapi.tesla.com/api/catalogs/${catalogExternalReference}/categories?VIN=${encodeURIComponent(vin)}`,
  );

  if (treePayload.status < 200 || treePayload.status >= 300) {
    throw new Error(`Tesla categories request failed: ${treePayload.status}`);
  }

  const categories = Array.isArray(treePayload.payload?.responseObject)
    ? treePayload.payload.responseObject
    : Array.isArray(treePayload.payload?.categories)
      ? treePayload.payload.categories
      : Array.isArray(treePayload.payload)
        ? treePayload.payload
        : [];
  const catalog = categories[0]?.catalog ?? { externalReference: catalogExternalReference };
  const systemGroups = [];

  for (const category of categories) {
    for (const subcategory of category.subCategories ?? category.subcategories ?? []) {
      for (const systemGroup of subcategory.systemGroups ?? subcategory.systemgroups ?? []) {
        const externalReference = systemGroup.externalReference ?? '';
        if (externalReference) {
          systemGroups.push({ category, subcategory, systemGroup, externalReference });
        }
      }
    }
  }

  const details = [];
  let partsSeen = 0;

  for (const item of systemGroups) {
    if (maxSystemGroups > 0 && details.length >= maxSystemGroups) {
      break;
    }
    if (maxParts > 0 && partsSeen >= maxParts) {
      break;
    }

    const detailPayload = await browserPageJsonRequest(
      apiPage,
      `https://epcapi.tesla.com/api/catalogs/${catalogExternalReference}/systemgroups/${item.externalReference}?VIN=${encodeURIComponent(vin)}`,
    );

    if (detailPayload.status >= 200 && detailPayload.status < 300) {
      const responseObject = detailPayload.payload?.responseObject ?? detailPayload.payload;
      const partCount = Array.isArray(responseObject?.parts) ? responseObject.parts.length : 0;
      details.push({
        system_group_external_reference: item.externalReference,
        status: detailPayload.status,
        details: responseObject,
      });
      partsSeen += partCount;
    } else {
      details.push({
        system_group_external_reference: item.externalReference,
        status: detailPayload.status,
        error: detailPayload.payload,
        details: null,
      });
    }

    if (sleepMs > 0) {
      await page.waitForTimeout(sleepMs);
    }
  }

  console.log(JSON.stringify({
    vin,
    catalogs: [
      {
        catalog: {
          ...catalog,
          externalReference: catalog.externalReference ?? catalogExternalReference,
        },
        tree: {
          catalog,
          categories,
        },
        categories,
        system_group_details: details,
      },
    ],
  }, null, 2));

  await apiPage.close();

  if (typeof browser.disconnect === 'function') {
    await browser.disconnect();
  }
}

const mode = process.argv[2] ?? 'search';

if (mode === 'login') {
  await login();
} else if (mode === 'search') {
  await search();
} else if (mode === 'catalog-snapshot') {
  await catalogSnapshot();
} else if (mode === 'cdp-find-part-search') {
  await cdpFindPartSearch();
  process.exit(0);
} else if (mode === 'firefox-login') {
  await firefoxLogin();
} else if (mode === 'firefox-find-part-search') {
  await browserFindPartSearch({ browserName: 'firefox' });
  process.exit(0);
} else if (mode === 'chrome-find-part-search') {
  await browserFindPartSearch({ browserName: 'chrome' });
  process.exit(0);
} else if (mode === 'edge-find-part-search') {
  await browserFindPartSearch({ browserName: 'edge' });
  process.exit(0);
} else if (mode === 'opera-find-part-search') {
  await browserFindPartSearch({ browserName: 'opera' });
  process.exit(0);
} else if (mode === 'cdp-vin-catalog-snapshot') {
  await cdpVinCatalogSnapshot();
  process.exit(0);
} else {
  console.error(`Unknown mode: ${mode}`);
  process.exit(1);
}
