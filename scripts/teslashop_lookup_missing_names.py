import csv
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote
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

BASE_URL = "https://teslashop.by"
OUTPUT_DIR = Path("storage/app/teslashop-lookups")
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
TIMEOUT = 20
SLEEP_SECONDS = 0.15
MAX_WORKERS = 8


def clean(value):
    return re.sub(r"\s+", " ", (value or "").replace("\xa0", " ")).strip()


def normalize_part_number(value):
    return re.sub(r"[^A-Z0-9]", "", (value or "").upper())


def fetch(url):
    req = Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept-Language": "ru,be;q=0.8,en;q=0.6",
        },
    )
    with urlopen(req, timeout=TIMEOUT) as response:
        return response.geturl(), response.read().decode("utf-8", "replace")


def card_links(card):
    links = []
    for href in card.xpath('.//a[contains(@href, "/auto-parts/")]/@href'):
        href = clean(href)
        if re.match(r"^/auto-parts/\d+$", href):
            links.append(BASE_URL + href)
    return sorted(set(links))


def card_part_numbers(card):
    values = []
    for text in card.xpath(".//text()"):
        text = clean(text)
        if text:
            values.extend(re.findall(r"\b[A-Z0-9]{6,}[- ][A-Z0-9]{2}[- ][A-Z0-9]\b", text, flags=re.IGNORECASE))
    return {normalize_part_number(value) for value in values if normalize_part_number(value)}


def product_cards(doc):
    cards = doc.xpath('//div[@id="w0"]/div[contains(concat(" ", normalize-space(@class), " "), " card ")]')
    if cards:
        return cards

    # Fallback for small markup changes: keep only cards that contain product detail links.
    seen = set()
    fallback = []
    for card in doc.xpath('//div[contains(concat(" ", normalize-space(@class), " "), " card ")]'):
        links = tuple(card_links(card))
        if not links or links in seen:
            continue
        seen.add(links)
        fallback.append(card)
    return fallback


def parse_card(card, expected_part_number):
    expected = normalize_part_number(expected_part_number)
    numbers = card_part_numbers(card)
    if expected and numbers and expected not in numbers:
        return None

    title = clean(card.xpath('normalize-space(.//div[contains(@class, "card-title")][1])'))
    if not title:
        return None

    model = ""
    title_node = card.xpath('.//div[contains(@class, "card-title")][1]')
    if title_node:
        title_container = title_node[0].getparent()
        if title_container is not None and title_container.tag.lower() == "a":
            sibling = title_container.getnext()
            if sibling is not None:
                model = clean(sibling.text_content())

    name = clean(f"{title} {model}")
    if not name:
        return None

    links = card_links(card)
    return {
        "name_ru": name,
        "name_ua": "",
        "teslashop_url": links[0] if links else "",
        "matched_numbers": ",".join(sorted(numbers)),
    }


def choose_result(results):
    if not results:
        return None

    # Prefer the most common normalized name when several stock cards are returned.
    counts = {}
    by_name = {}
    for result in results:
        key = clean(result["name_ru"]).lower()
        counts[key] = counts.get(key, 0) + 1
        by_name.setdefault(key, result)

    best_key = sorted(counts, key=lambda key: (-counts[key], key))[0]
    return by_name[best_key]


def lookup_part(part_number):
    search_url = f"{BASE_URL}/auto-parts?number={quote(part_number)}"
    final_url, body = fetch(search_url)
    doc = html.fromstring(body)

    results = []
    for card in product_cards(doc):
        result = parse_card(card, part_number)
        if result:
            result["search_url"] = final_url or search_url
            results.append(result)

    result = choose_result(results)
    if result:
        return {
            "part_number": part_number,
            **result,
        }
    return None


def load_missing_parts(conn, limit=None, after_part=None, before_part=None, part=None):
    sql = """
        select
            part_number,
            group_concat(distinct source order by source separator ',') as sources,
            count(*) as rows_count
        from part_catalog_items
        where part_number is not null and part_number <> ''
          and source = 'tesla_official'
          and (name_ru is null or trim(name_ru) = '')
          and (name_ua is null or trim(name_ua) = '')
    """
    params = []
    if part:
        sql += " and part_number = %s"
        params.append(part)
    if after_part:
        sql += " and part_number > %s"
        params.append(after_part)
    if before_part:
        sql += " and part_number <= %s"
        params.append(before_part)

    sql += " group by part_number order by part_number"
    if limit:
        sql += " limit %s"
        params.append(limit)

    with conn.cursor() as cur:
        cur.execute(sql, params or None)
        return cur.fetchall()


def chunked(values, size=1000):
    for offset in range(0, len(values), size):
        yield values[offset : offset + size]


def backup_rows(conn, part_numbers):
    backup_dir = Path("database/backups")
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / f"before_apply_teslashop_names_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

    rows = []
    with conn.cursor() as cur:
        for chunk in chunked(part_numbers):
            placeholders = ",".join(["%s"] * len(chunk))
            cur.execute(
                f"""
                select *
                from part_catalog_items
                where part_number in ({placeholders})
                  and source = 'tesla_official'
                  and (name_ru is null or trim(name_ru) = '')
                  and (name_ua is null or trim(name_ua) = '')
                order by part_number, source, id
                """,
                chunk,
            )
            rows.extend(cur.fetchall())

    with path.open("w", encoding="utf-8") as handle:
        json.dump(rows, handle, ensure_ascii=False, indent=2, default=str)
    return path


