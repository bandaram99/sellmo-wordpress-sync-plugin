#!/usr/bin/env python3
"""
Fetch ALL SalesPlay inventory and write each product/variant's in_stock into the
stock_count / stock_status columns of:
    wp_sellmo_product_mapper   (staging table)
    wp_sellmo_product_mapping  (confirmed Sellmo<->WC links)

Why this is an external script:
The plugin's Sellmo_API::get_inventory() sends the pagination cursor in the URL
query string, which the /inventory endpoint ignores -- the returned cursor never
advances, so the in-plugin sync loops over the first 10 rows forever and never
reaches most products. This script paginates the way the endpoint actually wants:
GET + the cursor inside the JSON body (identical to export_all_inventory_counts.py).

Matching: an inventory row carries product_id (which is the variant id for a
variant row) and product_code, but no separate variant_id field. So a catalog row
is matched when its sellmo_product_id equals the inventory product_id, or its
sellmo_product_code equals the inventory product_code. stock_status is derived:
1 when in_stock > 0, otherwise 0.

Usage:
    python sync_inventory_stock.py
Token is read from the wp_options table (sellmo_wc_api_token); override with the
SALESPLAY_TOKEN environment variable if needed.
"""

from __future__ import annotations

import json
import os
import sys
import time
from typing import Any

import requests
import mysql.connector

# --- API config (mirrors export_all_inventory_counts.py) ---
API_URL = "https://api.salesplaypos.com/v1.0/inventory"
PAGE_LIMIT = "250"
REQUEST_TIMEOUT = 30
MAX_PAGES = 5000
MAX_EMPTY_PAGES = 3
MAX_RETRIES = 3

# --- DB config (XAMPP defaults) ---
DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "wordpress",
}
TABLE_PREFIX = "wp_"
TABLES = [f"{TABLE_PREFIX}sellmo_product_mapper", f"{TABLE_PREFIX}sellmo_product_mapping"]


# =========================================================================
# Inventory fetching (cursor in the JSON body -- the method that advances)
# =========================================================================
def build_payload(cursor: str) -> dict[str, str]:
    return {"product_ids": "", "shop_ids": "", "limit": PAGE_LIMIT, "cursor": cursor}


def extract_inventory_items(response_json: dict[str, Any]) -> list[dict[str, Any]]:
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
    for container in (response_json.get("data"), response_json.get("meta")):
        if isinstance(container, dict):
            for key in ("cursor", "next_cursor", "next_page_cursor"):
                value = container.get(key)
                if isinstance(value, str) and value.strip():
                    return value
    return ""


def fetch_page(token: str, cursor: str) -> dict[str, Any]:
    headers = {"token": f"Bearer {token}", "content-type": "application/json"}
    last_error: Exception | None = None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = requests.request(
                method="GET",
                url=API_URL,
                headers=headers,
                data=json.dumps(build_payload(cursor)),
                timeout=REQUEST_TIMEOUT,
            )
            if response.status_code == 429 and attempt < MAX_RETRIES:
                time.sleep(2 ** attempt)
                continue
            if response.status_code != 200:
                raise RuntimeError(f"HTTP {response.status_code}: {response.text[:300]}")
            return response.json()
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(2 ** attempt)
                continue
            break
    raise RuntimeError(f"Request failed for cursor='{cursor}': {last_error}")


def inventory_key(item: dict[str, Any]) -> str:
    for key in ("id", "inventory_id", "inventory_level_id"):
        value = item.get(key)
        if value not in (None, ""):
            return f"id:{value}"
    return f"product_shop:{item.get('product_code', '')}:{item.get('shop_id', '')}"


def fetch_all_inventory(token: str) -> list[dict[str, Any]]:
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
        page_json = fetch_page(token, cursor)
        page_items = extract_inventory_items(page_json)

        added = 0
        for item in page_items:
            key = inventory_key(item)
            if key in seen_keys:
                continue
            seen_keys.add(key)
            all_items.append(item)
            added += 1

        next_cursor = extract_cursor(page_json)
        print(f"Page {page}: fetched={len(page_items)} added={added} total={len(all_items)}")

        empty_pages = empty_pages + 1 if not page_items else 0
        if empty_pages >= MAX_EMPTY_PAGES:
            print("Stopping: too many consecutive empty pages.")
            break
        if not next_cursor:
            break
        cursor = next_cursor
        time.sleep(0.05)

    return all_items


