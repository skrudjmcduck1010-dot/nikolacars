import hashlib
import json
import os
import re
import sys
import time
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import HTTPCookieProcessor, Request, build_opener

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
PLACEHOLDER_STEMS = {"65112127046566", "63823657639696"}
CHALLENGE_COOKIE = "ea711ddd5b297885600ff1df0ef114b145ad0fa0fc6e6d02d637fbc6f4eb4666"


def compact_part_number(value):
    return "".join(ch for ch in (value or "").upper() if ch.isalnum()) or "unknown"


def image_stem(url):
    name = os.path.basename(urlparse(url).path)
    return re.sub(r"\.(?:jpe?g|png|webp)$", "", name, flags=re.I)


def is_placeholder(url):
    return image_stem(url) in PLACEHOLDER_STEMS


def normalize_driveparts_image_url(url):
    normalized = driveparts_image_url_with_size(url, HIGH_RES_SIZE)
    if not normalized:
        return url

    return re.sub(r"\.(?:jpe?g|png)$", ".webp", normalized, flags=re.I)


def driveparts_image_url_with_size(url, size):
    parsed = urlparse(url)
    if "drive-parts.com.ua" not in (parsed.netloc or ""):
        return None

    match = re.search(r"/content/images/(\d+)/[^/]+/([^/?#]+)$", parsed.path, flags=re.I)
    if not match:
        return None

    return f"https://drive-parts.com.ua/content/images/{match.group(1)}/{size}/{match.group(2)}"


def download_candidates(url):
    high_original = driveparts_image_url_with_size(url, HIGH_RES_SIZE)
    preferred_original = driveparts_image_url_with_size(url, "450x600l80mc100")
    values = [
        normalize_driveparts_image_url(url),
        high_original,
        re.sub(r"\.(?:jpe?g|png)$", ".webp", preferred_original, flags=re.I) if preferred_original else None,
        preferred_original,
        url,
    ]
    result = []
    for value in values:
        if value and value not in result:
            result.append(value)
    return result


def unique_image_key(url):
    return image_stem(url) or url


def remote_urls_from_raw(raw):
    values = []
    for key in ("remote_image_urls", "image_urls"):
        current = raw.get(key)
        if isinstance(current, list):
            values.extend(current)
    for key in ("remote_image_url", "image_url"):
        current = raw.get(key)
        if isinstance(current, str):
            values.append(current)

    seen = set()
    result = []
    for value in values:
        if not isinstance(value, str):
            continue
        value = value.strip()
        if not value.startswith(("http://", "https://", "//")):
            continue
        if is_placeholder(value):
            continue

        normalized = normalize_driveparts_image_url(value)
        key = unique_image_key(normalized)
        if key in seen:
            continue
        seen.add(key)
        result.append(normalized)

    return result


def local_urls_from_raw(raw):
    values = []
    current = raw.get("image_urls")
    if isinstance(current, list):
        values.extend(current)
    current = raw.get("image_url")
    if isinstance(current, str):
        values.append(current)

    result = []
    for value in values:
        if not isinstance(value, str):
            continue
        value = value.strip()
        if value and not value.startswith(("http://", "https://", "//")) and value not in result:
            result.append(value)

    return result


def local_path_for(part_number, url):
    parsed = urlparse(url)
    filename = os.path.basename(parsed.path)
    stem, ext = os.path.splitext(filename)
    ext = (ext.lstrip(".") or "webp").lower()
    if ext not in {"jpg", "jpeg", "png", "webp"}:
        ext = "webp"
    digest = hashlib.sha1(url.encode("utf-8")).hexdigest()[:10]
    stem = re.sub(r"[^A-Za-z0-9_-]+", "-", stem).strip("-") or digest
    return f"driveparts/part-images/{compact_part_number(part_number)}/{stem}-{digest}.{ext}"


