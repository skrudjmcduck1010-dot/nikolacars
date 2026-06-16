import csv
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote, unquote, urljoin, urlparse
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

BASE_URL = "https://aleto.ua"
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
OUTPUT_DIR = Path("storage/app/aleto-lookups")
TIMEOUT = 20
SLEEP_SECONDS = 0.35
SITEMAP_INDEX_URL = f"{BASE_URL}/sitemap.xml"
MAX_WORKERS = 8


def normalize_part_number(value):
    return re.sub(r"[^A-Z0-9]", "", (value or "").upper())


def clean(value):
    return re.sub(r"\s+", " ", (value or "").strip())


def blank(value):
    return value is None or str(value).strip() == ""


def fetch(url, accept_language="ru,en;q=0.8", visit="RU"):
    req = Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept-Language": accept_language,
            "Cookie": f"visit={visit}",
        },
    )
    with urlopen(req, timeout=TIMEOUT) as response:
        body = response.read().decode("utf-8", "replace")
        return response.geturl(), body


def ru_url(url):
    parsed = urlparse(url)
    path = parsed.path
    if path.startswith("/ua/detail/"):
        path = path.replace("/ua/detail/", "/detail/", 1)
    return parsed._replace(path=path).geturl()


def uk_url(url):
    parsed = urlparse(url)
    path = parsed.path
    if path.startswith("/detail/"):
        path = path.replace("/detail/", "/ua/detail/", 1)
    elif not path.startswith("/ua/detail/"):
        path = "/ua" + path
    return parsed._replace(path=path).geturl()


def detail_links(search_body):
    doc = html.fromstring(search_body)
    links = []
    seen = set()
    for anchor in doc.xpath('//a[contains(@href, "/detail/") or contains(@href, "/ua/detail/")]'):
        href = anchor.get("href")
        if not href:
            continue
        absolute = urljoin(BASE_URL, href)
        canonical = ru_url(absolute)
        if canonical in seen:
            continue
        seen.add(canonical)
        links.append(canonical)
    return links


def sitemap_locations():
    _, body = fetch(SITEMAP_INDEX_URL)
    doc = html.fromstring(body.encode("utf-8"))
    links = doc.xpath("//*[local-name()='sitemap']/*[local-name()='loc']/text()")
    return [link for link in links if "sitemap-products" in link]


def scan_sitemaps(parts):
    targets = {normalize_part_number(row["part_number"]): row["part_number"] for row in parts}
    candidates = {}
    if not targets:
        return candidates

    for sitemap_url in sitemap_locations():
        _, body = fetch(sitemap_url)
        for block in re.findall(r"<url>\s*(.*?)\s*</url>", body, flags=re.IGNORECASE | re.DOTALL):
            loc_match = re.search(r"<loc>(.*?)</loc>", block, flags=re.IGNORECASE | re.DOTALL)
            if not loc_match:
                continue
            detail_url = clean(loc_match.group(1))
            if "/detail/" not in detail_url:
                continue

            block_targets = set()
            token_text = re.sub(r"[^A-Z0-9]+", " ", unquote(block).upper())
            for token in re.findall(r"\b[A-Z0-9]{6,}\b", token_text):
                normalized = normalize_part_number(token)
                if normalized in targets:
                    block_targets.add(targets[normalized])

            for part_number in block_targets:
                candidates.setdefault(part_number, [])
                canonical = ru_url(detail_url)
                if canonical not in candidates[part_number]:
                    candidates[part_number].append(canonical)

        print(f"Scanned {sitemap_url}, candidate parts so far {len(candidates)}")
    return candidates


