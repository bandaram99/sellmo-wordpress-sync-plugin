#!/usr/bin/env python3
"""
Export all SalesPlay inventory data to a single JSON file using cursor pagination.

API:
- Endpoint: https://api.salesplaypos.com/v1.0/inventory
- Method: GET
- Cursor is sent in the JSON body payload.
"""

from __future__ import annotations

import json
import os
import sys
import time
from typing import Any

import requests

API_URL = "https://api.salesplaypos.com/v1.0/inventory"
OUTPUT_FILE = "inventory_all.json"
PAGE_LIMIT = "250"
REQUEST_TIMEOUT = 30
MAX_PAGES = 5000
MAX_EMPTY_PAGES = 3
MAX_RETRIES = 3

# Prefer SALESPLAY_TOKEN env var. Keep empty by default for safety.
HARDCODED_TOKEN = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJsb2dpbl9uYW1lIjoibTQwMDg3MDY5QGdtYWlsLmNvbSIsImRhdGUiOiJGcmlkYXkgMjJuZCAyMDI2ZiBNYXkgMjAyNiAxMjo0ODo1OCBQTSJ9.szInNNklyVpepVMVr9y7-tfmLGRvoyjMrDRHJREpvFo"


def build_payload(cursor: str, shop_ids: str = "", product_ids: str = "") -> dict[str, str]:
    return {
        "product_ids": product_ids,
        "shop_ids": shop_ids,
        "limit": PAGE_LIMIT,
        "cursor": cursor,
    }


def extract_inventory_items(response_json: dict[str, Any]) -> list[dict[str, Any]]:
    # Common shapes from SalesPlay inventory endpoint.
    if isinstance(response_json.get("inventory_levels"), list):
        return response_json["inventory_levels"]

    data = response_json.get("data")
    if isinstance(data, list):
        return data

    if isinstance(data, dict):
        if isinstance(data.get("inventory_levels"), list):
            return data["inventory_levels"]
        if isinstance(data.get("items"), list):
            return data["items"]

    return []


def extract_cursor(response_json: dict[str, Any]) -> str:
    for key in ("cursor", "next_cursor", "next_page_cursor"):
        value = response_json.get(key)
        if isinstance(value, str) and value.strip():
            return value

    data = response_json.get("data")
    if isinstance(data, dict):
        for key in ("cursor", "next_cursor", "next_page_cursor"):
            value = data.get(key)
            if isinstance(value, str) and value.strip():
                return value

    meta = response_json.get("meta")
    if isinstance(meta, dict):
        for key in ("cursor", "next_cursor", "next_page_cursor"):
            value = meta.get(key)
            if isinstance(value, str) and value.strip():
                return value

    return ""


def fetch_page(token: str, cursor: str, shop_ids: str = "", product_ids: str = "") -> dict[str, Any]:
    payload = build_payload(cursor, shop_ids=shop_ids, product_ids=product_ids)
    headers = {
        "token": f"Bearer {token}",
        "content-type": "application/json",
    }

    last_error: Exception | None = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            # Keep GET + JSON body as required by this API usage.
            response = requests.request(
                method="GET",
                url=API_URL,
                headers=headers,
                data=json.dumps(payload),
                timeout=REQUEST_TIMEOUT,
            )

            if response.status_code == 429 and attempt < MAX_RETRIES:
                time.sleep(2 ** attempt)
                continue

            if response.status_code != 200:
                raise RuntimeError(
                    f"HTTP {response.status_code} for cursor='{cursor}'. Body: {response.text[:500]}"
                )

            return response.json()

        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Invalid JSON response for cursor='{cursor}'.") from exc
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(2 ** attempt)
                continue
            break

    raise RuntimeError(f"Request failed for cursor='{cursor}': {last_error}")


def inventory_key(item: dict[str, Any]) -> str:
    # Use a stable unique key for dedupe across pages.
    for key in ("id", "inventory_id", "inventory_level_id"):
        value = item.get(key)
        if value not in (None, ""):
            return f"id:{value}"

    product_code = item.get("product_code", "")
    shop_id = item.get("shop_id", "")
    if product_code or shop_id:
        return f"product_shop:{product_code}:{shop_id}"

    return "raw:" + json.dumps(item, sort_keys=True, ensure_ascii=False)


def export_all_inventory(token: str, shop_ids: str = "", product_ids: str = "") -> list[dict[str, Any]]:
    all_items: list[dict[str, Any]] = []
    seen_keys: set[str] = set()
    seen_cursors: set[str] = set()

    cursor = ""
    page = 0
    empty_pages = 0

    while page < MAX_PAGES:
        if cursor and cursor in seen_cursors:
            print("Stopping: cursor cycle detected.")
            break

        if cursor:
            seen_cursors.add(cursor)

        page += 1
        page_json = fetch_page(token, cursor, shop_ids=shop_ids, product_ids=product_ids)
        page_items = extract_inventory_items(page_json)

        added_this_page = 0
        for item in page_items:
            key = inventory_key(item)
            if key in seen_keys:
                continue
            seen_keys.add(key)
            all_items.append(item)
            added_this_page += 1

        next_cursor = extract_cursor(page_json)
        print(
            f"Page {page}: fetched={len(page_items)} added={added_this_page} "
            f"total={len(all_items)} next_cursor={'yes' if next_cursor else 'no'}"
        )

        if len(page_items) == 0:
            empty_pages += 1
        else:
            empty_pages = 0

        if empty_pages >= MAX_EMPTY_PAGES:
            print("Stopping: too many consecutive empty pages.")
            break

        if not next_cursor:
            break

        cursor = next_cursor
        time.sleep(0.05)

    return all_items


def main() -> int:
    token = os.getenv("SALESPLAY_TOKEN", "").strip() or HARDCODED_TOKEN
    shop_ids = os.getenv("SALESPLAY_SHOP_IDS", "").strip()
    product_ids = os.getenv("SALESPLAY_PRODUCT_IDS", "").strip()

    if not token:
        print("ERROR: Set SALESPLAY_TOKEN environment variable first.")
        print("PowerShell example:")
        print("  $env:SALESPLAY_TOKEN = 'your_token_here'")
        return 1

    try:
        items = export_all_inventory(token, shop_ids=shop_ids, product_ids=product_ids)
    except Exception as exc:  # noqa: BLE001
        print(f"Export failed: {exc}")
        return 1

    with open(OUTPUT_FILE, "w", encoding="utf-8") as fp:
        json.dump(items, fp, ensure_ascii=False, indent=2)

    print(f"Saved {len(items)} inventory records to {OUTPUT_FILE}")
    return 0


if __name__ == "__main__":
    sys.exit(main())