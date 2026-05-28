#!/usr/bin/env python3
"""
Export all SalesPlay products to product.json using cursor pagination.

API:
- Endpoint: https://api.salesplaypos.com/v1.0/products
- Method: GET
- Cursor is sent in the JSON body payload as requested.
"""

from __future__ import annotations

import json
import os
import sys
import time
from typing import Any

import requests

API_URL = "https://api.salesplaypos.com/v1.0/products"
OUTPUT_FILE = "product.json"
PAGE_LIMIT = "50"
REQUEST_TIMEOUT = 30
MAX_PAGES = 5000
MAX_EMPTY_PAGES = 10
HARDCODED_TOKEN = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJsb2dpbl9uYW1lIjoibTQwMDg3MDY5QGdtYWlsLmNvbSIsImRhdGUiOiJGcmlkYXkgMjJuZCAyMDI2ZiBNYXkgMjAyNiAxMjo0ODo1OCBQTSJ9.szInNNklyVpepVMVr9y7-tfmLGRvoyjMrDRHJREpvFo"


def build_payload(cursor: str) -> dict[str, str]:
    return {
        "product_ids": "",
        "created_at_min": "",
        "created_at_max": "",
        "limit": PAGE_LIMIT,
        "cursor": cursor,
    }


def extract_products(response_json: dict[str, Any]) -> list[dict[str, Any]]:
    # Support common response shapes.
    if isinstance(response_json.get("products"), list):
        return response_json["products"]

    data = response_json.get("data")
    if isinstance(data, list):
        return data

    if isinstance(data, dict):
        if isinstance(data.get("products"), list):
            return data["products"]
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


def fetch_page(token: str, cursor: str) -> dict[str, Any]:
    payload = build_payload(cursor)
    headers = {
        "Token": f"Bearer {token}",
        "Content-Type": "application/json",
    }

    # Send GET with JSON body so cursor stays in request body as requested.
    response = requests.request(
        method="GET",
        url=API_URL,
        headers=headers,
        data=json.dumps(payload),
        timeout=REQUEST_TIMEOUT,
    )

    if response.status_code != 200:
        raise RuntimeError(
            f"HTTP {response.status_code} for cursor='{cursor}'. Body: {response.text[:500]}"
        )

    try:
        return response.json()
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Invalid JSON response for cursor='{cursor}'.") from exc


def export_all_products(token: str) -> list[dict[str, Any]]:
    all_products: list[dict[str, Any]] = []
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

        page_products = extract_products(page_json)

        for product in page_products:
            key = str(product.get("id") or product.get("product_code") or "")
            if not key:
                key = json.dumps(product, sort_keys=True)

            if key in seen_keys:
                continue

            seen_keys.add(key)
            all_products.append(product)

        next_cursor = extract_cursor(page_json)
        print(
            f"Page {page}: fetched={len(page_products)} total={len(all_products)} "
            f"next_cursor={'yes' if next_cursor else 'no'}"
        )

        if len(page_products) == 0:
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

    return all_products


def main() -> int:
    token = os.getenv("SALESPLAY_TOKEN", "").strip() or HARDCODED_TOKEN

    if not token:
        print("ERROR: Set SALESPLAY_TOKEN environment variable first.")
        print("PowerShell example:")
        print("  $env:SALESPLAY_TOKEN = 'your_token_here'")
        return 1

    try:
        products = export_all_products(token)
    except Exception as exc:  # noqa: BLE001
        print(f"Export failed: {exc}")
        return 1

    with open(OUTPUT_FILE, "w", encoding="utf-8") as fp:
        json.dump(products, fp, ensure_ascii=False, indent=2)

    print(f"Saved {len(products)} products to {OUTPUT_FILE}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
