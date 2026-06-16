import csv
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urljoin, urlparse
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

BASE_URL = "https://prom.ua"
OUTPUT_DIR = Path("storage/app/prom-lookups")
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
TIMEOUT = 20
SLEEP_SECONDS = 0.35
MAX_WORKERS = 5
MAX_PRODUCTS = 10


def clean(value):
    return re.sub(r"\s+", " ", (value or "").replace("\xa0", " ")).strip()


def normalize_part_number(value):
    return re.sub(r"[^A-Z0-9]", "", (value or "").upper())


def fetch(url, lang):
    req = Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept-Language": "uk,ru;q=0.8,en;q=0.6" if lang == "uk" else "ru,uk;q=0.8,en;q=0.6",
        },
    )
    with urlopen(req, timeout=TIMEOUT) as response:
        return response.geturl(), response.read().decode("utf-8", "replace")


def search_url(part_number, lang):
    prefix = "/ua" if lang == "uk" else ""
    return f"{BASE_URL}{prefix}/search?search_term={quote(part_number)}"


def localized_url(url, lang):
    parsed = urlparse(urljoin(BASE_URL, url))
    path = parsed.path
    if lang == "uk":
        if not path.startswith("/ua/"):
            path = "/ua" + path
    elif path.startswith("/ua/"):
        path = path[3:] or "/"
    return parsed._replace(path=path).geturl()


def extract_balanced_json(text, marker):
    start = text.find(marker)
    if start < 0:
        return None

    brace_start = text.find("{", start + len(marker))
    if brace_start < 0:
        return None

    depth = 0
    in_string = False
    escape = False
    for index in range(brace_start, len(text)):
        char = text[index]
        if in_string:
            if escape:
                escape = False
            elif char == "\\":
                escape = True
            elif char == '"':
                in_string = False
            continue

        if char == '"':
            in_string = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return text[brace_start : index + 1]

    return None


def walk(value):
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def products_from_apollo_state(body):
    raw = extract_balanced_json(body, "window.ApolloCacheState")
    if not raw:
        return []

    try:
        state = json.loads(raw)
    except json.JSONDecodeError:
        return []

    products = []
    seen = set()
    for node in walk(state):
        if node.get("__typename") != "Product":
            continue
        name = clean(node.get("name"))
        if not name:
            continue

        product_id = node.get("id")
        url_text = clean(node.get("urlText"))
        href = ""
        if product_id and url_text:
            href = f"/p{product_id}-{url_text}.html"

        key = (product_id, name)
        if key in seen:
            continue
        seen.add(key)
        products.append(
            {
                "title": name,
                "sku": clean(node.get("sku")),
                "url": href,
            }
        )

    return products


def products_from_markup(body):
    doc = html.fromstring(body)
    products = []
    seen = set()
    for anchor in doc.xpath('//a[@data-qaid="product_link" or @data-qaid="product_name" or contains(@href, ".html")]'):
        title = clean(anchor.text_content())
        href = clean(anchor.get("href"))
        if not title or not href:
            continue
        key = (href, title)
        if key in seen:
            continue
        seen.add(key)
        products.append({"title": title, "sku": "", "url": href})
    return products


def product_matches(product, expected_part_number):
    expected = normalize_part_number(expected_part_number)
    haystack = normalize_part_number(" ".join([product.get("title", ""), product.get("sku", ""), product.get("url", "")]))
    return expected and expected in haystack


def descriptive_title(title, expected_part_number):
    title = clean(title)
    expected = normalize_part_number(expected_part_number)
    normalized_title = normalize_part_number(title)
    if not title or normalized_title == expected:
        return False

    without_part = re.sub(re.escape(expected_part_number), "", title, flags=re.IGNORECASE)
    return clean(without_part) != ""


def lookup_lang(part_number, lang, allow_fuzzy=False):
    url = search_url(part_number, lang)
    final_url, body = fetch(url, lang)
    products = products_from_apollo_state(body) or products_from_markup(body)

    candidates = []
    for index, product in enumerate(products[:MAX_PRODUCTS]):
        matched = product_matches(product, part_number)
        if not matched and not allow_fuzzy:
            continue
        if matched and not descriptive_title(product.get("title"), part_number):
            continue
        candidates.append((not matched, index, product))

    if not candidates:
        return None

    _, _, product = sorted(candidates, key=lambda item: (item[0], item[1], len(item[2]["title"])))[0]
    return {
        "title": product["title"],
        "url": localized_url(product.get("url") or final_url, lang),
        "search_url": final_url or url,
        "matched_numbers": normalize_part_number(part_number) if product_matches(product, part_number) else "",
    }


