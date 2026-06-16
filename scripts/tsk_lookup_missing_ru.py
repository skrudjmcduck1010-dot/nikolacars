import csv
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

import pymysql
from lxml import html


DB = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "sklad_zapchastey",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
OUTPUT_DIR = Path("storage/app/tsk-lookups")
MAX_WORKERS = 8
TIMEOUT = 18


def load_parts():
    conn = pymysql.connect(**DB)
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                select
                    part_number,
                    group_concat(distinct source order by source separator ',') as sources,
                    max(model_label) as model_label,
                    max(main_category_name) as main_category_name,
                    max(subcategory_name) as subcategory_name,
                    max(node_name) as node_name,
                    count(*) as rows_count
                from part_catalog_items
                where part_number is not null and part_number <> ''
                  and (name_ru is null or trim(name_ru) = '')
                  and source in ('tesla_official','teslapartsukraine')
                group by part_number
                order by part_number
                """
            )
            return cur.fetchall()
    finally:
        conn.close()


def load_existing_tsk(parts):
    if not parts:
        return {}

    part_numbers = [row["part_number"] for row in parts]
    existing = {}
    conn = pymysql.connect(**DB)
    try:
        with conn.cursor() as cur:
            for offset in range(0, len(part_numbers), 1000):
                chunk = part_numbers[offset : offset + 1000]
                placeholders = ",".join(["%s"] * len(chunk))
                cur.execute(
                    f"""
                    select
                        part_number,
                        source_url as tsk_url,
                        name_ru,
                        price_amount,
                        currency,
                        json_unquote(json_extract(raw_attributes, '$.product_url')) as product_url
                    from part_catalog_items
                    where source = 'tsk'
                      and part_number in ({placeholders})
                      and name_ru is not null
                      and trim(name_ru) <> ''
                    order by price_amount is null, id
                    """,
                    chunk,
                )
                for row in cur.fetchall():
                    existing.setdefault(row["part_number"], row)
    finally:
        conn.close()
    return existing


def product_url(part_number, lang=None):
    slug = part_number.strip().lower()
    if lang:
        return f"https://tsk.ua/{lang}/{slug}/"
    return f"https://tsk.ua/{slug}/"


def fetch(url):
    req = Request(url, headers={"User-Agent": USER_AGENT, "Accept-Language": "ru,en;q=0.8"})
    with urlopen(req, timeout=TIMEOUT) as response:
        body = response.read().decode("utf-8", "replace")
        return response.geturl(), body


def jsonld_products(doc):
    for script in doc.xpath('//script[@type="application/ld+json"]/text()'):
        try:
            data = json.loads(script)
        except Exception:
            continue

        nodes = data if isinstance(data, list) else [data]
        for node in nodes:
            if isinstance(node, dict) and node.get("@type") == "Product":
                yield node


def offer_price(product):
    offers = product.get("offers") if product else None
    if isinstance(offers, list):
        offers = offers[0] if offers else None
    if not isinstance(offers, dict):
        return None, None
    price = offers.get("price")
    currency = offers.get("priceCurrency")
    if price in (None, ""):
        return None, currency
    try:
        price = str(price).replace(",", ".")
        return f"{float(price):.2f}", currency
    except ValueError:
        return str(price), currency


def visible_price(text):
    patterns = [
        r"(?:Ціна|Цена|Price)\s*:\s*([0-9]+(?:[.,][0-9]+)?)\s*([A-Z]{3})",
        r"([0-9]+(?:[.,][0-9]+)?)\s*(USD|EUR|UAH)",
    ]
    for pattern in patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            try:
                return f"{float(match.group(1).replace(',', '.')):.2f}", match.group(2).upper()
            except ValueError:
                return match.group(1), match.group(2).upper()
    return None, None


def parse_card(part_number, final_url, body):
    doc = html.fromstring(body)
    h1 = " ".join(doc.xpath("normalize-space(//h1)").split())
    text = " ".join(doc.xpath("//body//text()"))
    product = next(jsonld_products(doc), None)

    if not product and not h1:
        return None

    ld_name = product.get("name") if product else None
    name = h1 or ld_name
    if not name:
        return None

    page_blob = f"{final_url}\n{body[:5000]}".lower()
    if part_number.lower() not in page_blob and not product:
        return None

    price, currency = visible_price(text)
    if price is None:
        price, currency = offer_price(product)

    return {
        "part_number": part_number,
        "tsk_url": final_url,
        "name_ru": name.strip(),
        "price_amount": price,
        "currency": currency,
        "jsonld_name": ld_name,
    }


def lookup(row):
    part_number = row["part_number"]
    last_error = None
    for lang in (None, "en", "ru"):
        url = product_url(part_number, lang)
        try:
            final_url, body = fetch(url)
            parsed = parse_card(part_number, final_url, body)
            if parsed:
                return {**row, **parsed}
        except HTTPError as exc:
            if exc.code == 404:
                return None
            last_error = f"HTTP {exc.code}"
        except (URLError, TimeoutError, OSError) as exc:
            last_error = str(exc)
    return {"part_number": part_number, "error": last_error}


def write_csv(path, rows):
    fields = [
        "part_number",
        "name_ru",
        "price_amount",
        "currency",
        "tsk_url",
        "sources",
        "model_label",
        "main_category_name",
        "subcategory_name",
        "node_name",
        "rows_count",
        "jsonld_name",
        "found_via",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def main():
    limit = int(sys.argv[1]) if len(sys.argv) > 1 else None
    parts = load_parts()
    if limit:
        parts = parts[:limit]

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_path = OUTPUT_DIR / f"tsk_found_missing_ru_{stamp}.csv"
    errors_path = OUTPUT_DIR / f"tsk_errors_missing_ru_{stamp}.csv"

    existing_tsk = load_existing_tsk(parts)
    found = []
    for row in parts:
        tsk = existing_tsk.get(row["part_number"])
        if not tsk:
            continue
        merged = {
            **row,
            **tsk,
            "tsk_url": tsk.get("product_url") or tsk.get("tsk_url"),
            "found_via": "local_tsk",
        }
        found.append(merged)

    parts_to_lookup = [row for row in parts if row["part_number"] not in existing_tsk]
    errors = []
    started = time.time()

    print(
        f"Loaded {len(parts)} part numbers; {len(found)} already have local TSK data; "
        f"checking {len(parts_to_lookup)} on tsk.ua with {MAX_WORKERS} workers..."
    )
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
        futures = [pool.submit(lookup, row) for row in parts_to_lookup]
        for index, future in enumerate(as_completed(futures), 1):
            result = future.result()
            if result and result.get("name_ru"):
                result["found_via"] = "tsk.ua"
                found.append(result)
            elif result and result.get("error"):
                errors.append(result)

            if index % 250 == 0 or index == len(futures):
                write_csv(output_path, found)
                if errors:
                    write_csv(errors_path, errors)
                elapsed = time.time() - started
                print(
                    f"{index}/{len(futures)} checked online, total found {len(found)}, "
                    f"errors {len(errors)}, {elapsed:.1f}s"
                )

    write_csv(output_path, found)
    if errors:
        write_csv(errors_path, errors)

    print(json.dumps({
        "checked": len(parts),
        "checked_online": len(parts_to_lookup),
        "found_local_tsk": len(existing_tsk),
        "found": len(found),
        "errors": len(errors),
        "output": str(output_path),
        "errors_output": str(errors_path) if errors else None,
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
