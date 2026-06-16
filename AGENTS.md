# AGENTS.md

## Project Snapshot

This is a Laravel warehouse and parts-catalog application for NikolaCars/Tesla parts.
It is primarily a Blade admin app for local Laragon development, with production
deployment notes for `https://sklad.nikolacars.kiev.ua`.

Main business areas:

- warehouse management: warehouses, locations, stock items, stock movements, reservations;
- donor cars and generated donor products;
- Tesla and competitor parts catalogs, imports, search, deduplication, localization;
- purchases, cashbook, Valera cashbook, exchange rates, monthly reports;
- STO work orders and employees;
- mobile donor-parts flow wrapped by Capacitor for Android.

## Stack

- PHP `^8.3`
- Laravel `^13.0`
- Blade views in `resources/views`
- Vite `^8`, Tailwind CSS `^4`
- MySQL for the normal local/live app; SQLite file exists for quick local testing
- PHPUnit `^12`
- Capacitor Android wrapper for the mobile flow

## Common Commands

Install/setup:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
```

Run locally:

```bash
start-local.bat
```

Or use Composer's multi-process dev command:

```bash
composer run dev
```

Frontend:

```bash
npm run dev
npm run build
```

Tests:

```bash
composer test
php artisan test
php artisan test --filter=PartCatalogSearchServiceTest
```

`composer test` runs the source encoding audit before PHPUnit. Live file deploys
also run the same audit before uploading anything, so mojibake or UTF-8 BOM in
source files should block the release before it reaches production.

Catalog maintenance:

```bash
php artisan storage:purge-unreferenced-public-files
php artisan parts:rebuild-catalog-source-stats
php artisan parts:rebuild-catalog-source-stats driveparts
php artisan parts:sync-nikolacars-tesla-category-tree --resolve-items
php artisan parts:resolve-nikolacars-tesla-categories
php artisan parts:diagnose-nikolacars-inventory
php artisan parts:diagnose-nikolacars-inventory --focus=category
php artisan parts:diagnose-nikolacars-inventory --focus=category-localization
php artisan parts:diagnose-nikolacars-inventory --focus=sellability
```

Formatting:

```bash
vendor/bin/pint
```

Mobile:

```bash
npm run mobile:sync
npm run mobile:open
npm run mobile:build:android
```

## Important Files And Directories

- `routes/web.php` - all public/admin routes. Most admin routes sit under `/admin`
  and use permission middleware.
- `app/Http/Controllers/Admin` - admin CRUD and workflow controllers.
- `app/Http/Requests` - form validation.
- `app/Models` - Eloquent models.
- `app/Services` - domain-heavy logic, imports, catalog search/filtering, feeds,
  stock and cashbook services. Prefer placing business logic here instead of in
  Blade templates.
- `app/Console/Commands` - maintenance/import/backfill commands.
- `resources/views/layouts/admin.blade.php` - main admin layout.
- `resources/views/layouts/mobile.blade.php` - mobile layout.
- `resources/views/admin` - admin screens grouped by feature.
- `resources/css/app.css` - Tailwind entry point and theme source config.
- `resources/js/app.js` - currently minimal.
- `config/catalog_sources.php` - configured catalog sources. `routes/web.php`
  builds many catalog routes from this config.
- `database/migrations` - schema history. There are many data-fix migrations;
  inspect nearby migrations before changing related tables.
- `database/seeders/DatabaseSeeder.php` - seed data including the local admin user.
- `tests/Feature` and `tests/Unit` - existing test coverage by domain.
- `docs/deployment.md` - production deployment notes.
- `docs/mobile-app.md` - Capacitor Android workflow.
- `docs/warehouse-spec.md` - original warehouse specification. Some text appears
  mojibake in this checkout; do not mass-rewrite encoding unless that is the task.
- `scripts` - one-off import, sync, image, and catalog maintenance scripts.
  Do not include the whole directory in production code archives. The live FTP
  deploy helper should stage only runtime scripts that the app calls directly,
  currently `scripts/tesla_official_browser_search.mjs`; local deploy/sync and
  one-off repair/import helpers should stay local.

## Architecture Notes

- Keep controllers thin where practical; move reusable workflow logic into
  `app/Services`.
- Use Form Request classes for validation when adding admin forms.
- Use route names and Laravel helpers in Blade instead of hard-coded URLs.
- Permission checks are usually route middleware strings such as
  `permission:products.manage`.
- Customer orders use the separate `customer_orders.manage` permission. Warehouse
  workers should have it without receiving `sto_work_orders.manage`, so customer
  order access does not also open STO work orders.
- The `warehouse_worker` role should see the full Competitors catalog menu,
  including all configured competitor source permissions, plus Tesla.com via
  `tesla_catalog.view`.
- Competitor parsing controls and refresh endpoints use
  `competitor_refresh.manage`; do not grant this to warehouse workers when they
  only need to view competitor catalogs.
- Admin activity logging is applied to the admin route group through `admin.log`,
  but read-only admin requests (`GET`/`HEAD`/`OPTIONS`) are intentionally not
  logged so ordinary page views do not fill the journal with "Просмотр" rows.
  Local-to-live database sync is retired; do not reintroduce pending flags,
  hidden sync runners, or scripts that import the local database into live.
- Catalog pages are multi-source. Prefer extending `config/catalog_sources.php`
  and the shared `PartCatalogController`/service path before duplicating screens.
- The old all-source `/admin/tesla-catalog` common catalog is retired and should
  stay unregistered. Competitor refreshes update their own catalog source only;
  do not reintroduce competitor-to-common Tesla merge commands or services.
- Competitor catalog data lives in `part_catalog_items` and source metadata in
  `raw_attributes`; do not add competitor mirror columns back to `products`
  unless donor/inventory products need first-class competitor state again.
- Stock Tesla import no longer uses `https://stock-tesla.com/feeds/prom.xml`.
  It discovers products from site category listings, skips direct listing crawls
  for `/category/10/` and `/category/11/`, and builds the product category path
  from each product page breadcrumb.
