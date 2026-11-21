# Google Analytics 4

Production property: `418166094`; web stream: `6426290165`; measurement ID: `G-5GRKRBQXET`.

Both Blade layouts include `partials.analytics`. The Google tag is loaded once per page; `assets/js/analytics.js` handles business events.

| Event | Trigger |
| --- | --- |
| `generate_lead` | Server-confirmed callback request or parts order submission |
| `click_phone` | Click on a telephone link, not a completed call |
| `click_email` | Click on an email link |
| `click_messenger` | Click on Telegram, Viber, or WhatsApp; `contact_method` identifies the channel |
| `view_item` | Product detail loaded |
| `add_to_cart` | A new product is added to the cart |
| `search` | Catalog results loaded for a changed nonempty query |

`generate_lead` is a GA4 key event, counted once per event with no default monetary value. `form_id` identifies `home_callback`, `service_callback`, `contacts_callback`, `callback_widget`, or `parts_order`. Page context identifies the particular service/product. Orders are requests pending confirmation, so this integration does not send `purchase` or claim revenue.

Custom payloads do not include submitted names, phone numbers, comments, delivery addresses, or VINs. Product events use an explicit allowlist. Search telemetry rejects email/phone-like terms while allowing Tesla part numbers. Existing GA4 enhanced measurement remains enabled, including automatic `form_start`, `form_submit`, and URL-based `view_search_results`; those are separate from the server-confirmed lead and custom AJAX search events.

Verified 2026-09-20: Ukraine reporting timezone, UAH, event/user retention 14 months. Enhanced measurement already enabled. The production Realtime report received page views, `view_item`, and `add_to_cart`. Synthetic form checks covered successful and failed responses for 11 callback handlers without submitting a real lead. JavaScript syntax and Blade compilation passed; production pages returned HTTP 200. Test cart was emptied.

Original production files and staged changes: `.live-backup-20260920-ga4-events/`. Live files differ from some older local files: deploy staged changes to preserve current production fixes. Verification: `node .live-backup-20260920-ga4-events/verify.cjs`.
