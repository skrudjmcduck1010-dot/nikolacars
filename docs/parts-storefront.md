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