- DriveParts placeholder product images must not be duplicated under per-part
  image folders. Normalize known placeholder references to the shared
  `driveparts/placeholder.svg` asset and keep real product photos separate.
- TeslaPartsUkraine product refresh uses the seven full model listing URLs in
  `TeslaPartsUkraineCatalogImporter::refreshModelListings()`. For new products,
  open the product page and save full details/photos; for existing products,
  update only the price from the listing.
- DriveParts product URLs have Ukrainian pages by default and Russian pages under
  `/ru/...`. New DriveParts products should fetch and store `name_ru` from the
  Russian product page. For one-off backfills of missing Russian names, use
  `php artisan parts:refresh-driveparts-translations --missing-ru-only`; some
  DriveParts Russian pages expose only the part number and cannot produce a
  useful `name_ru`.
- Catalog item names have multilingual/localized/manual-lock behavior. Search
  existing services and tests before editing name rebuilding or marker logic.
  RU/UA auto-fill is a one-time creation-time operation for new Tesla official,
  NikolaCars, and donor catalog rows; do not add commands or jobs that
  continuously improve existing localized names. Competitor localized names must
  come only from the competitor's own site. Trusted auto-fill sources for Tesla
  official/internal parts are TCARS, TeslaPartsUkraine, Erazborka, DK-Parts,
  Tesla West Parts, DriveParts, Stock Tesla, TeslaCompany, and TSK. Do not use
  TeslaHelp, TeslaShop, Terebra, or TopRazborka as localized name sources. Try
  exact full article matches first, then seven-character base matches only for
  Tesla-format articles such as `1234567-00-A`.
- Manual RU/UA name locks from donor-generated Tesla rows, NikolaCars, or
  Tesla.com propagate only to those same internal sources with the exact
  normalized part number. Do not propagate manual names by base number or
  seven-digit prefix, and do not update competitor catalog rows.