def lookup_part(part_number, allow_fuzzy=False):
    ru = lookup_lang(part_number, "ru", allow_fuzzy=allow_fuzzy)
    time.sleep(SLEEP_SECONDS)
    uk = lookup_lang(part_number, "uk", allow_fuzzy=allow_fuzzy)

    if not ru and not uk:
        return None

    primary = ru or uk
    secondary = uk or ru
    return {
        "part_number": part_number,
        "name_ru": (ru or secondary)["title"] if ru or secondary else "",
        "name_ua": (uk or primary)["title"] if uk or primary else "",
        "prom_url": (ru or {}).get("url", ""),
        "prom_url_uk": (uk or {}).get("url", ""),
        "search_url": (ru or {}).get("search_url", search_url(part_number, "ru")),
        "search_url_uk": (uk or {}).get("search_url", search_url(part_number, "uk")),
        "matched_numbers": (primary or {}).get("matched_numbers") or (secondary or {}).get("matched_numbers") or "",
    }


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
    path = backup_dir / f"before_apply_prom_names_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

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

    raw["name_source"] = "prom.ua"
    raw["name_source_site"] = "prom.ua"
    raw["name_source_url"] = row.get("prom_url")
    raw["name_source_url_ua"] = row.get("prom_url_uk")
    raw["name_source_search_url"] = row.get("search_url")
    raw["name_source_search_url_uk"] = row.get("search_url_uk")
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
                fields = ["raw_attributes = %s", "updated_at = now()"]
                values = [raw]
                if clean(row.get("name_ru")):
                    fields.insert(0, "name_ru = %s")
                    values.insert(0, row["name_ru"])
                if clean(row.get("name_ua")):
                    fields.insert(0, "name_ua = %s")
                    values.insert(0, row["name_ua"])
                values.append(item["id"])
                cur.execute(f"update part_catalog_items set {', '.join(fields)} where id = %s", values)
                stats["updated_rows"] += cur.rowcount
            if items:
                stats["updated_parts"] += 1
    return stats


def write_csv(path, rows):
    fields = [
        "part_number",
        "name_ru",
        "name_ua",
        "prom_url",
        "prom_url_uk",
        "search_url",
        "search_url_uk",
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
            if clean(row.get("part_number"))
            and (
                descriptive_title(row.get("name_ru"), row.get("part_number"))
                or descriptive_title(row.get("name_ua"), row.get("part_number"))
            )
        ]


def main():
    limit = None
    apply = False
    apply_csv = None
    workers = MAX_WORKERS
    after_part = None
    before_part = None
    part = None
    allow_fuzzy = False

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
        elif arg == "--allow-fuzzy":
            allow_fuzzy = True
        elif arg.startswith("--limit="):
            limit = int(arg.split("=", 1)[1])
        elif arg.isdigit():
            limit = int(arg)
        else:
            raise SystemExit(f"Unknown argument: {arg}")

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    found_path = OUTPUT_DIR / f"prom_found_missing_names_{stamp}.csv"
    errors_path = OUTPUT_DIR / f"prom_errors_missing_names_{stamp}.csv"

    conn = pymysql.connect(**DB, autocommit=False)
    found = []
    pending_apply = []
    errors = []
    applied_stats = {"updated_rows": 0, "updated_parts": 0, "backups": []}
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
                result = lookup_part(row["part_number"], allow_fuzzy=allow_fuzzy)
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
                if result and (result.get("name_ru") or result.get("name_ua")):
                    found_row = {**row, **result}
                    found.append(found_row)
                    pending_apply.append(found_row)
                elif result and result.get("error"):
                    errors.append({**row, **result})

                if index % 50 == 0 or index == len(parts):
                    write_csv(found_path, found)
                    if errors:
                        write_csv(errors_path, errors)
                    if apply and pending_apply:
                        batch_stats = apply_rows(conn, pending_apply)
                        conn.commit()
                        applied_stats["updated_rows"] += batch_stats.get("updated_rows", 0)
                        applied_stats["updated_parts"] += batch_stats.get("updated_parts", 0)
                        if batch_stats.get("backup"):
                            applied_stats["backups"].append(batch_stats["backup"])
                        pending_apply = []
                    elapsed = time.time() - started
                    print(f"{index}/{len(parts)} checked, found {len(found)}, errors {len(errors)}, {elapsed:.1f}s")

        stats = applied_stats if apply else {}
        if not apply:
            conn.rollback()

        print(
            json.dumps(
                {
                    "checked_parts": len(parts),
                    "found_parts": len(found),
                    "errors": len(errors),
                    "applied": apply,
                    "allow_fuzzy": allow_fuzzy,
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