def page_part_numbers(doc):
    values = []
    labels = (
        "Ориг. номер",
        "Оригинальный номер",
        "Номер виробника",
        "Номер производителя",
        "Код",
    )
    text = "\n".join(clean(value) for value in doc.xpath("//body//text()") if clean(value))
    main_text = re.split(
        r"\b(?:Кроссы|Кроси|Аналоги|Інші запчастини|Другие запчасти|Гарантии|Гарантії)\b",
        text,
        maxsplit=1,
        flags=re.IGNORECASE,
    )[0]
    for label in labels:
        pattern = rf"{re.escape(label)}\s+([A-ZА-ЯІЇЄҐ0-9][A-ZА-ЯІЇЄҐ0-9\- ]{{4,}})"
        for match in re.finditer(pattern, main_text, flags=re.IGNORECASE):
            value = clean(match.group(1))
            value = re.split(r"\s{2,}| Виробник | Производитель | Наличие | Наявність | Код ", value)[0]
            values.append(value)

    for token in re.findall(r"\b[A-Z0-9]{6,}[- ]?[A-Z0-9]{2}[- ]?[A-Z0-9]\b", main_text, flags=re.IGNORECASE):
        values.append(token)
    return {normalize_part_number(value) for value in values if normalize_part_number(value)}


def parse_detail(url, expected_part_number, lang):
    final_url, body = fetch(
        url,
        "uk,ru;q=0.8,en;q=0.6" if lang == "uk" else "ru,uk;q=0.8,en;q=0.6",
        "UA" if lang == "uk" else "RU",
    )
    doc = html.fromstring(body)
    title = clean(doc.xpath("normalize-space(//h1)"))
    if not title:
        return None

    expected = normalize_part_number(expected_part_number)
    numbers = page_part_numbers(doc)
    if expected not in numbers and expected not in normalize_part_number(title):
        return None

    return {
        "url": final_url,
        "title": title,
        "matched_numbers": sorted(numbers),
    }


def lookup_part(part_number, candidate_links=None):
    query = quote(part_number)
    search_url = f"{BASE_URL}/search/?q={query}"
    if candidate_links:
        links = candidate_links
    else:
        final_url, body = fetch(search_url)
        links = detail_links(body)

    for link in links:
        ru = None
        uk = None
        try:
            ru = parse_detail(ru_url(link), part_number, "ru")
            time.sleep(SLEEP_SECONDS)
            uk = parse_detail(uk_url(link), part_number, "uk")
            time.sleep(SLEEP_SECONDS)
        except HTTPError as exc:
            if exc.code == 404:
                continue
            return {"part_number": part_number, "error": f"HTTP {exc.code}", "search_url": search_url}
        except (URLError, TimeoutError, OSError) as exc:
            return {"part_number": part_number, "error": str(exc), "search_url": search_url}

        if ru or uk:
            return {
                "part_number": part_number,
                "name_ru": (ru or uk)["title"],
                "name_ua": (uk or ru)["title"],
                "aleto_url": (ru or uk)["url"],
                "aleto_url_uk": (uk or {}).get("url"),
                "search_url": search_url,
                "matched_numbers": ",".join((ru or uk).get("matched_numbers", [])),
            }

    return None