- `/admin/zapchasti` represents sellable NikolaCars service inventory:
  donor, warehouse, and purchase parts. NikolaCars donor import rows must be
  included only after the donor part is checked with a damage status of
  "Без повреждений", "Легкие повреждения", or "Сильные повреждения"; unchecked,
  unknown, or broken donor rows stay out of this catalog. Treat actual
  `products` as the business source of truth here; `part_catalog_items` may be
  used only as a technical display/search projection. NikolaCars catalog rows
  linked through `raw_attributes.product_id` must calculate visible/exported
  stock from the linked `products.stock_items` when stock items exist, because
  `raw_attributes.stock_quantity` can lag behind the real product stock.
  NikolaCars catalog rows
  with a donor VIN must be linked back to that donor as `products`, so they
  appear in the donor's "all parts" and checked-parts tabs.
  Customer orders and NikolaCars issued sales should link to `products.id`
  first (`customer_order_items.product_id`, `part_sales.product_id`). Keep
  `part_catalog_item_id` only as a legacy/projection bridge while old
  `/admin/zapchasti` screens and reports still read NikolaCars catalog rows.
  Auto-generated products linked to `tesla_official` are a donor inspection
  checklist before confirmation, not sellable stock. If a product was generated
  from the official Tesla VIN catalog, keep `products.is_auto_generated=true`
  for its lifetime, even when a checked part is mirrored into a NikolaCars
  catalog row for sale. When those official-generated donor products are
  created, missing RU/UA names should be auto-filled on the linked
  `tesla_official` catalog row from exact local catalog matches first, then
  from Google Translate only when configured. On donor car product tables, keep the `tesla.com` price
  visible for those official-generated products by resolving the original
  `tesla_official` catalog row, even when `source_part_catalog_item_id` now
  points at a `nikolacars://donor-product/{id}` mirror. Do not copy the
  `tesla.com` price into `products.selling_price`; sale price for generated
  official donor products starts at `0` USD and is entered manually. When
  repairing old generated products, zero the sale price only when it matches the
  resolved `tesla.com` price; if it differs, treat it as a manual sale price and
  preserve it. Legacy generated products with no resolved `tesla.com` price,
  positive sale price, and no `updated_by` user stamp can be treated as old
  copied Tesla.com prices: move that value into the official catalog price and
  reset the sale price to `0` USD. If an
  unchecked/generated part's NikolaCars mirror is removed, restore its
  `source_part_catalog_item_id` back to the official `tesla_official` row when
  possible instead of leaving a stale NikolaCars link.
  If the part is absent from the donor or unusable, mark it with the broken
  damage status so it stays out of checked/sellable NikolaCars projections.
  When a donor part damage status changes from unknown/blank to any concrete
  status, missing or non-Cyrillic RU/UA names are auto-filled on the resulting
  NikolaCars donor mirror from exact local catalog matches first, then from
  Google Translate only when `GOOGLE_TRANSLATE_API_KEY` is configured. If only
  one localized name is found locally, use that localized name as the Google
  Translate source for the missing language. If no localized names are found,
  use Google Translate from the English/source name for both `name_ru` and
  `name_ua`; if that direct translation returns unusable Latin/empty text for
  one locale but the other localized name is available, retry the missing locale
  from that localized name. Pass an explicit Google Translate source language
  (`en`, `ru`, or `uk`) when the source text language is known, because technical
  part names are often misdetected. When a `products` row is re-mirrored into a
  NikolaCars catalog row, preserve an existing Cyrillic `name_ru`/`name_ua` if
  the new candidate is blank or non-Cyrillic; Tesla.com technical English names
  must not overwrite already localized labels.
  Historical NikolaCars catalog projections may also exist for linked
  `products` that are `storage_status=sold` or marked with the broken damage
  status, but active `/admin/zapchasti` lists, customer-order search, inventory
  totals, and Prom export must exclude those sold/broken rows.
- In `/admin/zapchasti`, VIN and category are separate concepts. VIN lives only
  in `raw_attributes.donor_vin`; do not infer or restore VIN from
  `compatibility_text`, `category_display`, `category_path`, the part number, or
  the item name. Category display belongs in `category_display`/`category_path`
  and may be mirrored to `compatibility_text` only as display/search text.
- Old NikolaCars rows without a donor VIN still become real manual stock
  `products` with `is_auto_generated=false`, `storage_status=in_stock`, and a
  link back through `source_part_catalog_item_id`. If the original VIN is later
  recovered from live data, write it to `raw_attributes.donor_vin` and rerun the
  NikolaCars catalog-to-product sync to attach the product to the donor.
- Products mirrored from NikolaCars nomenclature rows, including one-time CRM
  transfer donor/inventory parts, are real `products`, not user-facing
  "automatically generated from parts catalog" items. Even if legacy
  `is_auto_generated=true` remains on those rows, do not show the auto-generated
  catalog badge for `nikolacars://donor-product/{id}` or
  `nikolacars://inventory-product/{id}` product projections.
- `/admin/deleted-parts` is a restore-capable trash for archived product and
  NikolaCars catalog rows. Restore from the saved snapshots with
  `DeletedPartRestoreService`, keep restored attributes limited to real table
  columns, and surface uniqueness/source URL conflicts as validation errors
  instead of silently overwriting active rows.
