# NikolaCars parts storefront

The public catalog is available at `/parts/` (Ukrainian) and `/ru/parts/` (Russian). NikolaCars does not connect to the warehouse database directly: browser requests go through `PartsController`, which calls the protected storefront API in `sklad-zapchastey`.

## Local start

Laragon must serve `nikolacars.test`. Run:

```powershell
.\start-parts-local.ps1
```

The script starts the local MySQL server when needed, starts the warehouse API on `127.0.0.1:8011`, and opens the catalog.

The shared local API token is stored only in the ignored `.env` files of both projects. The Nova Poshta key is also stored only in `C:\Projects\sklad-zapchastey\.env`.

## Production configuration

Deploy the warehouse API before the public site. Configure the same random secret on both applications:

- Warehouse: `STOREFRONT_API_TOKEN`, `STOREFRONT_SITE_URL`.
- NikolaCars: `SKLAD_STOREFRONT_URL`, `SKLAD_STOREFRONT_TOKEN`.
- Warehouse Telegram notification: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`.

Do not expose `STOREFRONT_API_TOKEN` in browser JavaScript. The NikolaCars server is the only public proxy for it.

## Call tracking

Every page on `nikolacars.com.ua` exposes the Disassembly phone numbers. On
`nikolacars.kiev.ua`, `/parts/`, `/ru/parts/`, `/ua/parts/` and every nested
catalog, product, and checkout page expose the Disassembly numbers; the home page
and service pages keep the STO number. Do not persist the department in browser
session storage: moving from the catalog to the home page on `nikolacars.kiev.ua`
must immediately restore the STO number while Binotel retains its own advertising
source attribution.

## Search Console improvements (2026-10-07)

Product JSON-LD identifies the catalog with Google Product Taxonomy
`CategoryCode` 5613 (Vehicle Parts & Accessories). Custom warehouse paths
remain visible on the page; they are not presented as Google taxonomy paths.
Duplicate consecutive breadcrumb destinations are omitted.

The owner confirmed Nova Poshta shipping throughout Ukraine, paid by the
customer, pickup, and returns within 14 days of receipt. These terms are visible
on both localized product pages; offers include a finite 14-day
`MerchantReturnPolicy` for UA. The owner's second store delivery page
(`https://nikolacars.com.ua/ua/delivery_info`, checked 2026-10-07) supplies
free pickup at Kyiv, Kolektorna 30, return exclusions, and buyer-paid delivery
costs. Product pages show these conditions and link to that source; return
markup specifies `ReturnFeesCustomerResponsibility`. Initially, no return method
was assumed and `shippingDetails` was omitted rather than inventing a carrier
rate. No fabricated reviews or ratings are added.
The owner confirmed preparation of 1–16 hours and processing out-of-hours
orders from 10:00 on the next working day. Localized pages state this separately
from the carrier's indicative 1–3-day transit after dispatch. Shipping markup
at offer level requires a truthful rate or maximum rate; no zero-cost shipping is assumed.
Processing and dispatch are Monday–Friday only. Preparation excludes weekends;
orders received after 18:00 on Friday or over the weekend are processed from
10:00 Monday. Positive saved `length_cm`, `width_cm`, `height_cm` values from
the warehouse product-detail API are shown as labelled centimetre measurements.
Missing fields are hidden. These are product measurements, not packed dimensions.

The key service keeps its existing URLs and uses localized titles focused on
programming Tesla key cards. Search Console validation was started for category
and return-policy warnings; final acceptance depends on Google's recrawl.

This release used isolated copies of the live files to avoid prematurely
publishing pending AJAX-cache changes in PartsController. Backups and staged
files are in ignored `.codex-tmp/gsc-improvements-*-20261007` directories.
Future API releases must retain these live schema and content changes.

## Shipping and return recommendations (2026-10-08)

The owner confirmed returns both by Nova Poshta and in person. Product offers
now include `ReturnByMail` and `ReturnInStore`; both localized pages describe
these methods and ask customers to agree the return and shipping details first.
The existing 14-day window, exclusions and buyer-paid return shipping remain.

Each product offer's `shippingDetails` references an inline `ShippingService`
under the seller `Organization`. The Google-supported organization-level
`ShippingConditions` format permits transit information without a shipping
rate. It specifies Ukraine and indicative 1–3-day transit; the description
preserves variable, buyer-paid Nova Poshta tariffs. No fixed price, maximum
price or free shipping is invented, and part dimensions are not used as packed
shipping dimensions. This does not promise a numeric delivery price in Search.

Handling is represented as 0–1 working days because Google requires whole-day
values: the visible 1–16-hour preparation statement is unchanged. Handling
days are Monday–Friday, with 18:00 cutoff using the current Europe/Kyiv UTC
offset. This schedule is separate from the carrier's transit time.

References:
- https://developers.google.com/search/docs/appearance/structured-data/merchant-listing
- https://developers.google.com/search/docs/appearance/structured-data/shipping-policy

Regression checks: `tests/merchant-policy-regression.php`.

The delivery/return section is a full-width direct child of `.parts-container`,
after `.product-detail` (which contains the existing description) and before
recommendations. It is no longer nested inside the purchase/specifications
column. The section anchor and both localized policy texts are unchanged.
Placement checks: `tests/product-delivery-layout-regression.php`.

Published the controller and product view with pre-upload hash guards and
post-upload hash verification; backups are in `.codex-tmp/merchant-shipping-*`.
Four live product pages (two products, both locales) returned HTTP 200 and
contained the policy reference and both return methods. Search Console live
inspection of `/parts/1002274-00-d-103754/` at 2026-10-08 01:28:23 reported one
valid merchant listing with no warnings. Google resolved the shipping-service
reference and recognized both return methods. The aggregate report has not yet
been refreshed by a site-wide recrawl; no new validation or indexing request was
submitted. Optional review/rating recommendations in Product snippets are
outside this change and must not be filled with fabricated reviews.

## Shared language-switch state (2026-10-08)

Both header layouts load `public/assets/css/language-switch.css`. The main
stylesheet had no active-language rule, while the parts stylesheet did. The
shared `.lang .pill.active` rule preserves the existing storefront appearance
and highlights the current language on the home, service and other main-site
pages. Only the selected link receives `aria-current="page"`; switching keeps
the current localized page.

Use an explicit asset version (`20261008-1` for this release), not a runtime
`filemtime(public_path(...))` lookup: this host deploys public assets separately
from the PHP application. Publish the CSS to `LIVE_PUBLIC_REMOTE` before
publishing the layouts. Increment the version when changing the stylesheet.

`tests/language-switch-regression.php` checks 24 layout/path/locale combinations.
Eight live pages (home, service, contacts and a product, both locales) returned
HTTP 200 with the correct active language. Browser checks confirmed distinct
computed colors/backgrounds and actual UA/RU switching on home and service
pages. Pre-change layout backups are `.codex-tmp/language-active-*-backup-*`.
