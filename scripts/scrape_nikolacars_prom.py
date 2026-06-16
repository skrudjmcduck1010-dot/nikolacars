from __future__ import annotations

import json
import re
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime
from pathlib import Path

import pandas as pd
from bs4 import BeautifulSoup


BASE_URL = "https://nikolacars.com.ua"
PRODUCT_LIST_URL = f"{BASE_URL}/ua/product_list"
CATEGORY_URLS = [
    f"{BASE_URL}/ua/g152203834-tesla-model",
    f"{BASE_URL}/ua/g152200207-tesla-model",
    f"{BASE_URL}/ua/g152203807-tesla-model",
]
LIST_QUERY = "product_items_per_page=48&sort=-date_created"
INPUT_XLS = Path(r"C:/Users/skrud/Downloads/Telegram Desktop/Номенклатура (2).xls")
OUTPUT_DIR = Path("outputs/nikolacars_prom")
USER_AGENT = "Mozilla/5.0 (compatible; Codex product data sync)"


def fetch(url: str, retries: int = 3) -> str:
    last_error: Exception | None = None
    for attempt in range(retries):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(request, timeout=35) as response:
                return response.read().decode("utf-8", "replace")
        except Exception as error:  # noqa: BLE001 - network retry wrapper
            last_error = error
            time.sleep(1.5 * (attempt + 1))
    raise RuntimeError(f"Failed to fetch {url}: {last_error}") from last_error