- For NikolaCars nomenclature imports, keep `products.name` as the full source
  nomenclature name from `raw_attributes.source_row.name`. Store the cleaned
  Ukrainian display name in the linked `part_catalog_items.name_ua` for
  `/admin/zapchasti`; build it from `products.name` by removing Tesla/Тесла,
  model markers, years, VINs, and article suffixes. This rule applies only to
  NikolaCars nomenclature parts, not competitor or Tesla official catalog rows.
- `/admin/zapchasti` may visually group NikolaCars rows only when their full
  normalized article number is identical. Do not group rows by the first seven
  digits of the article number in the list or item count; sorting by
  article/category/VIN is fine, and the business row remains the individual
  `PartCatalogItem`/linked `Product`.
- Manual `/admin/zapchasti` additions use `Название запчасти УКР` as the
  Ukrainian catalog/product name and may store an optional `Название запчасти РУ`.
  The first source option is `Закупка`; those manually purchased products use the
  `NC-PURCHASE-*` SKU prefix, keep `raw_attributes.source_type=purchase`, and can
  store `purchase_price_usd`/`products.purchase_price` for future resale.
- `/admin/zapchasti` keeps NikolaCars sale prices editable/displayed in USD, but
  also shows the UAH price underneath using the effective USD rate from
  `/admin/exchange-rates`, rounded to the nearest 10 UAH. Customer orders created
  from the NikolaCars cart are stored in UAH, with USD price hints saved only as
  reference values on order items.
- `/admin/exchange-rates` is an NBU-only USD rate source for app calculations and
  display. Ignore or hide old `source=manual` rows instead of using them as a
  fallback for USD conversion.
- NikolaCars customer order items keep `/admin/zapchasti` rows reserved only
  while the order is still in an active fulfillment/payment status. Orders that
  are issued/completed, or legacy Nova Poshta orders represented as
  `status=paid`, must create issued sales, write down stock, and disappear from
  the reservation projection. Cancellation or deleting an unissued order item
  also releases the corresponding reservation.
- Customer orders must be created only through the authenticated
  `/admin/customer-orders` admin workflow. Do not create verification, seed,
  script, or direct database rows in `customer_orders`; real order numbers must
  keep the `ORD-YYYYMMDD-0001` format and have the normal order history/items.
  New orders created from the NikolaCars cart do not have a comment field; ignore
  any submitted `note`/comment value in the normal order creation request.
- Customer orders with no known client phone, first name, or last name should
  stay blank on the order itself but link to the parts counterparty
  `Неизвестный Анонимус` with id `1` and phone `+380000000000`.
  Selecting that anonymous counterparty in the NikolaCars cart should default the
  delivery method to pickup (`Самовывоз`), lock phone/name fields from editing,
  and submit blank real client details.
- Customer order payment confirmation may be split across multiple unique payment
  types. The summed payment converted to UAH must be at least the order total, and
  the modal should keep showing the remaining amount even while an amount field is
  filled. That remaining hint is calculated for the row it is displayed under, so
  amounts typed in that same row are excluded from the hint until submission.
  Payment coverage is checked against visible rounded UAH totals, so fractional
  UAH from USD conversion does not reject a sum that matches the displayed amount.
  When the modal shows a concrete remaining USD amount from order item USD hints,
  use the effective remaining UAH/USD rate for coverage so paying that displayed
  USD amount can complete the order even if today's NBU rate differs.
  Payment is a parallel financial process, not an order workflow status:
  confirming full payment or full prepayment should fill the paid sums and
  `payment_confirmed_at`, keep the current `status` unchanged, and display the
  full payment in the separate payment field with a light-green "Оплачено" badge
  instead of showing "Оплачено" under the order status. Keep `status=paid` only
  for legacy rows that already have it.
- Customer order item/order UAH and USD hint calculations use the NBU USD rate
  for the order creation date. Do not recalculate an existing order's displayed
  sum with today's exchange rate.
  Editing an order item UAH price updates only that customer order item. Rebuild
  the item's USD hint from the order-date rate by rounding the per-unit USD value
  up to the next whole dollar, and do not write that price back to
  `products.selling_price` or `part_catalog_items.price_amount`.
- Customer orders with full prepayment or full payment cannot be cancelled from
  `/admin/customer-orders`; keep the payment and issued-sale state intact
  instead of using cancellation as a delete/rollback path.
