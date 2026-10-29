# NikolaCars parts storefront

## 2026-10-29 — dependency security upgrade and cart fix released

The owner approved resolving the dependency gate and releasing the cart fix.
Released and verified from pushed commit
`9ce135eb317b7eefb4fde9fdfcb0a9f19137006b` on `codex/parts-storefront`.
The bounded Composer update changes17 packages (16 production,1 development),
retains Laravel12 and PHP^8.2, and adds an explicit CommonMark^2.10.2 floor.
Laravel12.51.0 becomes12.70.2; CommonMark2.8.0 becomes2.10.2. The full locked
Composer security audit now has0 advisories and0 abandoned packages; strict
manifest validation and local platform checks pass. Composer scripts were not
executed during the update.

For compatibility checks,131 current live source files were captured using
certificate-verified FTPS;44 stale/missing local source files were reconciled with
those exact live bytes, preserving the three cart fixes, updated Composer files
and the local/HOSTiQ-compatible public front controller. These44 files are
baseline adoption only and must not become an upload of the whole application.
The deployment delta remains composer.json/lock and the vendor built from that
lock, plus parts.js, the header-cart partial and the catalog asset version.

A short-lived token-authenticated POST-only operational inspection confirmed
PHP8.3.36/LiteSpeed, SQLite:memory: (no persistent frontend database/tables),
file cache and sessions, and timestamp-validated OPcache. Its runner was removed
after byte verification. A frontend database dump is not applicable; warehouse
database access, imports, migrations, real forms and historical event replay are
outside this release. Preserve file cache/sessions, environment, media and private
data. Before promotion, preserve and independently verify the actual code/vendor
rollback archive; build and audit a separate production vendor in private storage.
Use a temporary atomic public-index503 gate for the short vendor switch, then
restore the exact original hosting index and refresh compiled views/OPcache.

Evidence and preparation are under
`C:/Projects/sklad-zapchastey/.codex-tmp/public-cart-security-20261029/`.
Local compatibility gates pass: `composer test` runs9 scripts in separate fresh
processes/cache/storage with an outer Throwable catch and completion sentinel.
An intentional assertion after Laravel installs its handler must fail with exit1;
this prevents the old scripts' misleading exit0 on uncaught assertions. The two
stale expectations were updated to the actual live service subtitles and named
JSON-LD entities, preserving semantic guards. A new HTTP-kernel cache contract
checks deferred refresh failure, last-good retention, recovery and hard expiry.
Playwright50/50 passes (34 cart+16 real routed homepage/service/product/404 cases),
UA/RU and desktop/mobile, without retries/skips/flaky cases. The upstream is
synthetic; no real order, callback, payment or authorization is tested.
Source UTF-8/BOM/NUL checks149/0 and32 PHP source lints pass; the warehouse
heuristic's259 existing findings are identical to captured production, with0
introduced findings. No scanner suppression or unrelated text repair was added.
The private Linux production build passed Composer platform checks and audit0,
with scripts/plugins disabled and the exact76 locked production packages.
Promotion and finish completed: full current vendor6022 files matches the staged
ledger; all8357 old vendor files remain privately preserved with their original
tree hash. Independent FTPS verified all131 source files: five candidates match
Git/local bytes (allowing Git CRLF normalization), and the other126 match the
captured live baseline. The hosting index was restored byte-for-byte.

Before maintenance, the scoped application-source/whole-vendor rollback archive
was independently downloaded and checked:24,435,749 bytes,8528 regular files,
SHA256 `11ac233759f69a35e4de535379f520e341916d5cb3f7e9edb0ccaee1e085ea73`,
gzip CRC, safe tar paths/no links and every file hash. It excludes environment,
storage/media and unrelated public assets. No persistent frontend DB exists.
The maintenance action began08:47:04UTC (10:47Kyiv); finish began08:51:01UTC,
then restored the index after its short cache/OPcache work. Exact gate timestamps
were not measured separately; the required drain was180s. Package manifests and
compiled views were rebuilt; application data cache/sessions were not cleared.

