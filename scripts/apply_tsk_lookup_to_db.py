import csv
import json
import re
import sys
from datetime import datetime
from pathlib import Path

import pymysql


DB = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "sklad_zapchastey",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

CYRILLIC_RE = re.compile(r"[\u0400-\u04FF]")


def blank(value):
    return value is None or str(value).strip() == ""


def load_rows(path):
    with Path(path).open(encoding="utf-8-sig", newline="") as f:
        rows = list(csv.DictReader(f))

    deduped = {}
    for row in rows:
        part_number = (row.get("part_number") or "").strip().upper()
        name = (row.get("name_ru") or "").strip()
        if not part_number or not name:
            continue

        current = deduped.get(part_number)
        if current is None:
            deduped[part_number] = row
            continue

        # Prefer product cards over synthetic EPC source ids, and priced rows over blank price rows.
        row_score = int((row.get("tsk_url") or "").startswith("https://tsk.ua/")) + int(bool(row.get("price_amount")))
        current_score = int((current.get("tsk_url") or "").startswith("https://tsk.ua/")) + int(bool(current.get("price_amount")))
        if row_score > current_score:
            deduped[part_number] = row

    return list(deduped.values())


def chunked(values, size=1000):
    for offset in range(0, len(values), size):
        yield values[offset : offset + size]


def backup_rows(conn, part_numbers):
    backup_dir = Path("database/backups")
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / f"before_apply_tsk_lookup_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

    rows = []
    with conn.cursor() as cur:
        for chunk in chunked(part_numbers):
            placeholders = ",".join(["%s"] * len(chunk))
            cur.execute(
                f"""
                select *
                from part_catalog_items
                where part_number in ({placeholders})
                  and source in ('tsk', 'tesla_official')
                order by part_number, source, id
                """,
                chunk,
            )
            rows.extend(cur.fetchall())

    with path.open("w", encoding="utf-8") as f:
        json.dump(rows, f, ensure_ascii=False, indent=2, default=str)

    return path


def load_tsk_categories(conn):
    by_model = {}
    roots = {}
    with conn.cursor() as cur:
        cur.execute(
            """
            select id, parent_id, depth, name, code, model_label, model_name, year_from, year_to
            from part_catalog_categories
            where source='tsk'
            """
        )
        for row in cur.fetchall():
            model = row["model_label"] or ""
            by_model.setdefault(model, []).append(row)
            if row["depth"] == 0:
                roots[model] = row
    return by_model, roots


def normalized(value):
    return re.sub(r"\s+", " ", (value or "").strip()).lower()


def choose_category(row, categories_by_model, roots):
    model = row.get("model_label") or ""
    categories = categories_by_model.get(model, [])
    root = roots.get(model)

    for key, depth in (("node_name", 3), ("subcategory_name", 2), ("main_category_name", 1)):
        target = normalized(row.get(key))
        if not target:
            continue
        for category in categories:
            if category["depth"] == depth and normalized(category["name"]) == target:
                return category

    for key in ("node_name", "subcategory_name"):
        target = normalized(row.get(key))
        if not target:
            continue
        for category in categories:
            if normalized(category["name"]) == target:
                return category

    return root


def price_value(value):
    value = (value or "").strip()
    if not value:
        return None
    try:
        return f"{float(value.replace(',', '.')):.2f}"
    except ValueError:
        return None


def merge_raw(existing_raw, row):
    if isinstance(existing_raw, str):
        try:
            raw = json.loads(existing_raw)
        except Exception:
            raw = {}
    elif isinstance(existing_raw, dict):
        raw = existing_raw.copy()
    else:
        raw = {}

    tsk_url = (row.get("tsk_url") or "").strip()
    if tsk_url.startswith("https://tsk.ua/"):
        raw["product_url"] = tsk_url
    raw["tsk_lookup_applied_at"] = datetime.now().isoformat(timespec="seconds")
    raw["tsk_lookup_found_via"] = row.get("found_via")
    if row.get("jsonld_name"):
        raw["jsonld_name"] = row["jsonld_name"]
    return json.dumps(raw, ensure_ascii=False)