- Paid pickup customer orders can be marked as completed/issued to the client
  from `/admin/customer-orders`; completed orders appear in a separate table on
  the active orders page and release their `/admin/zapchasti` reservations after
  the issued sale writes down stock.
- New paid Nova Poshta customer orders stay in their workflow status, usually
  "Отправлен"; payment alone must not issue the order, move it to `paid`, or
  create issued-sale rows. Legacy `status=paid` Nova Poshta rows may still render
  as issued history, but do not create new rows that way.
- Nova Poshta customer orders with `status=shipped` display in their own block
  above the issued orders block on the active `/admin/customer-orders` page.
- Customer orders with `delivery_method=sto` represent an internal handoff to the
  company's STO, not a direct client payment flow. Link them to the system
  counterparty `СТО NikolaCars`, not the anonymous counterparty. Do not offer
  prepayment or payment confirmation controls for them, do not include their paid
  fields in the `/admin/customer-orders` cash summary, and allow assembled STO
  orders to be marked issued/completed without payment.
- Issued customer orders create NikolaCars `PartSale` rows with
  `source_file=customer-order-issued`, link them to `products.id`, subtract the
  ordered quantity from product stock first and mirror the legacy
  `/admin/zapchasti` `raw_attributes.stock_quantity` when a NikolaCars projection
  exists. Use the full product article/source article for the sale article. When
  a NikolaCars projection has no real `stock_items` and carries
  `raw_attributes.source_row.stock` from the original import, customer-order
  issue/cancel stock math must not restore or sell from a quantity higher than
  that source row stock.
  Refresh the reservation projection after syncing issued sales so issued orders
  no longer appear as reserved. When the remaining stock reaches zero, mark the
  linked `products` row sold/inactive. With the default
  "hide sold" filter enabled, customer-order-issued rows must be hidden from
  `/admin/zapchasti`; show them as zero-stock history only when sold rows are
  explicitly included. Use
  `php artisan customer-orders:sync-issued-sales` to backfill sales/stock for
  already issued orders.
- Cancelled customer orders are read-only for order composition and fulfillment:
  do not allow changing the delivery method, adding items, deleting items, or
  editing item prices after `status=cancelled`.
- Customer order `note` is administrative metadata and may be edited from the
  order show page in any status, including cancelled, completed, paid, and
  shipped orders. Record note changes in `customer_order_history_events` as
  `note_updated`.
- Cancelled customer orders may be recreated from the show page. Recreate must
  create a new authenticated admin order with a fresh `ORD-YYYYMMDD-0001`
  number, copy the client/delivery/note and linked NikolaCars parts, verify that
  each part is still sellable and has enough unreserved stock, recalculate UAH
  item prices using today's NBU USD rate and the USD price hints/current source
  prices, and put the new order's items back into the normal customer-order
  reservation projection.
- `/admin/zapchasti` rows can be manually marked as sold before June 1, 2026 for
  cleanup. This keeps the `PartCatalogItem` as history, writes
  `raw_attributes.manual_sold_at=2026-05-31`, zeroes `stock_quantity`, marks the
  linked `products` row as `storage_status=sold`/inactive, creates a NikolaCars
  `PartSale` dated `2026-05-31`, and excludes the row from active
  `/admin/zapchasti` lists, customer-order search, inventory totals, and Prom
  export. Only these manual cleanup sales show an "Отменить" button in
  `/admin/nikolacars-sales`; canceling there deletes that `PartSale` and returns
  the item to active `/admin/zapchasti`.
- When syncing NikolaCars catalog rows back into `products`, reuse an existing
  donor product with the same donor and exact `external_sku` before creating a
  new `NC-*` product. Mirror rows such as `nikolacars://donor-product/{id}` must
  update that source product and must not create a second donor product.
- When a `products` row is mirrored into `/admin/zapchasti`, its
  `source_part_catalog_item_id` must point to the product-owned NikolaCars row
  such as `nikolacars://donor-product/{product_id}` or
  `nikolacars://inventory-product/{product_id}`. Tesla official or competitor
  catalog rows may seed names/categories before mirroring, but they must not
  remain the product's primary `/admin/zapchasti` link after the NikolaCars row
  exists.
- Auto-generated donor product SKU codes use the `DON{donor_car_id}-0001`
  format (for example `DON28-0259`). Manually added donor products use the
  matching `RON{donor_car_id}-0001` format so they are visibly separate from
  Tesla.com-generated checklist parts. Do not generate new donor SKUs with the
  old `DA{donor_car_id}-0001` or `D{donor_car_id}-0001` prefixes.
