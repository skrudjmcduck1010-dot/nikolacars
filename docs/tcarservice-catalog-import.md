# TCARS catalog import

The importer stores parsed TCARS data in separate catalog tables. It does not create real warehouse products or stock items.

Cybertruck, Rivian, and Lucid Air catalog branches are excluded from import.

Run migrations first:

```bash
php artisan migrate
```

Small check run:

```bash
php artisan parts:import-tcarservice --dry-run --max-category-pages=5 --max-products=3
```

Limited import:

```bash
php artisan parts:import-tcarservice --max-category-pages=50 --max-products=200 --sleep-ms=300
```

Show current progress in the console:

```bash
php artisan parts:import-tcarservice --show-progress --max-category-pages=50 --max-products=200 --sleep-ms=300
```

Import only the full category tree first:

```bash
php artisan parts:import-tcarservice-categories --show-progress --max-category-pages=0 --sleep-ms=250
```

Continue category import using already scanned local branches:

```bash
php artisan parts:import-tcarservice-categories --resume --show-progress --max-category-pages=1000 --sleep-ms=250
```

Then import product cards from saved leaf categories:

```bash
php artisan parts:import-tcarservice-products --show-progress --max-categories=50 --max-products=200 --sleep-ms=300
```

The product import follows pagination links inside each leaf category. Use `--max-pages-per-category=30` if a category has many pages.
If a saved leaf category is actually an intermediate TCARS page with deeper subcategories, the product import saves those child categories and continues with them instead of trying to parse products from the intermediate page.

Re-scan one exact leaf category:

```bash
php artisan parts:import-tcarservice-products --show-progress --category-url=/zapchasty/model-y-327/11---closure-components-1821/1120---liftgate-1839/liftgate-handle-and-emergency-release-1840
```

Already checked leaf categories are skipped on later product imports. Force a re-check with:

```bash
php artisan parts:import-tcarservice-products --rescan --show-progress --max-categories=50 --max-products=200
```

By default, the importer skips category pages only after their child links were scanned and marked locally. Existing final categories are still opened, because product links live inside those leaf pages.

Force a full category re-scan:

```bash
php artisan parts:import-tcarservice --no-skip-existing-categories --max-category-pages=50 --max-products=200 --sleep-ms=300
```

Import one known final category:

```bash
php artisan parts:import-tcarservice --start-url=/zapchasty/model-3-326/10---body-2124/1001---bumper-and-fascia-2141/front-bumper-carrier-2142 --max-products=20 --sleep-ms=300
```

Full import:

```bash
php artisan parts:import-tcarservice --sleep-ms=300
```

Refresh competitor products from any supported competitor catalog into the warehouse products table:

```bash
php artisan parts:refresh-competitor tcarservice
php artisan parts:refresh-competitor teslapartsukraine
php artisan parts:refresh-competitor tsk
php artisan parts:refresh-competitor stock-tesla
php artisan parts:refresh-competitor driveparts
php artisan parts:refresh-competitor dkparts
php artisan parts:refresh-competitor erazborka
php artisan parts:refresh-competitor toprazborka
php artisan parts:refresh-competitor teslawestparts
php artisan parts:refresh-competitor teslacompany
```

`teslahelp` /  is intentionally excluded from this refresh flow.

Remove already imported Cybertruck, Rivian, and Lucid Air rows:

```bash
php artisan parts:purge-non-tesla-catalog
```

Parsed parts become suggestions in the product name autocomplete. Selecting a TCARS suggestion fills the part number, slug, model, generation/compatibility, price, currency, and description with the source URL and catalog path.
