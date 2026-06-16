import concurrent.futures
import hashlib
import json
import os
import re
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import Request, build_opener

import pymysql


DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 3306,
    "user": "root",
    "password": "",
    "database": "sklad_zapchastey",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

PUBLIC_STORAGE = Path("storage/app/public")
SOURCE = "driveparts"
HIGH_RES_SIZE = "1280x1706l80mc100"
PREFERRED_SIZE = "450x600l80mc100"
PLACEHOLDER_STEMS = {"65112127046566", "63823657639696"}
CHALLENGE_COOKIE = "ea711ddd5b297885600ff1df0ef114b145ad0fa0fc6e6d02d637fbc6f4eb4666"
MAX_WORKERS = 24


def compact_part_number(value):
    return "".join(ch for ch in (value or "").upper() if ch.isalnum()) or "unknown"


def remote_stem(url):
    name = os.path.basename(urlparse(str(url)).path)
    return re.sub(r"\.(?:jpe?g|png|webp)$", "", name, flags=re.I)


def local_stem(path):
    name = os.path.basename(str(path))
    stem = re.sub(r"\.(?:jpe?g|png|webp)$", "", name, flags=re.I)
    return re.sub(r"-[0-9a-f]{10}$", "", stem, flags=re.I)


def driveparts_image_url_with_size(url, size):
    parsed = urlparse(url)
    if "drive-parts.com.ua" not in (parsed.netloc or ""):
        return None
    match = re.search(r"/content/images/(\d+)/[^/]+/([^/?#]+)$", parsed.path, flags=re.I)
    if not match:
        return None
    return f"https://drive-parts.com.ua/content/images/{match.group(1)}/{size}/{match.group(2)}"


def normalize_high(url):
    high = driveparts_image_url_with_size(url, HIGH_RES_SIZE)
    if not high:
        return url
    return re.sub(r"\.(?:jpe?g|png)$", ".webp", high, flags=re.I)


def candidates(url):
    high = driveparts_image_url_with_size(url, HIGH_RES_SIZE)
    preferred = driveparts_image_url_with_size(url, PREFERRED_SIZE)
    values = [
        normalize_high(url),
        high,
        re.sub(r"\.(?:jpe?g|png)$", ".webp", preferred, flags=re.I) if preferred else None,
        preferred,
        url,
    ]
    result = []
    for value in values:
        if value and value not in result:
            result.append(value)
    return result


def local_path_for(part_number, url):
    filename = os.path.basename(urlparse(url).path)
    stem, ext = os.path.splitext(filename)
    ext = (ext.lstrip(".") or "webp").lower()
    if ext not in {"jpg", "jpeg", "png", "webp"}:
        ext = "webp"
    digest = hashlib.sha1(url.encode("utf-8")).hexdigest()[:10]
    stem = re.sub(r"[^A-Za-z0-9_-]+", "-", stem).strip("-") or digest
    return f"driveparts/part-images/{compact_part_number(part_number)}/{stem}-{digest}.{ext}"


def raw_remote_urls(raw):
    values = []
    for key in ("remote_image_urls", "image_urls"):
        current = raw.get(key)
        if isinstance(current, list):
            values.extend(current)
    for key in ("remote_image_url", "image_url"):
        current = raw.get(key)
        if isinstance(current, str):
            values.append(current)

    result = {}
    for value in values:
        if not isinstance(value, str) or not value.startswith(("http://", "https://", "//")):
            continue
        stem = remote_stem(value)
        if stem in PLACEHOLDER_STEMS or not stem:
            continue
        result.setdefault(stem, normalize_high(value))
    return result


def raw_local_urls(raw):
    values = []
    current = raw.get("image_urls")
    if isinstance(current, list):
        values.extend(current)
    current = raw.get("image_url")
    if isinstance(current, str):
        values.append(current)

    result = {}
    for value in values:
        if not isinstance(value, str) or not value or value.startswith(("http://", "https://", "//")):
            continue
        stem = local_stem(value)
        if stem:
            result.setdefault(stem, value)
    return result


def fetch_image(url, referer):
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
        "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
        "Referer": referer or "https://drive-parts.com.ua/ru/vsi-tovary/",
        "Cookie": f"challenge_passed={CHALLENGE_COOKIE}",
    }
    opener = build_opener()
    with opener.open(Request(url, headers=headers), timeout=8) as response:
        body = response.read()
        content_type = response.headers.get("content-type", "")
    if not content_type.startswith("image/"):
        raise RuntimeError(content_type)
    return body


def download_one(task):
    item_id, part_number, referer, url = task
    for candidate in candidates(url):
        local = local_path_for(part_number, candidate)
        path = PUBLIC_STORAGE / local
        path.parent.mkdir(parents=True, exist_ok=True)
        if path.exists() and path.stat().st_size > 0:
            return item_id, remote_stem(url), candidate, local, True
        try:
            path.write_bytes(fetch_image(candidate, referer))
            return item_id, remote_stem(url), candidate, local, True
        except Exception:
            continue
    return item_id, remote_stem(url), url, None, False


def main():
    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                """
                select id, source_url, part_number, raw_attributes
                from part_catalog_items
                where source=%s
                order by id
                """,
                (SOURCE,),
            )
            rows = cursor.fetchall()

        items = {}
        tasks = []
        for row in rows:
            try:
                raw = json.loads(row["raw_attributes"]) if row.get("raw_attributes") else {}
            except Exception:
                raw = {}
            remotes = raw_remote_urls(raw)
            locals_ = raw_local_urls(raw)
            if not remotes:
                continue
            missing = {stem: url for stem, url in remotes.items() if stem not in locals_}
            if not missing:
                continue
            items[row["id"]] = {
                "row": row,
                "raw": raw,
                "remotes": remotes,
                "locals": locals_,
                "success_remote": {},
            }
            for url in missing.values():
                tasks.append((row["id"], row["part_number"], row["source_url"], url))

        print(json.dumps({"items_with_missing": len(items), "missing_images": len(tasks)}, ensure_ascii=False), flush=True)

        done = 0
        failed = 0
        with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
            for item_id, stem, remote_url, local_url, ok in executor.map(download_one, tasks):
                done += 1
                if ok and local_url:
                    items[item_id]["locals"][stem] = local_url
                    items[item_id]["success_remote"][stem] = remote_url
                else:
                    failed += 1
                if done % 100 == 0:
                    print(json.dumps({"done": done, "failed": failed}, ensure_ascii=False), flush=True)

        updated = 0
        with conn.cursor() as cursor:
            for item_id, payload in items.items():
                raw = payload["raw"]
                local_by_stem = payload["locals"]
                remote_by_stem = payload["remotes"]

                kept_stems = [stem for stem in remote_by_stem if stem in local_by_stem]
                if not kept_stems:
                    continue
                raw["image_urls"] = [local_by_stem[stem] for stem in kept_stems]
                raw["image_url"] = raw["image_urls"][0]
                raw["remote_image_urls"] = [
                    payload["success_remote"].get(stem, remote_by_stem[stem])
                    for stem in kept_stems
                ]
                raw.pop("remote_image_url", None)
                cursor.execute(
                    "update part_catalog_items set raw_attributes=%s, updated_at=now() where id=%s",
                    (json.dumps(raw, ensure_ascii=False), item_id),
                )
                updated += 1
        conn.commit()
        print(json.dumps({"updated_items": updated, "attempted_images": done, "failed_images": failed}, ensure_ascii=False, indent=2), flush=True)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
