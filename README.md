# NikolaCars Parts Warehouse

Laravel admin application for NikolaCars warehouse, donor cars, Tesla parts
catalogs, purchases, customer orders, STO work orders, cashbooks, and the mobile
donor-parts flow.

## Stack

- PHP 8.3+
- Laravel 13
- Blade admin UI
- Vite 8 and Tailwind CSS 4
- MySQL in local/live environments
- PHPUnit 12
- Capacitor Android wrapper for the mobile flow

## Local Run

Use Laragon or another PHP/MySQL environment. Do not open `public/index.php`
directly in the browser.

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
start-local.bat
```

Then open `http://127.0.0.1:8000/login`.

Default seeded login:

- email: `admin@sklad.test`
- password: `password`

## Useful Commands

```bash
composer run dev
npm run build
composer test
vendor/bin/pint
```

Catalog and storage maintenance commands are documented in `AGENTS.md`.

## Production

Production deployment notes live in `docs/deployment.md`.

Keep local secrets and generated artifacts out of releases:

- do not upload `.env`, `.env.live-sync`, `.codex-tmp`, `outputs`, `node_modules`,
  `vendor`, local import spreadsheets, local JSON dumps, or Laravel cache files;
- upload/import the production database separately from code;
- upload `storage/app/public` only when the live public media needs to be
  refreshed.