def fetch_image(opener, url, referer):
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
        "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
        "Referer": referer or "https://drive-parts.com.ua/ru/vsi-tovary/",
        "Cookie": f"challenge_passed={CHALLENGE_COOKIE}",
    }
    request = Request(url, headers=headers)
    with opener.open(request, timeout=8) as response:
        body = response.read()
        content_type = response.headers.get("content-type", "")
    if not content_type.startswith("image/"):
        raise RuntimeError(f"not an image: {content_type}")
    return body


def download_item_images(opener, item):
    raw = json.loads(item["raw_attributes"]) if item.get("raw_attributes") else {}
    remote_urls = remote_urls_from_raw(raw)
    if not remote_urls:
        return raw, [], [], 0, 0

    local_urls = []
    saved_remote_urls = []
    downloaded = 0
    failed = 0
    referer = item.get("source_url") or "https://drive-parts.com.ua/ru/vsi-tovary/"

    for url in remote_urls:
        saved_local = None
        saved_remote = None
        for candidate in download_candidates(url):
            local = local_path_for(item.get("part_number"), candidate)
            path = PUBLIC_STORAGE / local
            path.parent.mkdir(parents=True, exist_ok=True)

            if path.exists() and path.stat().st_size > 0:
                saved_local = local
                saved_remote = candidate
                break

            try:
                path.write_bytes(fetch_image(opener, candidate, referer))
                downloaded += 1
                saved_local = local
                saved_remote = candidate
                break
            except Exception as exc:
                continue

        if saved_local and saved_remote:
            local_urls.append(saved_local)
            saved_remote_urls.append(saved_remote)
            continue

        failed += 1
        print(f"FAILED item={item['id']} url={url} error=all candidates failed", flush=True)

    if local_urls:
        raw["image_url"] = local_urls[0]
        raw["image_urls"] = local_urls
        raw["remote_image_urls"] = saved_remote_urls
        raw.pop("remote_image_url", None)

    return raw, remote_urls, local_urls, downloaded, failed


def main():
    limit = int(sys.argv[1]) if len(sys.argv) > 1 else 0
    sleep_ms = int(sys.argv[2]) if len(sys.argv) > 2 else 50
    start_id = int(sys.argv[3]) if len(sys.argv) > 3 else 0

    opener = build_opener(HTTPCookieProcessor(CookieJar()))
    conn = pymysql.connect(**DB_CONFIG, autocommit=False)
    totals = {
        "seen": 0,
        "with_remote": 0,
        "updated": 0,
        "images_downloaded": 0,
        "images_available": 0,
        "image_failures": 0,
    }

    try:
        with conn.cursor() as cursor:
            sql = """
                select id, source_url, part_number, raw_attributes
                from part_catalog_items
                where source=%s
                    and id >= %s
                order by id
            """
            if limit > 0:
                sql += f" limit {limit}"
            cursor.execute(sql, (SOURCE, start_id))
            items = cursor.fetchall()

        for item in items:
            totals["seen"] += 1
            try:
                existing_raw = json.loads(item["raw_attributes"]) if item.get("raw_attributes") else {}
            except Exception:
                existing_raw = {}
            if len(local_urls_from_raw(existing_raw)) >= len(remote_urls_from_raw(existing_raw)):
                continue

            raw, remote_urls, local_urls, downloaded, failed = download_item_images(opener, item)
            totals["images_downloaded"] += downloaded
            totals["image_failures"] += failed

            if remote_urls:
                totals["with_remote"] += 1
            if local_urls:
                totals["images_available"] += len(local_urls)

                with conn.cursor() as cursor:
                    cursor.execute(
                        "update part_catalog_items set raw_attributes=%s, updated_at=now() where id=%s",
                        (json.dumps(raw, ensure_ascii=False), item["id"]),
                    )
                conn.commit()
                totals["updated"] += 1

            if totals["seen"] % 50 == 0 or downloaded > 0:
                print(json.dumps({"item": item["id"], **totals}, ensure_ascii=False), flush=True)

            if sleep_ms > 0:
                time.sleep(sleep_ms / 1000)
    finally:
        conn.close()

    print(json.dumps({"done": True, **totals}, ensure_ascii=False, indent=2), flush=True)


if __name__ == "__main__":
    main()