def main():
    if len(sys.argv) < 2:
        raise SystemExit("Usage: apply_tsk_lookup_to_db.py <csv-path>")

    csv_path = sys.argv[1]
    rows = load_rows(csv_path)
    by_part = {(row["part_number"] or "").strip().upper(): row for row in rows}
    part_numbers = sorted(by_part)

    conn = pymysql.connect(**DB, autocommit=False)
    stats = {
        "csv_rows": len(rows),
        "tsk_updated": 0,
        "tsk_created": 0,
        "official_ru_updated": 0,
        "official_ru_skipped_non_cyrillic": 0,
        "official_ru_skipped_blank": 0,
        "prices_written": 0,
    }

    try:
        backup_path = backup_rows(conn, part_numbers)
        categories_by_model, roots = load_tsk_categories(conn)

        with conn.cursor() as cur:
            for part_number, row in by_part.items():
                name = (row.get("name_ru") or "").strip()
                has_cyrillic = bool(CYRILLIC_RE.search(name))
                tsk_url = (row.get("tsk_url") or "").strip()
                source_url = tsk_url if tsk_url else f"tsk-lookup:{part_number.lower()}"
                price = price_value(row.get("price_amount"))
                currency = (row.get("currency") or "USD").strip()[:3] if price else None
                category = choose_category(row, categories_by_model, roots)
                category_id = category["id"] if category else None
                root = roots.get(row.get("model_label") or "")

                cur.execute(
                    """
                    select *
                    from part_catalog_items
                    where source='tsk' and part_number=%s
                    order by source_url like 'https://tsk.ua/%%' desc, id
                    limit 1
                    """,
                    (part_number,),
                )
                existing = cur.fetchone()

                raw = merge_raw(existing.get("raw_attributes") if existing else None, row)

                if existing:
                    update_fields = [
                        "part_catalog_category_id=%s",
                        "name=%s",
                        "raw_attributes=%s",
                        "updated_at=now()",
                    ]
                    values = [category_id or existing["part_catalog_category_id"], name, raw]

                    if has_cyrillic:
                        update_fields.append("name_ru=%s")
                        values.append(name)
                    if price is not None:
                        update_fields.extend(["price_amount=%s", "currency=%s"])
                        values.extend([price, currency])
                        stats["prices_written"] += 1
                    if source_url.startswith("https://tsk.ua/") and not str(existing["source_url"]).startswith("https://tsk.ua/"):
                        update_fields.append("source_url=%s")
                        values.append(source_url)
                    values.append(existing["id"])
                    cur.execute(
                        f"update part_catalog_items set {', '.join(update_fields)} where id=%s",
                        values,
                    )
                    stats["tsk_updated"] += 1
                else:
                    cur.execute("select id from part_catalog_items where source_url=%s limit 1", (source_url,))
                    if cur.fetchone():
                        source_url = f"tsk-lookup:{part_number.lower()}"

                    cur.execute(
                        """
                        insert into part_catalog_items
                        (part_catalog_category_id, source, source_url, part_number, name, name_en, name_ru, name_ua,
                         scheme_number, price_amount, currency, model_label, model_name, year_from, year_to,
                         main_category_code, main_category_name, subcategory_code, subcategory_name, node_name,
                         compatibility_text, notes_en, notes_ru, notes_ua, `condition`, quality, availability,
                         raw_attributes, source_updated_at, created_at, updated_at)
                        values
                        (%s, 'tsk', %s, %s, %s, null, %s, null,
                         null, %s, %s, %s, %s, %s, %s,
                         null, %s, null, %s, %s,
                         null, null, null, null, null, null, null,
                         %s, now(), now(), now())
                        """,
                        (
                            category_id,
                            source_url,
                            part_number,
                            name,
                            name if has_cyrillic else None,
                            price,
                            currency,
                            row.get("model_label") or (root or {}).get("model_label"),
                            row.get("model_label") or (root or {}).get("model_name"),
                            (root or {}).get("year_from"),
                            (root or {}).get("year_to"),
                            row.get("main_category_name") or (category or {}).get("name"),
                            row.get("subcategory_name"),
                            row.get("node_name"),
                            raw,
                        ),
                    )
                    stats["tsk_created"] += 1
                    if price is not None:
                        stats["prices_written"] += 1

                if not has_cyrillic:
                    stats["official_ru_skipped_non_cyrillic"] += 1
                    continue

                cur.execute(
                    """
                    update part_catalog_items
                    set name_ru=%s, updated_at=now()
                    where source='tesla_official'
                      and part_number=%s
                      and (name_ru is null or trim(name_ru)='')
                    """,
                    (name, part_number),
                )
                stats["official_ru_updated"] += cur.rowcount

        conn.commit()
        stats["backup"] = str(backup_path)
        print(json.dumps(stats, ensure_ascii=False, indent=2))
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