Environment and protected runtime configuration hashes are unchanged, cached
config remains absent, maintenance is off. The bounded store metadata sample
retained all1147 observed entries and added9; active cache/session contents can
change normally, so no complete-store content identity is claimed. All three
temporary operational runners were removed and independently returned FTP550.
Live browser checks pass for UA desktop and RU mobile: empty/open/add/quantity/
sum/remove, checkout navigation and header editing without form submission;
homepage, service and real product render. The test cart was emptied and viewport
restored. Screenshots and detailed proofs are in the evidence directory above.
Only the pre-release old-version renderCart error remained in the captured browser
log; no new post-release uncaught error was observed. No order, callback, payment,
warehouse DB import, historical replay or media cleanup was performed. This result
documentation is a later docs-only checkpoint; released/tested runtime SHA stays9ce135e.

## 2026-10-29 — authorized cart release blocked by dependency audit

The owner authorized the cart release. Fresh certificate-verified FTPS reads of
all11 related sources match the reviewed28October baseline exactly; no newer
remote edits would be overwritten. The three-file cart payload remains prepared
locally. Fresh isolated Playwright passes34/34 (48.0s); npm audit reports0.

Release stopped before commit, push, upload, cache operations or maintenance:
`composer audit --locked --no-dev --format=json` returns1 with43 advisories
affecting11 production packages. Fresh live `composer.json`, `composer.lock` and
`vendor/composer/installed.json` are byte-identical to the local versions, so
these are actual deployed dependencies, not merely a stale local lockfile.
`league/commonmark` is2.8.0; the warehouse AGENTS release policy requires>=2.10.2
and retaining security audits. These findings predate the cart fix.

Updating the framework/vendor set requires a separately scoped dependency
upgrade and compatibility review of the current public site; it must not be
hidden inside the prepared three-file cart upload or use the older whole local
frontend as a replacement. After resolving the audit, repeat the required gates,
fresh source guards and task-only Git publication, then perform the guarded cart
release and browser verification without submitting an order. No production
business data, environment, media or caches were changed. Evidence:
`C:/Projects/sklad-zapchastey/.codex-tmp/public-cart-release-20261029/`.

## 2026-10-28 — cart repair and permanent browser checks, local only

The existing source checkout is `C:/Users/Skrudj/OneDrive/Documents/ChatGPT/nikolacars`;
`C:/Projects/nikolacars` is its existing junction. No second checkout was created.
The local source predated the current live SEO/phone changes. Before editing,
certificate-verified read-only FTPS snapshots were compared with the local files.
Only the four required source files were reconciled: `parts.js`, the header-cart
partial, the catalog view and its `CatalogPath` dependency. Existing live SEO,
preferred paths, phone content and after-hours handling were preserved.
`CatalogPath.php` is byte-identical to the live snapshot; it is baseline adoption,
not a new SEO change. Other layouts/controllers remain older local versions.

The actual repair relative to that live baseline is three small changes:

- `renderCart` updates zero or more legacy `[data-cart-count]` nodes, then emits
  the existing `nc:cart-updated` event. A missing retired floating counter no
  longer stops catalog initialization or cart updates.
- The shared header opens `ncCartModal` directly when available, including on
  checkout. Pages without the modal keep their native catalog link and existing
  server redirect to `#cart`. The retired floating button is no longer required.
- The catalog loads `parts.js?v=20261028-cart-fix1` for future cache invalidation.

Playwright is a pinned development dependency. Run `npm ci`, optionally
`npm run test:e2e:install`, then `npm run test:e2e` in this checkout. Windows uses
the absolute Laragon PHP path; `LOCAL_PHP` may select another PHP executable.
The runner starts only an isolated PHP server on `127.0.0.1:4327`, with fresh
cache/views/storage under ignored `.codex-tmp/cart-e2e/`. It never starts the
ordinary MySQL or warehouse API from `start-parts-local.ps1`. Real Blade/JS are
rendered with synthetic warehouse responses; PHP stray requests and browser
off-origin requests are blocked. The router rejects every non-GET, other hosts
and private/unlisted paths; browser tests never submit an order.

