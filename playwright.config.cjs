const { defineConfig, devices } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const root = __dirname;
const parent = path.join(root, '.codex-tmp', 'cart-e2e');
fs.mkdirSync(parent, { recursive: true });
if (!process.env.NC_CART_E2E_RUNTIME) {
  process.env.NC_CART_E2E_RUNTIME = fs.mkdtempSync(path.join(parent, 'run-'));
  fs.writeFileSync(path.join(parent, 'last-run.json'), JSON.stringify({ runtime: process.env.NC_CART_E2E_RUNTIME }));
}
const runtime = path.resolve(process.env.NC_CART_E2E_RUNTIME);
if (!runtime.startsWith(parent + path.sep)) throw new Error('Isolated test directory required.');
for (const directory of ['bootstrap', 'storage/logs', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions']) {
  fs.mkdirSync(path.join(runtime, directory), { recursive: true });
}
const php = process.env.LOCAL_PHP || (process.platform === 'win32'
  ? 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe' : 'php');
if (/["`$\r\n&|;<>]/.test(php)) throw new Error('Unsafe PHP executable path.');
const baseURL = 'http://127.0.0.1:4327';
// Laravel 12 treats drive-letter cache overrides as relative paths on Windows.
const cachePath = name => path.relative(root, path.join(runtime, 'bootstrap', name)).split(path.sep).join('/');

module.exports = defineConfig({
  testDir: './tests/e2e',
  testMatch: '**/*.spec.cjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,
  timeout: 30_000,
  expect: { timeout: 5_000 },
  outputDir: path.join(runtime, 'artifacts'),
  reporter: [['list'], ['json', { outputFile: path.join(runtime, 'results.json') }]],
  use: { baseURL, timezoneId: 'Europe/Kyiv', serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'], browserName: 'chromium' } },
  ],
  webServer: {
    command: `"${php}" -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:4327 -t public tests/e2e/router.php`,
    cwd: root,
    url: `${baseURL}/__cart_e2e/health`,
    reuseExistingServer: false,
    timeout: 30_000,
    env: {
      NC_CART_E2E: '1', NC_CART_E2E_RUNTIME: runtime,
      APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: baseURL,
      APP_MAINTENANCE_DRIVER: 'file',
      APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      ASSET_URL: baseURL, CACHE_STORE: 'array', SESSION_DRIVER: 'array',
      QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
      DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '',
      SKLAD_STOREFRONT_URL: 'http://127.0.0.1:9/api/storefront/v1',
      SKLAD_STOREFRONT_TOKEN: 'synthetic-cart-fixture', TELEGRAM_BOT_TOKEN: '', TELEGRAM_CHAT_ID: '',
      LARAVEL_STORAGE_PATH: path.join(runtime, 'storage'),
      VIEW_COMPILED_PATH: path.join(runtime, 'storage/framework/views'),
      APP_CONFIG_CACHE: cachePath('config.php'),
      APP_SERVICES_CACHE: cachePath('services.php'),
      APP_PACKAGES_CACHE: cachePath('packages.php'),
      APP_ROUTES_CACHE: cachePath('routes.php'),
      APP_EVENTS_CACHE: cachePath('events.php'),
    },
  },
});
