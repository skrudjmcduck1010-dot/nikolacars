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

BASE_URL = "https://zapchasti.ria.com"
OUTPUT_DIR = Path("storage/app/zapchasti-ria-lookups")
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
TIMEOUT = 20
SLEEP_SECONDS = 0.35
MAX_WORKERS = 6


def clean(value):
    return re.sub(r"\s+", " ", (value or "").replace("\xa0", " ")).strip()


def normalize_part_number(value):
    return re.sub(r"[^A-Z0-9]", "", (value or "").upper())


def blank(value):
    return value is None or str(value).strip() == ""


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


def response_matches_lang(final_url, body, lang):
    path = urlparse(final_url).path
    if lang == "uk":
        return path.startswith("/uk/") or '<html lang="uk"' in body[:1000]

    return not path.startswith("/uk/") and '<html lang="uk"' not in body[:1000]


def localized_path(path, lang):
    if lang == "uk":
        if path.startswith("/uk/"):
            return path
        return "/uk" + path

    if path.startswith("/uk/"):
        return path[3:] or "/"
    return path


def localized_url(url, lang):
    parsed = urlparse(urljoin(BASE_URL, url))
    return parsed._replace(path=localized_path(parsed.path, lang)).geturl()


def search_url(part_number, lang):
    prefix = "/uk" if lang == "uk" else ""
    return f"{BASE_URL}{prefix}/c/zapchasti/?search_text={quote(part_number)}"


def title_without_site_suffix(value):
    value = re.sub(r"\s*\|\s*(?:Купити|Купить).*$", "", clean(value), flags=re.IGNORECASE)
    return clean(value)


def strip_listing_prefix(title, part_number):
    title = clean(title)
    part = re.escape(part_number)
    if re.search(part, title, flags=re.IGNORECASE):
        # Zapchasti RIA seller titles often start with an internal shelf/catalog number:
        # "47 Крепление ..." -> the part name itself starts after that prefix.
        title = re.sub(r"^\d{1,4}\s+(?=[^\d\s])", "", title)
    return clean(title)


def page_part_numbers(doc):
    text = "\n".join(clean(value) for value in doc.xpath("//body//text()") if clean(value))
    values = re.findall(r"\b[A-Z0-9]{6,}[- ][A-Z0-9]{2}[- ][A-Z0-9]\b", text, flags=re.IGNORECASE)
    return {normalize_part_number(value) for value in values if normalize_part_number(value)}


def detail_links(search_body):
    doc = html.fromstring(search_body)
    links = []
    seen = set()
    for anchor in doc.xpath('//a[contains(@href, ".html")]'):
        href = clean(anchor.get("href"))
        text = clean(anchor.text_content())
        if not href or not text:
            continue
        absolute = localized_url(href, "ru")
        if "/c/" in absolute or absolute in seen:
            continue
        seen.add(absolute)
        links.append(absolute)
    return links


def parse_search_card(search_body, expected_part_number, lang):
    doc = html.fromstring(search_body)
    expected = normalize_part_number(expected_part_number)
    candidates = []

    for anchor in doc.xpath('//a[contains(@href, ".html")]'):
        href = clean(anchor.get("href"))
        title = clean(anchor.text_content())
        if not href or not title:
            continue

        normalized_title = normalize_part_number(title)
        if expected and expected not in normalized_title:
            continue

        url = localized_url(href, lang)
        candidates.append(
            {
                "title": strip_listing_prefix(title, expected_part_number),
                "url": url,
                "matched_numbers": expected,
            }
        )

    if not candidates:
        return None

    candidates.sort(key=lambda item: (len(item["title"]), item["title"].lower()))
    return candidates[0]


def parse_detail(url, expected_part_number, lang):
    final_url, body = fetch(localized_url(url, lang), lang)
    doc = html.fromstring(body)
    title = clean(doc.xpath("normalize-space(//h1)"))
    if not title:
        title = title_without_site_suffix(doc.xpath("normalize-space(//title)"))
    if not title:
        return None

    expected = normalize_part_number(expected_part_number)
    numbers = page_part_numbers(doc)
    if expected not in numbers and expected not in normalize_part_number(title):
        return None

    return {
        "title": strip_listing_prefix(title, expected_part_number),
        "url": final_url,
        "matched_numbers": ",".join(sorted(numbers)) if numbers else expected,
    }