- Some old NikolaCars donor groups do not have a real 17-character VIN; their
  donor identity is a stored donor-car pseudo-VIN such as
  `TESLA МS 2015 - 2021 залишки` from the import category path. Treat exact
  case-insensitive matches to existing `donor_cars.vin` values as donor VINs so
  those products link back to the donor page.
- Ignore old pseudo-VIN/category-path donor identity assumptions for NikolaCars:
  category text is not a VIN. Restore missing VIN only from trusted historical
  source data, such as the live row before it was overwritten, and store it in
  `raw_attributes.donor_vin`.
- `/admin/zapchasti` category display is resolved from Tesla.com catalog by part
  number through `NikolaCarsOfficialPartMatcher`: only articles shaped like
  `1034344-20-B` are eligible, exact full `tesla_official.part_number` match is
  tried first, then seven-digit fallback. Copy the matched Tesla.com category
  into `category_display`/`category_path`. If the article is
  invalid or no Tesla.com category is found, store `Не определено`.
- Tesla.com category codes such as `16` or `1601` stay in
  `part_catalog_categories.code`; strip them from user-facing
  `/admin/zapchasti` category labels.
- Use `NikolaCarsOfficialPartEnrichmentService` for read-only Tesla.com data
  packages such as official item, match type, compatibility, schemes, and photos.
- `/admin/zapchasti` owns its own NikolaCars parts, but its Tesla model/category
  navigation is mirrored from `tesla_official` into `part_catalog_categories`
  with `source=nikolacars` and `source_url=nikolacars://tesla-category/{id}`.
  Sync the tree, RU/UA category names, and item attachments with
  `php artisan parts:sync-nikolacars-tesla-category-tree --resolve-items`; the
  `parts:resolve-nikolacars-tesla-categories` command also syncs the tree before
  attaching NikolaCars items. Keep future NikolaCars-only top-level categories,
  such as purchases, as their own `source=nikolacars` root branches.
- For Tesla.com category rows, treat `name` as a legacy/canonical source label,
  effectively the same business value as the official English `name_en`. Do not
  use `name` as a separate localization source. Russian and Ukrainian category
  labels belong in `name_ru`/`name_ua` and are maintained manually or from
  one-time trusted translation backfills; NikolaCars mirrored categories should
  carry those same localized fields.
- Tesla official catalog localized names are auto-synced by
  `TeslaOfficialLocalizedNameSyncService`: match by base part number before the
  first hyphen, fill/update only explicit competitor `name_ru`/`name_ua` values
  in this priority order: TCARS, TeslaPartsUkraine, Erazborka, DK-Parts,
  Tesla West Parts, Terebra, DriveParts, Stock Tesla, TeslaCompany, TSK. Do not
  use the generic competitor `name` field as a language fallback. Manual locks
  on Tesla official names always prevent automatic updates.
- For manual Tesla official login and long Find Part parsing, follow
  `docs/tesla-official-parsing.md`. Do not use the Playwright `firefox-login`
  helper first: it can trigger Tesla CAPTCHA. Open the project Firefox profile
  with the installed browser instead, let the user log in, then run the checker:
  `Start-Process -FilePath 'C:\Program Files\Mozilla Firefox\firefox.exe' -ArgumentList @('-no-remote','-profile','C:\Users\skrud\OneDrive\Projects\sklad-zapchastey\storage\app\tesla-official-firefox-profile','https://parts.tesla.com/en-US/find-part')`.
  After the user closes Firefox, copy only session/storage files into
  `storage/app/tesla-official-firefox-playwright-profile` so bundled Playwright
  Firefox does not reject the profile as a newer Firefox version, then verify one
  part number and run
  `parts:enrich-tesla-official-cdp-find-part --browser=firefox --profile-dir=storage/app/tesla-official-firefox-playwright-profile`.
  For long runs, start `scripts/start-tesla-official-find-part-background.ps1`
  instead of manual foreground batches; it writes
  `storage/logs/tesla-official-find-part-loop.log`, and the admin Tesla.com log
  page shows `Stopped with error` when the worker writes
  `BACKGROUND STOPPED WITH ERROR`.
- Stock movement history is append-only from the app's point of view. Use
  corrective operations rather than deleting or rewriting movement history.