def merge_raw(raw_attributes, row):
    if isinstance(raw_attributes, str):
        try:
            raw = json.loads(raw_attributes)
        except Exception:
            raw = {}
    elif isinstance(raw_attributes, dict):
        raw = raw_attributes.copy()
    else:
        raw = {}

    raw["name_source"] = "teslashop.by"
    raw["name_source_site"] = "teslashop.by"
    raw["name_source_url"] = row.get("teslashop_url")
    raw["name_source_search_url"] = row.get("search_url")
    raw["name_source_part_number"] = row.get("part_number")
    raw["name_source_matched_numbers"] = row.get("matched_numbers")
    raw["name_source_applied_at"] = datetime.now().isoformat(timespec="seconds")
    return json.dumps(raw, ensure_ascii=False)


def apply_rows(conn, rows):
    stats = {"updated_rows": 0, "updated_parts": 0}
    if not rows:
        return stats

    backup_path = backup_rows(conn, [row["part_number"] for row in rows])
    stats["backup"] = str(backup_path)

    with conn.cursor() as cur:
        for row in rows:
            cur.execute(
                """
                select id, raw_attributes
                from part_catalog_items
                where part_number = %s
                  and source = 'tesla_official'
                  and (name_ru is null or trim(name_ru) = '')
                  and (name_ua is null or trim(name_ua) = '')
                """,
                (row["part_number"],),
            )
            items = cur.fetchall()
            for item in items:
                raw = merge_raw(item.get("raw_attributes"), row)
                cur.execute(
                    """
                    update part_catalog_items
                    set name_ru = %s,
                        name_ua = %s,
                        raw_attributes = %s,
                        updated_at = now()
                    where id = %s
                    """,
                    (row["name_ru"], clean(row.get("name_ua")) or None, raw, item["id"]),
                )
                stats["updated_rows"] += cur.rowcount
            if items:
                stats["updated_parts"] += 1
    return stats


def write_csv(path, rows):
    fields = [
        "part_number",
        "name_ru",
        "name_ua",
        "teslashop_url",
        "search_url",
        "matched_numbers",
        "sources",
        "rows_count",
        "error",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def load_found_csv(path):
    with Path(path).open(encoding="utf-8-sig", newline="") as handle:
        return [
            row
            for row in csv.DictReader(handle)
            if clean(row.get("part_number")) and clean(row.get("name_ru"))
        ]


def main():
    limit = None
    apply = False
    apply_csv = None
    workers = MAX_WORKERS
    after_part = None
    before_part = None
    part = None

    for arg in sys.argv[1:]:
        if arg == "--apply":
            apply = True
        elif arg.startswith("--apply-csv="):
            apply_csv = arg.split("=", 1)[1]
            apply = True
        elif arg.startswith("--workers="):
            workers = max(1, int(arg.split("=", 1)[1]))
        elif arg.startswith("--after-part="):
            after_part = arg.split("=", 1)[1]
        elif arg.startswith("--before-part="):
            before_part = arg.split("=", 1)[1]
        elif arg.startswith("--part="):
            part = arg.split("=", 1)[1]
        elif arg.startswith("--limit="):
            limit = int(arg.split("=", 1)[1])
        elif arg.isdigit():
            limit = int(arg)
        else:
            raise SystemExit(f"Unknown argument: {arg}")

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    found_path = OUTPUT_DIR / f"teslashop_found_missing_names_{stamp}.csv"
    errors_path = OUTPUT_DIR / f"teslashop_errors_missing_names_{stamp}.csv"

    conn = pymysql.connect(**DB, autocommit=False)
    found = []
    errors = []
    try:
        if apply_csv:
            found = load_found_csv(apply_csv)
            stats = apply_rows(conn, found)
            conn.commit()
            print(json.dumps({"csv": apply_csv, "found_parts": len(found), "applied": True, **stats}, ensure_ascii=False, indent=2))
            return

        parts = load_missing_parts(conn, limit, after_part, before_part, part)
        started = time.time()
        print(f"Loaded {len(parts)} part numbers with blank RU and UA names.")

        def run_lookup(row):
            try:
                time.sleep(SLEEP_SECONDS)
                result = lookup_part(row["part_number"])
            except HTTPError as exc:
                result = {"part_number": row["part_number"], "error": f"HTTP {exc.code}"}
            except (URLError, TimeoutError, OSError) as exc:
                result = {"part_number": row["part_number"], "error": str(exc)}
            except Exception as exc:
                result = {"part_number": row["part_number"], "error": f"{type(exc).__name__}: {exc}"}
            return row, result

        with ThreadPoolExecutor(max_workers=workers) as pool:
            futures = [pool.submit(run_lookup, row) for row in parts]
            for index, future in enumerate(as_completed(futures), 1):
                row, result = future.result()
                if result and result.get("name_ru"):
                    found.append({**row, **result})
                elif result and result.get("error"):
                    errors.append({**row, **result})

                if index % 50 == 0 or index == len(parts):
                    write_csv(found_path, found)
                    if errors:
                        write_csv(errors_path, errors)
                    elapsed = time.time() - started
                    print(f"{index}/{len(parts)} checked, found {len(found)}, errors {len(errors)}, {elapsed:.1f}s")

        stats = {}
        if apply and found:
            stats = apply_rows(conn, found)
            conn.commit()
        else:
            conn.rollback()

        print(
            json.dumps(
                {
                    "checked_parts": len(parts),
                    "found_parts": len(found),
                    "errors": len(errors),
                    "applied": apply,
                    "found_output": str(found_path),
                    "errors_output": str(errors_path) if errors else None,
                    **stats,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
