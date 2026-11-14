# Search Console indexing audit — 2026-10-07

Search Console lists 41 example URLs for 404 and 45 for crawled-not-indexed.
Source report update: 2026-10-04; example crawl dates can be newer.
The example lists were read from the authenticated report, not guessed.

## Confirmed change

`/services/car-service/` returned 404 while `/services/tesla-service/`
returned 200. A GET/HEAD-only exact 301 was added in `public/.htaccess`.
The live old URL subsequently returned 301 with the correct destination.
The original live configuration is backed up in ignored
`.codex-tmp/legacy-service-backup-20261007.htaccess`.

## Existing correct behavior

- `/contacts` redirects to `/contacts/`, which returns 200.
- `/ru/ru/privacy-policy/` redirects to `/ru/privacy-policy/`, returning 200.
- `/privacy_policy/` redirects to `/privacy-policy/`, returning 200.
- The repeated high-voltage-battery category redirects to its normalized URL.
- Legacy numeric product 108973 redirects to its article/id canonical URL.

Several local warehouse records matching the 404 examples are donor checklist
parts with unknown damage status, intentionally excluded from the public store.
Other records require current stock/visibility verification; do not assume all
product 404s are intentional or redirect them to arbitrary replacement products.
Old news, author/tag archives and cars without a verified equivalent remain 404.

## Interrupted checks

Two HTTP audit runs lost connectivity: even home/contact pages and the warehouse
timed out at TCP connection, while Google remained reachable. Connectivity
recovered between runs. The second run used three-second pauses and stopped on
its first connection failure. Temporary IP filtering or hosting instability is
possible but unconfirmed; no server/security logs have established the cause.

The second run completed 32 404-example checks before failure (4 ended at 200,
28 at 404). It did not reach the 45 crawled-not-indexed examples. Do not report
those as currently checked or start issue-wide fix validation based on this run.
The saved lists and partial results are in ignored `.codex-tmp`.

Resume with small batches after connectivity is stable. Check status, canonical,
robots directives, sitemap inclusion, useful product content and internal links.
Catalog pagination already has page-specific canonical URLs in the local code;
verify live responses rather than changing that logic speculatively.

## Individual Google index checks after connectivity recovered

The authenticated URL Inspection tool now says **URL is on Google** for all
three sampled URLs still present in the crawled-not-indexed example list:

- `/ru/parts/1104422-10-k-75221/` (battery product).
- `/parts/1933984-20-b-112997/` (Highland steering-column trim product).
- `/ru/parts/model-3-06-2017-12-2023/?page=42` (catalog pagination).

For the battery, Google reports a successful smartphone crawl on
2026-10-06 at 04:09:32, crawling and indexing allowed, and the inspected URL
as Google's canonical, matching the site's canonical. Product and merchant
structured data each have one valid item with noncritical warnings;
breadcrumbs have one valid item. The Sitemap field says temporary processing
error, but this has not prevented this URL from being indexed. It does not
establish a current sitemap outage without checking the actual sitemap report.

This proves that the aggregate exclusion list is already stale for these
examples, not that every one of its 45 URLs has been indexed. No indexing
request or issue-wide fix validation was submitted during these checks.
Screenshots are saved in ignored `.codex-tmp/gsc-battery-indexed-20261007.jpg`
and `.codex-tmp/gsc-pagination-indexed-20261007.jpg`.

## Completed live check of all 45 crawled-not-indexed examples

The resumed run used five-second pauses and completed all 45 URLs without
connection failures or HTTP 5xx responses. All 45 ended at HTTP 200:
42 responded directly and three redirected to their normalized URLs
(repeated battery category, repeated instrument-panel category, and the
privacy-policy URL missing its trailing slash).

Every final page has a canonical equal to its final URL. No HTML robots or
Googlebot meta noindex directive was found in the saved responses. This is
an HTTP/HTML check, not a claim that robots.txt and response-header directives
were individually tested for all URLs. The three URL Inspection samples above
provide additional Google-side confirmation of indexing.

Results: ignored `.codex-tmp/indexing-audit-20261007/crawled-results.json`.
This short successful run is not proof of long-term warehouse API stability.
No speculative canonical or pagination changes were deployed as part of it.
