import argparse
import hashlib
import json
import re
import sys
import time
from collections import deque
from datetime import datetime
from pathlib import Path
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

import pymysql
from lxml import html


BASE_URL = "https://tsk.ua"
START_URL = "https://tsk.ua/katalog-zapchastey296/"
SOURCE = "tsk"
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"

MODEL_ROOTS = [
    ("/katalog-zapchastey296/model-s-parts-europe/", "Model S 02.2012-03.2016", "Model S", 2012, 2016),
    ("/katalog-zapchastey296/model-sr-europe/", "Model S2 04.2016-01.2021", "Model S2", 2016, 2021),
    ("/katalog-zapchastey296/model-s-feb-2021-parts-catalog3736/", "Model S Palladium 02.2021-05.2025", "Model S Palladium", 2021, 2025),
    ("/katalog-zapchastey296/model-s-parts-catalog487/", "Model 3 06.2017 - 12.2023", "Model 3", 2017, 2023),
    ("/katalog-zapchastey296/model-3-sep-2023/", "Model 3 Highland 01.2024 -", "Model 3 Highland", 2024, None),
    ("/katalog-zapchastey296/model-x-europe/", "Model X 09.2015-02.2021", "Model X", 2015, 2021),
    ("/katalog-zapchastey296/model-x-mar-20214301/", "Model X Palladium 03.2021-05.2025", "Model X Palladium", 2021, 2025),
    ("/katalog-zapchastey296/model-y-parts-catalog3166/", "Model Y 01.2020 - 01.2025", "Model Y", 2020, 2025),
]


def clean(value):
    return re.sub(r"\s+", " ", value or "").strip()


def canonical_url(url):
    absolute = urljoin(BASE_URL, url)
    parsed = urlparse(absolute)
    path = re.sub(r"^/(ua|ru|en)(/katalog-zapchastey296/)", r"\2", parsed.path, flags=re.I)
    path = path.rstrip("/") + "/"
    return f"{parsed.scheme}://{parsed.netloc}{path}"


def is_epc_url(url):
    parsed = urlparse(url)
    path = parsed.path.strip("/")
    return parsed.netloc == "tsk.ua" and (path == "katalog-zapchastey296" or path.startswith("katalog-zapchastey296/"))


def fetch(url, retries=3):
    last_error = None
    for attempt in range(retries):
        try:
            req = Request(url, headers={"User-Agent": USER_AGENT, "Accept-Language": "uk-UA,uk;q=0.9,ru;q=0.8,en;q=0.7"})
            with urlopen(req, timeout=35) as response:
                return response.read().decode("utf-8", errors="replace")
        except Exception as exc:
            last_error = exc
            time.sleep(0.8 + attempt)
    print(f"FAILED {url}: {last_error}", file=sys.stderr, flush=True)
    return None


def parse_doc(source, url):
    doc = html.fromstring(source, base_url=url)
    doc.make_links_absolute(BASE_URL)
    return doc


def breadcrumb_names(doc):
    names = []
    for script in doc.xpath('//script[@type="application/ld+json"]/text()'):
        try:
            payload = json.loads(script)
        except Exception:
            continue
        if payload.get("@type") != "BreadcrumbList":
            continue
        for item in payload.get("itemListElement", []):
            name = clean((item.get("item") or {}).get("name", ""))
            lower = name.lower()
            if name and lower not in {"головна", "каталог запчастин", "epc tesla"}:
                names.append(name)
        if names:
            break

    if not names:
        for node in doc.xpath('//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//a | //*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//span'):
            name = clean(node.text_content())
            lower = name.lower()
            if name and lower not in {"головна", "каталог запчастин", "epc tesla"} and name not in names:
                names.append(name)

    return names


def split_code_name(name):
    match = re.match(r"^(\d+)\s*-\s*(.+)$", name or "")
    if match:
        return match.group(1), clean(match.group(2))
    return None, clean(name)


def category_links(doc, current_url, known_urls):
    current = canonical_url(current_url)
    links = []
    for node in doc.xpath("//a[@href]"):
        url = canonical_url(node.get("href"))
        if url == START_URL or url == current or not is_epc_url(url):
            continue
        text = clean(node.text_content())
        if not text:
            continue
        if url in known_urls:
            continue
        links.append(url)
    return list(dict.fromkeys(links))