def load_missing_parts(conn, limit=None, after_part=None, before_part=None):
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
        group by part_number
    """
    params = []
    if after_part:
        sql = sql.replace("group by part_number", "and part_number > %s\ngroup by part_number")
        params.append(after_part)
    if before_part:
        sql = sql.replace("group by part_number", "and part_number <= %s\ngroup by part_number")
        params.append(before_part)
    sql += " order by part_number"
    if limit:
        sql += " limit %s"
        params.append(limit)
    with conn.cursor() as cur:
        cur.execute(sql, params or None)
        return cur.fetchall()


def backup_rows(conn, part_numbers):
    backup_dir = Path("database/backups")
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / f"before_apply_aleto_names_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    rows = []
    with conn.cursor() as cur:
        for offset in range(0, len(part_numbers), 1000):
            chunk = part_numbers[offset : offset + 1000]
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

    raw["name_source"] = "aleto.ua"
    raw["name_source_site"] = "aleto.ua"
    raw["name_source_url"] = row["aleto_url"]
    raw["name_source_search_url"] = row["search_url"]
    raw["name_source_part_number"] = row["part_number"]
    raw["name_source_applied_at"] = datetime.now().isoformat(timespec="seconds")
    if row.get("aleto_url_uk"):
        raw["name_source_url_ua"] = row["aleto_url_uk"]
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
                    (row["name_ru"], row["name_ua"], raw, item["id"]),
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
        "aleto_url",
        "aleto_url_uk",
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
            if clean(row.get("part_number")) and clean(row.get("name_ru")) and clean(row.get("name_ua")) and clean(row.get("aleto_url"))
        ]


def main():
    limit = None
    apply = False
    apply_csv = None
    use_sitemap = False
    search_missing = False
    workers = MAX_WORKERS
    after_part = None
    before_part = None
    for arg in sys.argv[1:]:
        if arg == "--apply":
            apply = True
        elif arg.startswith("--apply-csv="):
            apply_csv = arg.split("=", 1)[1]
            apply = True
        elif arg == "--no-sitemap":
            use_sitemap = False
        elif arg == "--sitemap":
            use_sitemap = True
        elif arg == "--search-missing":
            search_missing = True
        elif arg.startswith("--workers="):
            workers = max(1, int(arg.split("=", 1)[1]))
        elif arg.startswith("--after-part="):
            after_part = arg.split("=", 1)[1]
        elif arg.startswith("--before-part="):
            before_part = arg.split("=", 1)[1]
        elif arg.startswith("--limit="):
            limit = int(arg.split("=", 1)[1])
        elif arg.isdigit():
            limit = int(arg)
        else:
            raise SystemExit(f"Unknown argument: {arg}")

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    found_path = OUTPUT_DIR / f"aleto_found_missing_names_{stamp}.csv"
    errors_path = OUTPUT_DIR / f"aleto_errors_missing_names_{stamp}.csv"

    conn = pymysql.connect(**DB, autocommit=False)
    found = []
    errors = []
    try:
        if apply_csv:
            found = load_found_csv(apply_csv)
            stats = apply_rows(conn, found)
            conn.commit()
            print(
                json.dumps(
                    {
                        "csv": apply_csv,
                        "found_parts": len(found),
                        "applied": True,
                        **stats,
                    },
                    ensure_ascii=False,
                    indent=2,
                )
            )
            return

        parts = load_missing_parts(conn, limit, after_part, before_part)
        started = time.time()
        print(f"Loaded {len(parts)} part numbers with blank RU and UA names.")

        candidates = scan_sitemaps(parts) if use_sitemap else {}
        if use_sitemap and not search_missing:
            parts_to_check = [row for row in parts if row["part_number"] in candidates]
        else:
            parts_to_check = parts

        print(f"Checking {len(parts_to_check)} part numbers against Aleto detail pages.")

        def run_lookup(row):
            part_number = row["part_number"]
            try:
                result = lookup_part(part_number, candidates.get(part_number))
            except HTTPError as exc:
                result = {"part_number": part_number, "error": f"HTTP {exc.code}"}
            except (URLError, TimeoutError, OSError) as exc:
                result = {"part_number": part_number, "error": str(exc)}
            except Exception as exc:
                result = {"part_number": part_number, "error": f"{type(exc).__name__}: {exc}"}
            return row, result

        with ThreadPoolExecutor(max_workers=workers) as pool:
            futures = [pool.submit(run_lookup, row) for row in parts_to_check]
            for index, future in enumerate(as_completed(futures), 1):
                row, result = future.result()

                if result and result.get("name_ru"):
                    found.append({**row, **result})
                elif result and result.get("error"):
                    errors.append({**row, **result})

                if index % 50 == 0 or index == len(parts_to_check):
                    write_csv(found_path, found)
                    if errors:
                        write_csv(errors_path, errors)
                    elapsed = time.time() - started
                    print(f"{index}/{len(parts_to_check)} checked, found {len(found)}, errors {len(errors)}, {elapsed:.1f}s")

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
                    "checked_detail_pages_for_parts": len(parts_to_check),
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