Because the remaining local layout still contains the retired floating button,
the test-only header profile removes exactly that control from rendered Blade
to replay the verified current live DOM; the legacy profile retains it. This is
contract coverage, not a claim that the whole local frontend matches production.
Both locales and desktop/mobile cover empty opening/closing and focus, add-once/
remove, quantity1..stock, totals/badges/localStorage after reload, native link
fallback, checkout navigation and basket edits without submission.

Both defects were reproduced separately before their fixes. Final **34/34 browser
checks** pass, retries/flaky/skipped0; all **8 existing PHP regression scripts**
also pass. Desktop/mobile basket screenshots were inspected. Syntax, strict UTF-8/
BOM and source preservation checks pass; npm audit reports0 vulnerabilities.
The warehouse encoding heuristic flags2 unchanged readable RU/UA translation
lines in the verified live catalog view; baseline and final findings are identical.
No scanner suppression or unrelated translation edit was introduced. Private environment files
are unchanged. Evidence/backups and the three-hunk live-relative patch are under
`C:/Projects/sklad-zapchastey/.codex-tmp/public-cart-fix-20261028/`.

No commit/push/deploy, production form, notification, DB change or media cleanup
was performed. A future separately authorized release must freshly verify the
three changed runtime files against current live bytes, publish the JS before its
new view version, and preserve unrelated newer frontend work. Do not deploy the
entire older local frontend or upload tests, Node dependencies or private files.

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

## Portable front controller (2026-10-08)

`public/index.php` selects `public/laravel_app` when that directory contains
`bootstrap/app.php` (the existing HOSTiQ layout), otherwise the repository root
(the standard local Laravel layout). Autoloading, bootstrap and maintenance
mode all use the selected root. The hosted layout takes precedence if both
exist; maintenance in an inactive layout must not stop the selected application.

`tests/front-controller-regression.php` exercises both layouts, precedence and
maintenance behavior in six isolated PHP processes without database or API
requests. Temporary fixtures are confined to `.codex-tmp/` and removed after
the check. The real local entry point was also exercised through PHP CLI:
Laravel bootstrapped and handled the `/up` health request with status 200.
This is not an HTTP-server or live-hosting test. This compatibility change is
committed locally only, not published to HOSTiQ.

## SEO package 2 prepared (2026-10-29, Europe/Kyiv UTC+02:00)

The owner authorized the second narrow SEO release. Four existing localized
landings were selected from GSC query-to-page observations: Highland wheels and
hubcaps, number plate brackets, rear brake components, and Model S 2016–2021.
CategoryCatalogSeo supplies specific metadata and short selection guidance;
ModelCatalogSeo adds only the existing 2016–2021 Model S generation. Clean hub
links expose the three category destinations. Text respects the actual mixed
assortment: currently available Highland items are hubcaps, not wheel rims.

CatalogPath adds four reviewed parent-to-leaf aliases (two groups × UA/RU).
Each group was proven by complete nonempty product ID sets on 29 October,
including both locale variants (5 Highland IDs; 7 plate bracket IDs). Only
these aliases redirect; official repeated levels elsewhere remain unchanged.
Query strings survive direct 301s. Page-2 canonical and query noindex behavior
is preserved. The landing heading survives initial JS and AJAX rendering;
category/model links still perform full document navigation.

Independent production allowlist: app/Services/CatalogPath.php,
app/Services/ModelCatalogSeo.php, new app/Services/CategoryCatalogSeo.php,
app/Http/Controllers/PartsController.php, resources/views/parts/index.blade.php,
new resources/views/parts/_category_intro.blade.php, public/assets/js/parts.js.
The separately owned 12V service view is the eighth release file. Current
callback/launcher and 29 October cart changes are preserved.

The battery localization fix is a separate dependency, not part of this
independent payload. Backend presentation/query normalization is ready in
StorefrontCatalogTaxonomy, StorefrontCatalogQuery and StorefrontCatalogService;
it leaves DB and raw available/sold snapshots unchanged. A five-file frontend
overlay aligns old Russian root aliases, canonical/hreflang, breadcrumbs,
model links and scoped cache generations only after backend deployment.
The backend clean-tree release gate is currently blocked by unrelated work;
no stash, worktree exception, deployment or bypass was performed by this agent.