def lookup_lang(part_number, lang):
    url = search_url(part_number, lang)
    final_url, body = fetch(url, lang)
    if not response_matches_lang(final_url, body, lang):
        return {
            "error": f"{lang} URL redirected to another language",
            "search_url": final_url or url,
        }

    card = parse_search_card(body, part_number, lang)
    links = detail_links(body)
    if card:
        links.insert(0, card["url"])

    seen = set()
    for link in links[:5]:
        localized = localized_url(link, lang)
        if localized in seen:
            continue
        seen.add(localized)
        try:
            detail = parse_detail(localized, part_number, lang)
        except HTTPError as exc:
            if exc.code == 404:
                continue
            raise
        if detail:
            detail["search_url"] = final_url or url
            return detail

    if card:
        card["search_url"] = final_url or url
        return card

    return None


def lookup_part(part_number, uk_only=False, allow_partial=False):
    ru = None if uk_only else lookup_lang(part_number, "ru")
    if not uk_only:
        time.sleep(SLEEP_SECONDS)
    uk = lookup_lang(part_number, "uk")

    ru_error = (ru or {}).get("error")
    uk_error = (uk or {}).get("error")
    if (ru_error or uk_error) and not allow_partial:
        return {
            "part_number": part_number,
            "error": "; ".join(value for value in (ru_error, uk_error) if value),
            "search_url": (ru or {}).get("search_url"),
            "search_url_uk": (uk or {}).get("search_url"),
        }

    if (not ru or ru_error) and (not uk or uk_error):
        return None

    if (not ru or ru_error or not uk or uk_error) and not allow_partial:
        return {
            "part_number": part_number,
            "error": "Only one language version found",
            "search_url": (ru or {}).get("search_url"),
            "search_url_uk": (uk or {}).get("search_url"),
        }

    primary = ru if ru and not ru_error else uk
    secondary = uk if uk and not uk_error else ru
    return {
        "part_number": part_number,
        "name_ru": "" if not ru or ru_error else ru["title"],
        "name_ua": "" if not uk or uk_error else uk["title"],
        "zapchasti_ria_url": "" if not ru or ru_error else ru["url"],
        "zapchasti_ria_url_uk": "" if not uk or uk_error else uk["url"],
        "search_url": (ru or {}).get("search_url"),
        "search_url_uk": (uk or {}).get("search_url"),
        "matched_numbers": primary.get("matched_numbers") or secondary.get("matched_numbers"),
        "error": "; ".join(value for value in (ru_error, uk_error) if value),
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
    path = backup_dir / f"before_apply_zapchasti_ria_names_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

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

    raw["name_source"] = "zapchasti.ria.com"
    raw["name_source_site"] = "zapchasti.ria.com"
    raw["name_source_url"] = row.get("zapchasti_ria_url")
    raw["name_source_url_ua"] = row.get("zapchasti_ria_url_uk")
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
        "zapchasti_ria_url",
        "zapchasti_ria_url_uk",
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
            if clean(row.get("part_number")) and (clean(row.get("name_ru")) or clean(row.get("name_ua")))
        ]


def main():
    limit = None
    apply = False
    apply_csv = None
    workers = MAX_WORKERS
    after_part = None
    before_part = None
    part = None
    uk_only = False
    allow_partial = False

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
        elif arg == "--uk-only":
            uk_only = True
            allow_partial = True
        elif arg == "--allow-partial":
            allow_partial = True
        elif arg.startswith("--limit="):
            limit = int(arg.split("=", 1)[1])
        elif arg.isdigit():
            limit = int(arg)
        else:
            raise SystemExit(f"Unknown argument: {arg}")

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    found_path = OUTPUT_DIR / f"zapchasti_ria_found_missing_names_{stamp}.csv"
    errors_path = OUTPUT_DIR / f"zapchasti_ria_errors_missing_names_{stamp}.csv"

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
                result = lookup_part(row["part_number"], uk_only=uk_only, allow_partial=allow_partial)
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