# =========================================================================
# Database update
# =========================================================================
def get_token(conn) -> str:
    env = os.getenv("SALESPLAY_TOKEN", "").strip()
    if env:
        return env
    cur = conn.cursor()
    cur.execute(
        f"SELECT option_value FROM {TABLE_PREFIX}options WHERE option_name = %s",
        ("sellmo_wc_api_token",),
    )
    row = cur.fetchone()
    cur.close()
    return (row[0] or "").strip() if row else ""


def build_stock_maps(
    items: list[dict[str, Any]]
) -> tuple[dict[str, int], dict[str, int], dict[str, int]]:
    """Return (by_id, by_code, by_parent_code_sum).

    by_parent_code_sum aggregates variant stock under the parent code, so a
    variable product mapped at master level (e.g. "10067", whose inventory only
    lists variants "10067-351", "10067-352", ...) still gets a stock_count equal
    to the sum of its variants. Variant codes follow the "<parent>-<suffix>"
    convention seen in the SalesPlay data.
    """
    by_id: dict[str, int] = {}
    by_code: dict[str, int] = {}
    by_parent_code_sum: dict[str, int] = {}
    for it in items:
        try:
            stock = int(it.get("in_stock"))
        except (TypeError, ValueError):
            continue
        pid = str(it.get("product_id") or "").strip()
        code = str(it.get("product_code") or "").strip()
        if pid:
            by_id[pid] = stock
        if code:
            by_code[code] = stock
            if "-" in code:
                parent = code.rsplit("-", 1)[0]
                by_parent_code_sum[parent] = by_parent_code_sum.get(parent, 0) + stock
    return by_id, by_code, by_parent_code_sum


def update_table(
    conn,
    table: str,
    by_id: dict[str, int],
    by_code: dict[str, int],
    by_parent_code_sum: dict[str, int],
) -> tuple[int, int]:
    read = conn.cursor(dictionary=True)
    read.execute(f"SELECT id, sellmo_product_id, sellmo_product_code FROM {table}")
    rows = read.fetchall()
    read.close()

    write = conn.cursor()
    updated = 0
    print(f"\n=== {table} ({len(rows)} rows) ===")
    for r in rows:
        sid = (r.get("sellmo_product_id") or "").strip()
        code = (r.get("sellmo_product_code") or "").strip()

        stock = by_id.get(sid)
        source = "product_id/variant_id"
        if stock is None and code:
            stock = by_code.get(code)
            source = "product_code"
        if stock is None and code:
            stock = by_parent_code_sum.get(code)
            source = "variant rollup (sum)"

        if stock is None:
            print(f"  row {r['id']:>3}  ({code or sid[:12]+'...'}): no inventory match -> skipped")
            continue

        status = 1 if stock > 0 else 0
        write.execute(
            f"UPDATE {table} SET stock_count = %s, stock_status = %s, updated_at = UTC_TIMESTAMP() WHERE id = %s",
            (stock, status, r["id"]),
        )
        updated += 1
        print(f"  row {r['id']:>3}  ({code or sid[:12]+'...'}): stock_count={stock} stock_status={status}  [matched by {source}]")
    write.close()
    print(f"  -> {updated}/{len(rows)} rows updated")
    return updated, len(rows)


def main() -> int:
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
    except mysql.connector.Error as exc:
        print(f"ERROR: could not connect to MySQL: {exc}")
        return 1

    try:
        token = get_token(conn)
        if not token:
            print("ERROR: No API token. Set SALESPLAY_TOKEN or configure sellmo_wc_api_token.")
            return 1

        print("Fetching all inventory from SalesPlay ...")
        items = fetch_all_inventory(token)
        print(f"\nFetched {len(items)} inventory records total.")

        by_id, by_code, by_parent_code_sum = build_stock_maps(items)
        print(f"Distinct inventory product ids: {len(by_id)} | distinct codes: {len(by_code)} | parent codes (variant rollup): {len(by_parent_code_sum)}")

        grand_updated = 0
        for table in TABLES:
            updated, _ = update_table(conn, table, by_id, by_code, by_parent_code_sum)
            grand_updated += updated

        conn.commit()
        print(f"\nDONE. Committed {grand_updated} row update(s) across {len(TABLES)} table(s).")
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