Verification: tests/seo-package2-regression.php passed 314 assertions over
32 landing route/Blade variants; with the saved battery overlay, 341 assertions
passed, including redirect queries, pagination, no loops and sitemap deduping.
tests/e2e/seo-package2.cjs passed 16 mobile/desktop cases, including AJAX and
local cart interaction with all network intercepted. Existing nine PHP
regression scripts passed with the updated 12V subtitle expectation; final
test-only coordination is owned by release_verifier. Backend related suite:
60 tests / 688 assertions PASS; PHPStan of the three runtime files PASS.

Evidence and exact before/after SHA manifests live in the project mirror:
C:/Projects/nikolacars/docs/reports/seo-package2-2026-10-29.md and
.local-backup-20261029-seo-package2-tech/. The full battery patch is preserved
under deferred-battery/. Rollback must use the scoped fresh live before files;
do not roll back the entire site, cart, callback, database or unrelated work.
Battery frontend must not remain active if backend normalization is rolled back.
Status here is local preparation; release_verifier/root records deployment and
live verification separately. GSC observations through 27 October are baseline
selection evidence, not a measured ranking effect of this package.

### Independent frontend released and verified — 29 October 2026, 18:33 Kyiv

The eight runtime files were published at16:29:18 UTC from runtime commit
`857a4aed59f07441f787c6448dddfe118a979868`; documentation successor
`d489958cf1798042ccf98f853ac9b8d264cde390` contains no runtime changes.
Immutable manifest SHA256:
`5b2519aee9458063aba1a0aef193146624bbdb2dd98a376ed933117a0b85196f`.
Verified FTPS staging, immediate-before checks, readback and full protected
source/environment/dependency guard passed:8 payload files,143 guarded paths.
The two earlier stops occurred before any rename (FTPS session closure and
the completed documentation edit); receipts are retained. The same stage
was verified and resumed without repeating an unknown mutation.

Existing PHP regressions9+failure sentinel, new314 assertions/32routes,
landing browsers16, cart/compatibility E2E50, actual anchor navigation16,
and offline transport/recovery guards6 passed. The live autoloader supports
App PSR4 fallback; no Composer/vendor changes were needed.

In-app browser verification covered all8 localized landings, both12V service
locales, two old Russian parent aliases and the warehouse health page.
New title/H1/intro/canonical content, actual language navigation and final
alias destinations passed. Highland photos/header basket/contact launcher
rendered. No forms, orders or live basket contents were changed; HTTP status
codes were not inferred from DOM. A bounded Laravel log tail through16:32:45UTC
contained no post-release ERROR/upstream503/no-response; three slow successful
catalog200 entries were recorded. This does not establish future host uptime.

Release report and recovery evidence are in the mirror's
`docs/reports/seo-package2-release-2026-10-29.md` and this checkout's
`.codex-tmp/seo-package2-release-20261029/`. Backend battery normalization and
its five-file frontend overlay remain deferred pending the clean-tree
procedure decision; this independent publication does not claim that fix
or resolution of the earlier5xx observations.

### Battery normalization released and verified — 29 October 2026, 19:32 Kyiv

The owner explicitly authorized a temporary clean warehouse worktree. Backend
commit `5d9deca6d9555137e5f717cbff75c829fedd436a` was published by the normal
release wrapper and returned live at17:22:01UTC. The three catalog services
normalize the Russian battery root in memory while preserving IDs, quantities,
raw snapshots and lower taxonomy levels. The fresh audit required the single
security prerequisite symfony/mime7.4.13→7.4.22; no other packages or manifest
constraints changed. The backup rotation operator separately gained verified
FTPS/PROT P in a local-only task commit; it was not deployed as runtime.

All required gates passed:3669PHPtests/38954assertions,74browser E2E, PHPStan,
encoding1688, build and Composer/npm audits0. Fresh DB/code backups and normal
retention passed; no pending migrations existed and live reported Nothing to
migrate. An additional complete vendor/manifests archive was independently
verified before maintenance. Post-install checks matched27source/build paths,
the lock/marker,78production packages and all74Mime files. Protected settings
and443durable private entries remained unchanged except the expected lock marker.