- STO employees may be linked to admin login accounts through
  `sto_employees.user_id`. Keep that one-to-one link when adding employee-facing
  permissions or filtering work by the logged-in employee.
- Donor cars automatically move from `in_transit` to the legacy `at_sto` status
  when purchase date, STO arrival date, purchase cost with fees, USA delivery,
  Klaipeda-Ukraine delivery, and customs clearance are all filled. Do not
  override `dismantling` or `dismantled` statuses with this automation. Donor
  status is not manually editable from admin forms or inline list controls.
- Reserved quantity is separate from physical stock quantity.
- Every stock item must belong to a warehouse location.
- Many models use user stamps through `created_by`/`updated_by`; preserve that
  pattern where present.

## Data And Safety

- Use UTF-8 everywhere. Project files should stay UTF-8, MySQL should use
  `utf8mb4`, and importer/form input should be normalized before it is saved.
- When accepting text from CSV/XLS/XLSX/HTML/external feeds, run it through
  `App\Support\TextEncodingNormalizer` or an existing domain wrapper that uses
  it. Do not write CP1251/Windows-1252 bytes or known mojibake directly to the
  database.
- Run `composer encoding:audit` after encoding-related changes or when touching
  importer/parser code. Use `php artisan encoding:audit --fail-on-issues` once
  the current legacy mojibake findings have been cleaned up.
- For any reported mojibake/encoding issue in source files or Blade UI text,
  first run the existing source audit on the relevant path, for example
  `php artisan encoding:audit --path=resources/views/admin/donor_cars`, then fix
  only the reported source/data scope and rerun the same audit path afterward.
  Use `encoding:audit-db --fix` only for database text repairs, not source files.
- Do not commit real secrets from `.env`, `.env.live-sync`, dumps, or backup files.
- Keep local `.env` separate from `.env.production.example`.
- Live FTP/sync credentials are stored locally in `.env.live-sync`, which is
  ignored by Git. Do not copy those credentials into tracked docs or source files.
- Production deployment expects cached config/routes/views after migration; see
  `docs/deployment.md`.
- Live public media deletion is forbidden. Do not delete, reset, purge, replace,
  or mirror `storage/app/public` on production, and do not run public-storage
  cleanup commands with `--delete` on production. Live image recovery may only
  add missing files or restore from a verified hosting backup/snapshot.
- Local-to-live database import/sync is disabled and should stay removed. Use
  migrations and intentional live admin workflows instead of importing the local
  database into production.
- Be careful with files under `database/backups`, `database/dumps`, `storage`,
  `outputs`, `vendor`, and `node_modules`; they are usually artifacts or
  dependencies, not source changes.
- Local development intentionally does not store heavy catalog image files.
  Catalog rows may reference local public storage paths such as
  `competitor-catalog/...`, but those image files live only on production.
  Do not diagnose missing catalog photos from the local `storage/app/public` or
  `public/storage` contents alone; verify production storage, the public storage
  symlink, and the live DB image URLs. Local display can use
  `PUBLIC_STORAGE_FALLBACK_URL=https://sklad.nikolacars.kiev.ua/storage` so
  missing local public-storage images render from live while newly uploaded local
  files still render locally.
- Some catalog/source labels in config/docs may display with broken encoding.
  Treat that as existing data unless the user asks to repair encoding.

## Testing Guidance

- For narrow UI/controller changes, run the related feature test if one exists.
- For catalog import/search/filter/name behavior, run the relevant focused test
  under `tests/Feature` or `tests/Unit` before broadening.
- For migrations or stock/cashbook/STO workflows, prefer adding or updating a
  feature test that exercises the user-facing behavior.
- `composer test` clears config and runs the full Laravel test suite.

## Local Login

Local README documents the default seeded login:

- email: `admin@sklad.test`
- password: `password`

Open `http://127.0.0.1:8000/login` after starting the app.

## Agent Workflow

- Read this file first, then inspect the feature-specific controller/service/view
  and nearby tests.
- Prefer `rg`/`rg --files` for searching.
- Keep changes scoped to the requested feature.
- When changing project behavior, architecture, commands, data rules, or important
  workflows, update this file and any relevant docs so future work reflects the
  new logic.
- Do not rewrite unrelated formatting, generated assets, dumps, dependencies, or
  encoding artifacts.
- When changing frontend behavior, check both the Blade view and shared layout/CSS.
- When changing catalog behavior, check source config, controller paths, services,
  commands, and existing importer/search tests together.