def product_rows(doc, page_url):
    products = []
    for row in doc.xpath("//table//tr[td]"):
        cells = [clean(cell.text_content()) for cell in row.xpath("./td")]
        if len(cells) < 4:
            continue
        part_number = None
        part_index = None
        for index, cell in enumerate(cells):
            if re.match(r"^[A-Z0-9]{6,}-[A-Z0-9]{2,}(?:-[A-Z0-9]+)?$", cell or "", flags=re.I):
                part_number = cell.upper()
                part_index = index
                break
        if not part_number or part_index is None or part_index == 0:
            continue
        name = cells[part_index - 1]
        if not name or name.lower() in {"назва деталі", "name"}:
            continue
        scheme = cells[0] if cells[0] and cells[0] != "#" else None
        qty = cells[part_index + 1] if part_index + 1 < len(cells) else None
        availability = cells[part_index + 2] if part_index + 2 < len(cells) else None
        part_link = None
        for link in row.xpath(".//a[@href]"):
            if clean(link.text_content()).upper() == part_number:
                href = link.get("href") or ""
                if not href.lower().startswith("javascript:"):
                    part_link = canonical_url(href)
                break

        source_key = hashlib.md5("|".join([page_url, scheme or "", part_number, name]).encode()).hexdigest()
        products.append({
            "source_url": f"tsk-epc:{source_key}",
            "part_number": part_number,
            "name": name[:255],
            "scheme_number": int(scheme) if scheme and scheme.isdigit() and int(scheme) <= 65535 else None,
            "availability": availability,
            "product_url": part_link,
            "raw_attributes": json.dumps({"quantity": qty, "page_url": page_url, "product_url": part_link}, ensure_ascii=False),
        })
    return products


def product_card_name(url, cache):
    if not url:
        return None
    if url in cache:
        return cache[url]

    source = fetch(url, retries=2)
    if source is None:
        cache[url] = None
        return None

    doc = parse_doc(source, url)
    for query in ["//h1", "//h2"]:
        for node in doc.xpath(query):
            title = clean(node.text_content())
            if title and title not in {"&nbsp;"}:
                cache[url] = title[:255]
                return cache[url]

    cache[url] = None
    return None


def product_card_details(url, cache):
    if not url:
        return {}
    if url in cache:
        return cache[url]

    source = fetch(url, retries=2)
    if source is None:
        cache[url] = {}
        return {}

    doc = parse_doc(source, url)
    details = {}

    for query in ["//h1", "//h2"]:
        for node in doc.xpath(query):
            title = clean(node.text_content())
            if title and title != "&nbsp;":
                details["name"] = title[:255]
                break
        if details.get("name"):
            break

    text = clean(doc.text_content())
    match = re.search(r"Ціна:\s*([0-9]+(?:[\s,.][0-9]+)*)\s*(USD|EUR|UAH|ГРН)", text, flags=re.I)
    if match:
        details["price_amount"] = re.sub(r"\s+", "", match.group(1)).replace(",", ".")
        details["currency"] = "UAH" if match.group(2).upper() == "ГРН" else match.group(2).upper()
    else:
        for script in doc.xpath('//script[@type="application/ld+json"]/text()'):
            try:
                payload = json.loads(script)
            except Exception:
                continue

            if payload.get("@type") != "Product":
                continue

            offers = payload.get("offers") or {}
            price = offers.get("price")
            currency = offers.get("priceCurrency")
            if price and currency:
                details["price_amount"] = str(price)
                details["currency"] = str(currency).upper()
                break

    if not details.get("price_amount"):
        price_node = doc.xpath('//*[@itemprop="price"][@content][1]')
        currency_node = doc.xpath('//*[@itemprop="priceCurrency"][@content][1]')
        if price_node:
            details["price_amount"] = clean(price_node[0].get("content"))
            details["currency"] = clean(currency_node[0].get("content")) if currency_node else "USD"

    cache[url] = details
    return details