After three live UA/RU/oldalias API responses proved identical complete11ID and
quantity sets, the five dependent frontend files were published at17:26:06UTC,
commit `d36de9718f61f2d10c14dd32f260e0b97d071bae`. All5payload/143guard paths
matched. Fresh341PHPassertions,9regressions+failure sentinel and6operator tests
passed. IAB HTML showed11cards (7available/4sold), reciprocal hreflang,
selfcanonical and correct final oldalias destination. HTTP301 is covered by
route tests, not inferred from DOM. ModelS retained its separate3cards;
product108527 HTML/schema breadcrumbs use the new root. RU sitemap contained
3666loc, including5canonical battery URLs with no oldroot or repeats among those5.

The bounded frontend log contained0ERROR/503/no-response and3slow successful
catalog200 reads. Backend44ERROR records were43external Nova Poshta tracking404s
and1scheduled history-sync failure; the same class/frame/status occurred before
release (188such404s and5history-sync failures in the compared window). This
existing integration issue was not changed, and whole-host uptime is not claimed.

Recovery archives, receipts and full local reports remain outside the temporary
worktree. Root removed it with ordinary Git (no force); the pushed task branch
remains. All33dirty canonical file hashes/statuses, branch and35535c7HEAD stayed
unchanged. For ordinary SEO rollback restore the five frontend files first,
then the three backend services with own-after guards; retain the Mime security
patch and never restore business DB/environment/unrelated work. No rollback,
real form/order submission, indexing request or automation was performed.
Evidence: mirror `docs/reports/seo-package2-release-2026-10-29.md`, its technical
report/IAB sources, and this checkout's ignored SEO release directory.
The earlier deferred-battery status is superseded; ranking changes are not yet measured.


## 2026-10-29 — selected category tree and catalog intro cleanup released

The owner's explicit approval covered one temporary clean backend checkout and this scoped release. Backend `980fda919993c9d07742b8b5dce25f76ef2f0a46` adds `category_navigation` from complete already-loaded available/sold rows, with pure missing-breadcrumb fallback before filtering. It preserves battery localization and raw snapshots. Counts represent available cards and follow the selected model; roots and the expanded selected branch are returned. The two runtime files were released through the normal wrapper from live5d9deca6, with no dependency/schema change or foreign WIP included.

Frontend `33e1ce1988e7f5ed741a05bd5f17f2076d299d58` publishes nine runtime files: canonical SSR/JS tree links in the sidebar, current/ancestor states and responsive indentation; the three top SEO links, generic intro blocks and tagline above products are removed. H1/title/description, cards, photos, model links, cart and contacts remain. Independent verified-FTPS before/stage/immediate-rename/after guards passed for9payload/145paths; hosting overrides and security settings were preserved.

Fresh backend gates passed encoding1689/0, configured PHPStan,62PHPtests/717assertions, Composer/npm audits0, production build and74E2E; fresh DB/code backups, verified retention, no pending migrations, normal cache/warmup/up completed. Latest frontend checks passed554assertions/48SSR,24focused browser cases,6journeys/36model transitions and10regressions/failure probe. The earlier50cart/compat run covers the tree before the small intro-removal delta; it is not misreported as a fresh final-payload run.

Live IAB verified all6models/2886available cards at the root, preserved first24titles/first8photo URLs and loaded state, category/leaf/locale/model/sort transitions, and actual320/390px without label overflow. ModelY/Highland retain metadata and have no generic intros. No real form/order was submitted. Bounded frontend logs show0ERROR/5xx and3slow successful200; backend retains45 pre-existing Nova Poshta tracking404s plus1known scheduler failure, also present before release. Whole-host uptime or resolution of earlier503s is not claimed.

The clean checkout was removed with native Git without force after independent recovery verification. The pushed task branch remains; all35canonical dirty-file hashes/statuses, original branch and35535c7HEAD are unchanged. Recovery receipts/13DBarchives/codebackup remain outside the removed checkout. Rollback uses the nine frontend before files with own-after guards, then the previous Query through the normal backend wrapper; leave unrelated WIP, battery/Mime, DB, environment, media and durable receipts intact. Local report: mirror `docs/reports/catalog-tree-2026-10-29.md`; private receipts are not committed. Published and verified; no further agreed changes or monitoring scheduled.