def product_json_objects(html: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    products: list[dict] = []
    for script in soup.find_all("script", type="application/ld+json"):
        text = script.string or script.get_text()
        if "Product" not in text:
            continue
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            continue
        candidates = payload if isinstance(payload, list) else [payload]
        for item in candidates:
            if isinstance(item, dict) and item.get("@type") == "Product":
                products.append(item)
    return products


def normalize_abs_url(url: str | None) -> str:
    if not url:
        return ""
    return urllib.parse.urljoin(BASE_URL, url)


def code_from_sku(sku: str | None) -> str:
    if not sku:
        return ""
    match = re.search(r"(\d+)\s*$", sku)
    return str(int(match.group(1))) if match else ""


def clean_text(text: str | None) -> str:
    if not text:
        return ""
    lines = [" ".join(line.split()) for line in text.replace("\r", "\n").split("\n")]
    return "\n".join(line for line in lines if line)


def extract_attributes(soup: BeautifulSoup) -> dict[str, str]:
    names = soup.find_all(attrs={"data-qaid": "attribute_name"})
    values = soup.find_all(attrs={"data-qaid": "attribute_value"})
    attrs: dict[str, str] = {}
    for name_tag, value_tag in zip(names, values):
        name = clean_text(name_tag.get_text(" ", strip=True)).rstrip(":")
        value = clean_text(value_tag.get_text(" ", strip=True))
        if name:
            attrs[name] = value
    return attrs


def extract_images(soup: BeautifulSoup, product: dict) -> list[str]:
    images: list[str] = []
    raw_image = product.get("image")
    if isinstance(raw_image, str):
        images.append(raw_image)
    elif isinstance(raw_image, list):
        images.extend(str(item) for item in raw_image if item)

    for img in soup.find_all("img", src=True):
        classes = " ".join(img.get("class", []))
        if "cs-image-holder__image" in classes or "b-sticky-panel__image" in classes:
            images.append(normalize_abs_url(img.get("src")))

    seen: set[str] = set()
    unique = []
    for image in images:
        if image and "images.prom.ua" in image and "nikolacars" not in image and image not in seen:
            seen.add(image)
            unique.append(image)
    return unique


def parse_product_page(url: str) -> dict:
    html = fetch(url)
    soup = BeautifulSoup(html, "lxml")
    products = product_json_objects(html)
    if not products:
        raise RuntimeError(f"No Product JSON-LD found at {url}")
    product = products[0]
    offers = product.get("offers") or {}
    attrs = extract_attributes(soup)
    description = clean_text(product.get("description"))

    return {
        "prom_sku": product.get("sku", ""),
        "code": code_from_sku(product.get("sku")),
        "prom_name": clean_text(product.get("name")),
        "prom_url": normalize_abs_url(offers.get("url") or url),
        "prom_price": offers.get("price", ""),
        "prom_currency": offers.get("priceCurrency", ""),
        "prom_availability": (offers.get("availability") or "").split("/")[-1],
        "prom_brand": product.get("brand", ""),
        "prom_description": description,
        "prom_condition": attrs.get("Стан", ""),
        "prom_color": attrs.get("Колір", ""),
        "prom_attributes_json": json.dumps(attrs, ensure_ascii=False),
        "prom_images": "; ".join(extract_images(soup, product)),
    }


def page_product_urls(html: str) -> list[str]:
    products = product_json_objects(html)
    urls = [normalize_abs_url((item.get("offers") or {}).get("url")) for item in products]

    # Fallback to regular card links if JSON-LD ever changes.
    if not urls:
        urls = [
            normalize_abs_url(match)
            for match in re.findall(r'href="([^"]*/ua/p\d+-[^"]+\.html)"', html)
        ]
    return [url for url in urls if url]


def category_page_urls(category_url: str) -> list[str]:
    first_url = f"{category_url}?{LIST_QUERY}"
    html = fetch(first_url)
    soup = BeautifulSoup(html, "lxml")
    paginator = soup.find(attrs={"data-bazooka": "Paginator"})
    pages_count = int(paginator.get("data-pagination-pages-count", "1")) if paginator else 1
    urls = [first_url]
    for page in range(2, pages_count + 1):
        urls.append(f"{category_url}/page_{page}?{LIST_QUERY}")
    return urls


def discover_product_urls() -> list[str]:
    urls: list[str] = []
    for category_url in CATEGORY_URLS:
        for page_url in category_page_urls(category_url):
            html = fetch(page_url)
            page_urls = page_product_urls(html)
            print(f"Discovered {len(page_urls)} product URLs from {page_url}")
            urls.extend(page_urls)

    # Keep the general list as a safety net for products that are not in the
    # three visible category menu entries.
    html = fetch(f"{PRODUCT_LIST_URL}?{LIST_QUERY}")
    fallback_urls = page_product_urls(html)
    print(f"Discovered {len(fallback_urls)} product URLs from {PRODUCT_LIST_URL}")
    urls.extend(fallback_urls)

    return sorted(set(urls))


def load_local_catalog() -> pd.DataFrame:
    catalog = pd.read_excel(INPUT_XLS, dtype=str).fillna("")
    catalog["Код"] = catalog["Код"].astype(str).str.strip()
    return catalog


def main() -> int:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    product_urls = discover_product_urls()
    print(f"Discovered {len(product_urls)} product URLs")

    scraped: list[dict] = []
    errors: list[dict] = []
    for index, url in enumerate(product_urls, start=1):
        try:
            item = parse_product_page(url)
            scraped.append(item)
            print(f"[{index}/{len(product_urls)}] {item['prom_sku']} {item['prom_name']}")
        except Exception as error:  # noqa: BLE001 - capture scrape failures into output
            errors.append({"prom_url": url, "error": str(error)})
            print(f"[{index}/{len(product_urls)}] ERROR {url}: {error}", file=sys.stderr)

    catalog = load_local_catalog()
    scraped_df = pd.DataFrame(scraped)
    merged = catalog.merge(scraped_df, left_on="Код", right_on="code", how="left")
    current_only = scraped_df.merge(
        catalog,
        left_on="code",
        right_on="Код",
        how="left",
        suffixes=("_prom", "_local"),
    )

    csv_path = OUTPUT_DIR / f"nikolacars_prom_matched_{stamp}.csv"
    xlsx_path = OUTPUT_DIR / f"nikolacars_prom_matched_{stamp}.xlsx"
    json_path = OUTPUT_DIR / f"nikolacars_prom_raw_{stamp}.json"

    merged.to_csv(csv_path, index=False, encoding="utf-8-sig")
    with pd.ExcelWriter(xlsx_path, engine="openpyxl") as writer:
        current_only.to_excel(writer, index=False, sheet_name="Current Prom products")
        merged.to_excel(writer, index=False, sheet_name="Catalog with Prom data")
        pd.DataFrame(errors).to_excel(writer, index=False, sheet_name="Scrape errors")

        for sheet_name in writer.sheets:
            ws = writer.sheets[sheet_name]
            ws.freeze_panes = "A2"
            ws.auto_filter.ref = ws.dimensions
            for column in ws.columns:
                max_len = max(len(str(cell.value or "")) for cell in column[:200])
                ws.column_dimensions[column[0].column_letter].width = min(max(max_len + 2, 10), 60)

    with json_path.open("w", encoding="utf-8") as handle:
        json.dump({"products": scraped, "errors": errors}, handle, ensure_ascii=False, indent=2)

    matched = int(current_only["Код"].astype(str).str.len().gt(0).sum()) if not current_only.empty else 0
    print(json.dumps({
        "product_urls": len(product_urls),
        "scraped": len(scraped),
        "matched_by_code": matched,
        "errors": len(errors),
        "csv": str(csv_path.resolve()),
        "xlsx": str(xlsx_path.resolve()),
        "json": str(json_path.resolve()),
    }, ensure_ascii=False, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
