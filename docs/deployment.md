# Production Deployment

Production domain:

```text
https://sklad.nikolacars.kiev.ua
```

Keep local development on the local `.env`. Do not replace the local `.env` with production values.

## Environment

On the live server, create `.env` from `.env.production.example` and fill in:

- `APP_KEY`
- database credentials
- mail settings, if real email delivery is needed
- `PROM_FEED_TOKEN`, if Prom feed access should be protected

Required live URL settings:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sklad.nikolacars.kiev.ua
ASSET_URL=https://sklad.nikolacars.kiev.ua
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
TRUSTED_PROXIES=*
```

Use `TRUSTED_PROXIES=*` only when the app is behind a trusted proxy such as Nginx or Cloudflare that passes `X-Forwarded-*` headers. If the app is directly exposed, leave `TRUSTED_PROXIES` empty.

## Release Commands

Run these on the live server after uploading code and setting `.env`:

```bash
find bootstrap/cache -type f ! -name ".gitignore" -delete
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not upload local generated `bootstrap/cache/*.php` files. They can contain
local dev package discovery, including dev-only providers that are not installed
on production.

## Release Payload

Keep the live code payload source-only. Do not upload local development state or
one-off import material:

- `.env`, `.env.live-sync`, `.codex-tmp`, `outputs`, `node_modules`, and
  `vendor`;
- root import spreadsheets/JSON dumps such as `tesla_*_parts_by_group.json`,
  `tesla_*_categories.json`, `teslapartsukraine_catalog*`, `Продаж.xls`, and
  the local `NC` folder;
- generated Laravel cache files in `bootstrap/cache`, except the directory's
  `.gitignore`;
- database backups/dumps unless they are being intentionally transferred as the
  production database import.

The FTP deploy helper stages only the application runtime files. From
`scripts`, it includes `tesla_official_browser_search.mjs` because several
artisan commands call it directly; local deployment, sync, lookup, and repair
helpers should stay on the developer machine.

The live `public/index.php` entry point binds Laravel's public path to the
hosting webroot. Keep that binding in place: Vite reads `build/manifest.json`
through `public_path()`, while the application source lives in the separate
private `sklad_app` directory.

Database data and public media are separate release concerns. Import the MySQL
database intentionally. Normal code deploys must not touch `storage/app/public`.
Live public media deletion, reset, replacement, mirroring, or purge is forbidden.
Recovery may only add missing files or restore the whole directory from a
verified hosting backup/snapshot.

## Hosting Inode Audit

When the hosting file-count limit is close, audit public storage without
deleting anything:

```bash
php artisan storage:purge-unreferenced-public-files
```

For a narrower audit, pass one or more public storage prefixes:

```bash
php artisan storage:purge-unreferenced-public-files --prefix=competitor-catalog/stock-tesla
```

Never run this command with `--delete` on production. The command is blocked in
`APP_ENV=production`, and manual file deletion from live public storage is also
forbidden.

After deleting many files, clear and rebuild cached Laravel files:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If dependencies are installed on the server:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## FTP Helper Notes

`scripts/deploy-live-files.ps1` is code-only for live media: it must never
delete, reset, replace, mirror, or deploy `storage/app/public`.

The hosting account exposes `/bin/php` as `cgi-fcgi`; artisan commands must use
a CLI PHP binary. The deploy helper detects `/opt/alt/php83/usr/bin/php` and
uses it for live release commands.

## Local Development

Local work should continue with local values, for example:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
TRUSTED_PROXIES=
PUBLIC_STORAGE_FALLBACK_URL=https://sklad.nikolacars.kiev.ua/storage
```

`PUBLIC_STORAGE_FALLBACK_URL` is for development convenience: if a public
storage image is absent locally, admin screens can display it from live storage
without copying the full media archive. Existing local files still use the local
`APP_URL/storage` URL.