def connect():
    return pymysql.connect(
        host="127.0.0.1",
        port=3306,
        user="root",
        password="",
        database="sklad_zapchastey",
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def backup_existing(conn):
    backup_dir = Path("database/backups")
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / f"tsk_catalog_backup_{datetime.now().strftime('%Y%m%d_%H%M%S')}.sql"
    with conn.cursor() as cur, path.open("w", encoding="utf-8") as out:
        for table in ["part_catalog_items", "part_catalog_categories"]:
            cur.execute(f"select * from {table} where source=%s", (SOURCE,))
            for row in cur.fetchall():
                columns = ", ".join(f"`{key}`" for key in row)
                values = ", ".join(conn.escape(value) for value in row.values())
                out.write(f"insert into `{table}` ({columns}) values ({values});\n")
    return path


def reset_tsk(conn):
    with conn.cursor() as cur:
        cur.execute("delete from part_catalog_items where source=%s", (SOURCE,))
        cur.execute("delete from part_catalog_categories where source=%s", (SOURCE,))
    conn.commit()


def upsert_category(conn, payload):
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    payload = {**payload, "children_scanned_at": now, "products_scanned_at": now, "created_at": now, "updated_at": now}
    columns = list(payload)
    updates = [f"`{col}`=values(`{col}`)" for col in columns if col not in {"source_url", "created_at"}]
    sql = (
        f"insert into part_catalog_categories ({', '.join('`'+c+'`' for c in columns)}) "
        f"values ({', '.join(['%s'] * len(columns))}) "
        f"on duplicate key update {', '.join(updates)}"
    )
    with conn.cursor() as cur:
        cur.execute(sql, [payload[col] for col in columns])
        cur.execute("select id from part_catalog_categories where source_url=%s", (payload["source_url"],))
        return cur.fetchone()["id"]


def upsert_item(conn, payload):
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    payload = {**payload, "source": SOURCE, "source_updated_at": now, "created_at": now, "updated_at": now}
    columns = list(payload)
    updates = [f"`{col}`=values(`{col}`)" for col in columns if col not in {"source_url", "created_at"}]
    sql = (
        f"insert into part_catalog_items ({', '.join('`'+c+'`' for c in columns)}) "
        f"values ({', '.join(['%s'] * len(columns))}) "
        f"on duplicate key update {', '.join(updates)}"
    )
    with conn.cursor() as cur:
        cur.execute(sql, [payload[col] for col in columns])


def import_catalog(limit_pages=0, sleep=0.15):
    conn = connect()
    backup_path = backup_existing(conn)
    reset_tsk(conn)

    queue = deque()
    known_urls = {START_URL}
    root_meta = {}
    for path, model_label, model_name, year_from, year_to in MODEL_ROOTS:
        url = canonical_url(path)
        meta = {
            "model_label": model_label,
            "model_name": model_name,
            "year_from": year_from,
            "year_to": year_to,
            "depth": 0,
            "parent_id": None,
            "root_url": url,
        }
        queue.append((url, meta))
        known_urls.add(url)
        root_meta[url] = meta

    stats = {"pages": 0, "categories": 0, "items": 0, "cards": 0, "failed": 0, "backup": str(backup_path)}
    card_cache = {}

    while queue:
        if limit_pages and stats["pages"] >= limit_pages:
            break
        url, meta = queue.popleft()
        source = fetch(url)
        if source is None:
            stats["failed"] += 1
            continue
        doc = parse_doc(source, url)
        stats["pages"] += 1

        crumbs = breadcrumb_names(doc)
        current_name = crumbs[-1] if crumbs else meta["model_name"]
        if meta["depth"] == 0:
            name = meta["model_label"]
            code = None
        else:
            code, name = split_code_name(current_name)

        category_id = upsert_category(conn, {
            "parent_id": meta["parent_id"],
            "source": SOURCE,
            "source_url": url,
            "preview_image_url": None,
            "depth": meta["depth"],
            "code": code,
            "name": name[:255],
            "name_en": name[:255],
            "name_ru": name[:255],
            "name_ua": None,
            "model_label": meta["model_label"],
            "model_name": meta["model_name"],
            "year_from": meta["year_from"],
            "year_to": meta["year_to"],
            "sort_order": int(code) if code and code.isdigit() else 0,
        })
        stats["categories"] += 1

        main_category = None
        subcategory = None
        if meta["depth"] >= 1:
            code1, name1 = split_code_name(crumbs[1] if len(crumbs) > 1 else current_name)
            main_category = {"code": code1, "name": name1}
        if meta["depth"] >= 2:
            code2, name2 = split_code_name(crumbs[2] if len(crumbs) > 2 else current_name)
            subcategory = {"code": code2, "name": name2}

        for product in product_rows(doc, url):
            card_details = product_card_details(product["product_url"], card_cache)
            card_name = card_details.get("name")
            if card_name:
                stats["cards"] += 1
            upsert_item(conn, {
                "part_catalog_category_id": category_id,
                "source_url": product["source_url"],
                "part_number": product["part_number"],
                "name": product["name"],
                "name_en": product["name"],
                "name_ru": card_name or product["name"],
                "name_ua": card_name,
                "scheme_number": product["scheme_number"],
                "price_amount": card_details.get("price_amount"),
                "currency": card_details.get("currency"),
                "model_label": meta["model_label"],
                "model_name": meta["model_name"],
                "year_from": meta["year_from"],
                "year_to": meta["year_to"],
                "main_category_code": main_category["code"] if main_category else None,
                "main_category_name": main_category["name"] if main_category else None,
                "subcategory_code": subcategory["code"] if subcategory else None,
                "subcategory_name": subcategory["name"] if subcategory else None,
                "node_name": name[:255],
                "compatibility_text": meta["model_label"],
                "availability": product["availability"],
                "raw_attributes": product["raw_attributes"],
            })
            stats["items"] += 1

        for child_url in category_links(doc, url, known_urls):
            known_urls.add(child_url)
            queue.append((child_url, {
                **meta,
                "parent_id": category_id,
                "depth": meta["depth"] + 1,
            }))

        conn.commit()
        print(f"{stats['pages']:5} pages | {stats['categories']:5} categories | {stats['items']:6} items | {url}", flush=True)
        if sleep:
            time.sleep(sleep)

    conn.close()
    return stats


def backfill_product_cards(limit=0, sleep=0.05):
    conn = connect()
    card_cache = {}
    stats = {"seen": 0, "updated": 0, "no_card": 0, "failed": 0}

    with conn.cursor() as cur:
        cur.execute(
            """
            select id, part_number, raw_attributes
            from part_catalog_items
            where source=%s and source_url like 'tsk-epc:%%'
            order by id
            """,
            (SOURCE,),
        )
        rows = cur.fetchall()

    for row in rows:
        if limit and stats["seen"] >= limit:
            break
        stats["seen"] += 1

        try:
            raw = json.loads(row["raw_attributes"] or "{}")
        except Exception:
            raw = {}

        page_url = raw.get("page_url")
        part_number = row["part_number"]
        if not page_url or not part_number:
            stats["no_card"] += 1
            continue

        source = fetch(page_url, retries=2)
        if source is None:
            stats["failed"] += 1
            continue

        doc = parse_doc(source, page_url)
        product_url = None
        for link in doc.xpath("//table//tr[td]//a[@href]"):
            if clean(link.text_content()).upper() != part_number:
                continue
            href = link.get("href") or ""
            if not href.lower().startswith("javascript:"):
                product_url = canonical_url(href)
            break

        if not product_url:
            stats["no_card"] += 1
            continue

        card_details = product_card_details(product_url, card_cache)
        card_name = card_details.get("name")
        raw["product_url"] = product_url

        with conn.cursor() as cur:
            cur.execute(
                """
                update part_catalog_items
                    set name_ru=coalesce(%s, name_ru),
                        name_ua=coalesce(%s, name_ua),
                        price_amount=coalesce(%s, price_amount),
                        currency=coalesce(%s, currency),
                        raw_attributes=%s,
                        updated_at=%s
                where id=%s
                """,
                (
                        card_name,
                        card_name,
                        card_details.get("price_amount"),
                        card_details.get("currency"),
                        json.dumps(raw, ensure_ascii=False),
                    datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    row["id"],
                ),
            )
        conn.commit()
        stats["updated"] += 1

        if stats["seen"] % 50 == 0:
            print(json.dumps(stats, ensure_ascii=False), flush=True)
        if sleep:
            time.sleep(sleep)

    conn.close()
    return stats


def backfill_product_cards_by_page(limit_pages=0, sleep=0.02):
    conn = connect()
    card_cache = {}
    stats = {"pages": 0, "seen": 0, "updated": 0, "no_card": 0, "failed": 0}

    with conn.cursor() as cur:
        cur.execute(
            """
            select json_unquote(json_extract(raw_attributes, '$.page_url')) page_url
            from part_catalog_items
            where source=%s
              and source_url like 'tsk-epc:%%'
              and json_unquote(json_extract(raw_attributes, '$.page_url')) is not null
              and json_unquote(json_extract(raw_attributes, '$.product_url')) is null
            group by page_url
            order by min(id)
            """,
            (SOURCE,),
        )
        pages = [row["page_url"] for row in cur.fetchall()]

    for page_url in pages:
        if limit_pages and stats["pages"] >= limit_pages:
            break

        with conn.cursor() as cur:
            cur.execute(
                """
                select id, part_number, name, raw_attributes
                from part_catalog_items
                where source=%s
                  and source_url like 'tsk-epc:%%'
                  and json_unquote(json_extract(raw_attributes, '$.page_url'))=%s
                  and json_unquote(json_extract(raw_attributes, '$.product_url')) is null
                """,
                (SOURCE, page_url),
            )
            rows = cur.fetchall()

        if not rows:
            continue

        source = fetch(page_url, retries=2)
        if source is None:
            stats["failed"] += 1
            continue

        doc = parse_doc(source, page_url)
        links_by_part = {}
        for link in doc.xpath("//table//tr[td]//a[@href]"):
            part_number = clean(link.text_content()).upper()
            if not re.match(r"^[A-Z0-9]{6,}-[A-Z0-9]{2,}(?:-[A-Z0-9]+)?$", part_number, flags=re.I):
                continue
            href = link.get("href") or ""
            if href.lower().startswith("javascript:"):
                links_by_part.setdefault(part_number, None)
            else:
                links_by_part[part_number] = canonical_url(href)

        for row in rows:
            stats["seen"] += 1
            part_number = row["part_number"]
            product_url = links_by_part.get(part_number)
            if not product_url:
                stats["no_card"] += 1
                continue

            card_details = product_card_details(product_url, card_cache)
            card_name = card_details.get("name")
            try:
                raw = json.loads(row["raw_attributes"] or "{}")
            except Exception:
                raw = {}
            raw["product_url"] = product_url

            with conn.cursor() as cur:
                cur.execute(
                    """
                    update part_catalog_items
                    set name_ru=coalesce(%s, name_ru),
                        name_ua=coalesce(%s, name_ua),
                        price_amount=coalesce(%s, price_amount),
                        currency=coalesce(%s, currency),
                        raw_attributes=%s,
                        updated_at=%s
                    where id=%s
                    """,
                    (
                        card_name,
                        card_name,
                        card_details.get("price_amount"),
                        card_details.get("currency"),
                        json.dumps(raw, ensure_ascii=False),
                        datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                        row["id"],
                    ),
                )
            stats["updated"] += 1

        conn.commit()
        stats["pages"] += 1
        if stats["pages"] % 25 == 0:
            print(json.dumps(stats, ensure_ascii=False), flush=True)
        if sleep:
            time.sleep(sleep)

    conn.close()
    return stats


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit-pages", type=int, default=0)
    parser.add_argument("--backfill-cards", action="store_true")
    parser.add_argument("--backfill-cards-by-page", action="store_true")
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--sleep", type=float, default=0.15)
    args = parser.parse_args()
    try:
        if args.backfill_cards_by_page:
            result = backfill_product_cards_by_page(args.limit, args.sleep)
        elif args.backfill_cards:
            result = backfill_product_cards(args.limit, args.sleep)
        else:
            result = import_catalog(args.limit_pages, args.sleep)
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except KeyboardInterrupt:
        sys.exit(130)
