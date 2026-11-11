# Architecture Audit & Refactoring Plan

**Workspace:** `c:\xampp3\htdocs\sellmo-wordpress-sync-plugin`
**Audited:** 2026-08-12 · branch `Build-plugin-complete`
**Deployed copy:** `c:\xampp3\htdocs\wordpress\wp-content\plugins\salespay-woocommerce`

---

## 0. Executive summary

The plugin is **healthier than its size suggests**. It already has
`declare(strict_types=1)` in every file, typed signatures throughout, a
consistent docblock standard, a centralised HTTP client, a failure guard
(`Sellmo_Sync_Guard`), and nonce + capability checks on every reachable AJAX
endpoint. That is a better baseline than most WordPress plugins of this size.

The real problem is **not** structure — it is **volume**. Roughly **1,300 lines
of the ~7,900 lines of PHP in `includes/` are unreachable**: two superseded sync
engines and four AJAX endpoints with no caller anywhere in the codebase. They
still load on every request, still duplicate business rules that the live code
also implements, and still register a public attack surface.

> **Recommendation:** the highest-value work is deletion, not restructuring.
> Phases 1–2 below remove dead code and collapse duplicated helpers — that is
> ~90% of the benefit at ~10% of the risk. Phases 3–4 (splitting the admin god
> class) are genuine but optional improvements. Phase 5 is a performance idea
> that should only be taken if measurements justify it.
>
> Deliberately **rejected**: PSR-4/Composer autoloading, namespaces, a DI
> container, and `src/Domain|Application|Infrastructure` layering. All four would
> break the `{{PLACEHOLDER}}` build templating and the `class-*.php` file
> convention that `build.php` and WordPress.org review both depend on, in
> exchange for no behavioural gain. See §5.

---

## 1. Current State Analysis

### 1.1 Project structure

```
sellmo-wordpress-sync-plugin/
├── sellmo-woocommerce.php          # Entry point. Templated header, constants,
│                                   # activation/deactivation, WC guard, bootstrap
├── uninstall.php                   # Calls Sellmo_Cleanup::purge_catalogue()
├── build.php                       # White-label build: .env.<brand> → build/<slug>/
├── .env.sellmo / .env.salespay     # Two brand profiles
├── admin/
│   ├── css/sellmo-admin.css        (968)
│   ├── js/sellmo-admin.js          (1616)   # All 5 admin screens
│   ├── js/sellmo-mapper-select2.js          # Enqueued by Sellmo_Mapper_Ajax
│   ├── images/{sellmo,salespay}-icon.png
│   └── views/                      (1548)   # 5 page templates
├── includes/                       (~7,900) # 20 classes, flat, no namespaces
├── tests/test-variant-changes.php           # Only test file in the repo
└── build/                                   # COMMITTED GENERATED OUTPUT (~18k lines)
```

**Note:** `build/salespay-woocommerce/` is a full generated duplicate of the
source that is committed to git. It doubles every `grep`, every IDE index, and
every line count. It is regenerable via `php build.php salespay`.

### 1.2 Runtime architecture

```
 WP bootstrap (plugins_loaded)
   └── WooCommerce present? ──no──> admin notice, halt
        └── require 19 classes in manual dependency order
             └── Sellmo_Plugin::get_instance()   [singleton]
                  ├── Sellmo_Admin          — menus, 5 pages, 20 AJAX handlers
                  ├── Sellmo_Product_Sync   — WC product writer
                  ├── Sellmo_Stock_Sync     — (mostly dead, see 1.4)
                  ├── Sellmo_Order_Sync     — WC → POS order push
                  ├── Sellmo_Webhook        — NOT instantiated (intentional)
                  ├── Sellmo_Scheduler      — 2 cron events
                  └── Sellmo_Mapper_Ajax    — 3 Select2 search endpoints
```

**Data flow (the design that actually ships):**

```
SalesPlay API  ──Sellmo_Product_Mapper_Sync──>  mapping table (staged)
                                                      │
                                            user maps/imports in UI
                                                      ▼
                                                WooCommerce
SalesPlay API  ──Sellmo_Stock_Sync_Pagination──> stock + mapped fields
SalesPlay API  ──Sellmo_Product_Variant_Sync ──> variants table
WooCommerce    ──Sellmo_Order_Sync───────────>  SalesPlay API
```

### 1.3 Persistence

| Table (`POS_Brand::table()`) | Owner class | Purpose |
|---|---|---|
| `{prefix}{brand}_product_mapping` | `Sellmo_Product_Mapping` | Staged catalogue + POS↔WC mapping |
| `{prefix}{brand}_product_variants` | `Sellmo_Product_Variant_Store` | One row per POS variant |
| `{prefix}{brand}_sync_log` | `Sellmo_Logger` | Sync log |
| `{prefix}{brand}_product_mapper` | *legacy* | Migrated away; drop-only references remain |

All identifiers (tables, options, transients, meta, cron hooks, page slugs) are
brand-derived through `POS_Brand`, with `POS_Brand_Migrator` handling in-place
rename when the brand slug changes. **This is well done and should not be
touched.**

### 1.4 Finding A — Dead code (severity: HIGH, effort: LOW)

Verified by exhaustive call-site search across `includes/`, `admin/`, and
`uninstall.php`. "Zero callers" means no invocation exists — only stale
docblock prose referring to the method by name.

**`Sellmo_Stock_Sync` (984 lines) — superseded by `Sellmo_Stock_Sync_Pagination`.**

| Method | Callers |
|---|---|
| `sync_stock_for_wc_product()` | **LIVE** — `Sellmo_Admin` ×2 (lines 1363, 1470) |
| `run_sync()` | none |
| `run_manual_sync()` | none — admin docblock at :1978 lies; the handler uses `Stock_Sync_Pagination` |
| `sync_single_product()` | only `Sellmo_Webhook` (never instantiated) |
| `do_full_sync()` + ~16 private helpers | reachable only from the above |

Only the ~75-line live method survives; it depends on one private helper
(`extract_next_cursor`). **≈850 lines dead.**

**`Sellmo_Product_Sync` (2,235 lines) — the full-sync engine is orphaned.**

| Method | Callers |
|---|---|
| `import_product()`, `sync_product_to_wc()`, `link_wc_product_meta()`, `sync_mapped_product_fields()`, `set_product_image_from_url()`, `find_variation_id_for_variant()` | **LIVE** |
| `run_sync()`, `run_manual_sync()`, `run_ajax_trace_sync()` | none — `Scheduler::run_product_sync()` uses `Product_Mapper_Sync` |
| `sync_single()` | only `Sellmo_Webhook` (never instantiated) |
| `run_full_sync()`, `request_products_page()`, `extract_products()`, `extract_next_cursor()` | reachable only from the above |

**≈420 lines dead** — including a **second HTTP client**
(`wp_remote_request` + its own `PRODUCTS_ENDPOINT` constant, line 1563) that
bypasses `Sellmo_API` entirely, and therefore its token handling, caching,
`force_fresh` flag, and error logging. Two divergent API policies in one plugin.

**Four AJAX endpoints registered with no caller in any JS or PHP view:**

| Action | Handler |
|---|---|
| `sellmo_wc_mapper_wc_products` | `Sellmo_Admin::ajax_mapper_wc_products()` |
| `sellmo_wc_map_variant` | `Sellmo_Admin::ajax_map_variant()` |
| `sellmo_wc_search_sellmo_products` | `Sellmo_Mapper_Ajax::ajax_search_sellmo_products()` |
| `sellmo_wc_search_wc_variations` | `Sellmo_Mapper_Ajax::ajax_search_wc_variations()` |

These are authenticated + capability-gated, so not a vulnerability — but they
are unmaintained, untested, publicly reachable surface.

**`Sellmo_Webhook` (225 lines)** is deliberately not instantiated
(`class-sellmo-plugin.php:129-134` documents why: an unsecured
`permission_callback => __return_true` REST route would fail wp.org review). It
is still `require`d on every request, and it is the sole reason two dead sync
methods look "used".

### 1.5 Finding B — Duplication / DRY violations (severity: MEDIUM)

The same API-payload shredding logic is reimplemented per class:

| Concern | Implementations |
|---|---|
| Pagination cursor | **6** — `extract_cursor()` ×3, `extract_next_cursor()` ×2, `extract_cursor_from_response()` ×1 |
| Products array | **4** — `extract_products()` ×3, `extract_items_from_response()` ×1 |
| Price resolution | **4** — `extract_shop_price()`, `resolve_price()`, `first_variant_price()`, `variant_price()` |
| Category name | **2** — `resolve_sellmo_category_name()`, `resolve_category()` (**divergent**: one returns `'Uncategorized'`, the other `''`) |
| Page cap | **5** — `MAX_PAGES = 100` ×4 plus a bare `$page < 60` in `Stock_Sync` |

The category divergence is a latent bug: the same POS product yields a different
category string depending on which sync touched it.

### 1.6 Finding C — Separation of concerns (severity: MEDIUM)

**`Sellmo_Admin` — 2,760 lines, 50 methods, ~8 responsibilities:**
menu registration · 5 page renderers · settings save · admin notices · log
export · script localisation · 20 AJAX handlers · product-import orchestration ·
mapper orchestration · WC lookup helpers · cache purging.

Note that each of its 20 AJAX handlers repeats its own `check_ajax_referer` +
`current_user_can` pair. `Sellmo_Mapper_Ajax` already solves this correctly with
a single private `guard()` (line 307) — **the good pattern exists in the
codebase and simply has not been generalised.**

**`Sellmo_Product_Mapping` — 1,776 lines, all static:** schema DDL, column
migrations, legacy-table migration, name repair, finders, mutations, business
rules (shop scoping, reconciliation), *and* human-readable change-log string
formatting. At least three distinct roles in one file.

### 1.7 Finding D — Performance (severity: LOW–MEDIUM)

One press of **Sync Products Now** currently pages the `/products` endpoint
**twice** (once in `Product_Mapper_Sync::do_delta_sync`, once in
`Product_Variant_Sync::do_sync`) and `/inventory` once. At 50 items/page and a
100-page cap that is up to 200 HTTP round-trips where 100 would do.

`Product_Variant_Sync` also issues one `get_by_variant_id()` query per variant
purely to log shop changes (already annotated `ponytail:` at line 132).

### 1.8 Finding E — Testing (severity: MEDIUM)

One test file exists (`tests/test-variant-changes.php`, added with the variant
diffing feature). There is no harness, no fixtures, and no regression suite for
the other ~40 behaviours. Per standing project rule, **every change must be
proven against the live site via `wp-load.php`, not merely linted** — §6 turns
that rule into a repeatable script.

### 1.9 What is already good — do not churn

- `declare(strict_types=1)` + typed params/returns in all 20 classes.
- `POS_Brand` / `POS_Brand_Migrator` white-labelling — coherent and complete.
- `Sellmo_Sync_Guard` — uniform exception/`WP_Error` containment.
- `Sellmo_API` — single client, cursor paging, transient caching, `force_fresh`.
- Nonce + capability checks on **every reachable** AJAX endpoint.
- All direct SQL uses `$wpdb->prepare()` with interpolated table names sourced
  from the trusted prefix, and carries the matching `phpcs:ignore` rationale.

---

## 2. Feature Inventory Checklist

Every item must behave identically before and after refactoring. Verification
procedure for each is in §6.

### 2.1 Bootstrap & lifecycle
- [ ] F01 — WooCommerce absent → admin notice, no fatal, no classes loaded
- [ ] F02 — Activation creates all 3 tables + sets `db_version` + brand baseline
- [ ] F03 — Deactivation clears both cron hooks; tables survive
- [ ] F04 — Uninstall runs `Sellmo_Cleanup::purge_catalogue()`
- [ ] F05 — HPOS compatibility declared on `before_woocommerce_init`
- [ ] F06 — Brand slug change migrates tables/options/meta/cron in place
- [ ] F07 — Admin CSS/JS enqueued only on plugin pages

### 2.2 Settings screen
- [ ] F08 — Save settings (token, shop, intervals, enable flags, timezone)
- [ ] F09 — `sellmo_wc_test_connection` — API reachability
- [ ] F10 — `sellmo_wc_get_shops` — shop dropdown population
- [ ] F11 — `sellmo_wc_get_categories` — category list
- [ ] F12 — Changing token purges the previously synced catalogue
- [ ] F13 — Changing an interval/enable flag reschedules cron

### 2.3 Dashboard
- [ ] F14 — Stats tiles (total / mapped / unmapped / WC-only)
- [ ] F15 — **Sync Products Now** (`sellmo_wc_sync_products_now`, alias `run_salesplay_sync`)
- [ ] F16 — **Sync Stock Now** (`sellmo_wc_sync_stock_now`)
- [ ] F17 — Last-sync timestamps/status persisted and displayed

### 2.4 Products screen
- [ ] F18 — `sellmo_wc_get_products_list` — paginated POS list
- [ ] F19 — `sellmo_wc_refresh_products` — bust the products cache
- [ ] F20 — `sellmo_wc_import_product` — import one POS product into WC
- [ ] F21 — `sellmo_wc_toggle_sync` — per-product sync opt-in/out
- [ ] F22 — `sellmo_wc_bulk_products` — bulk import / enable / disable

### 2.5 Product Mapper screen
- [ ] F23 — `sellmo_wc_get_mapper_list` — paginated rows + variant sub-rows
- [ ] F24 — `sellmo_wc_map_product` — link POS product to existing WC product
- [ ] F25 — `sellmo_wc_mapper_import_map` — create WC product, then map
- [ ] F26 — `sellmo_wc_unmap_product` — unlink
- [ ] F27 — `sellmo_wc_bulk_mapper` — bulk map/import/unmap
- [ ] F28 — `sellmo_wc_mapper_import_variant` — import one variant
- [ ] F29 — `sellmo_wc_mapper_import_all_variants` — import all variants
- [ ] F30 — `sellmo_wc_unmap_variant` — unlink a variant
- [ ] F31 — `sellmo_wc_search_wc_products` — Select2 search (SKU + title)
- [ ] F32 — Selected-shop scoping hides out-of-shop products/variants

### 2.6 Sync engines
- [ ] F33 — Delta sync stages only new products; existing rows refreshed in place
- [ ] F34 — Upstream edits (name/category/price/variant count/variants) detected + logged
- [ ] F35 — Products deleted in POS are reconciled (row + WC product removed)
- [ ] F36 — Variants deleted in POS are pruned (row + WC variation removed)
- [ ] F37 — Out-of-shop products removed from WC, mapper row retained
- [ ] F38 — Stock sync updates mapped products, variants, and mapped fields
- [ ] F39 — Variant sync stores/refreshes variant rows with shops + stock
- [ ] F40 — Page-cap truncation skips reconciliation (no false deletions)

### 2.7 Order sync
- [ ] F41 — `woocommerce_order_status_processing` pushes the order to POS
- [ ] F42 — Order meta box renders (HPOS + legacy post screens)
- [ ] F43 — `sellmo_wc_manual_order_sync` — manual push from the meta box
- [ ] F44 — Order timezone setting respected

### 2.8 Logging
- [ ] F45 — All syncs write typed entries (`product`/`stock`/`order`)
- [ ] F46 — Log screen filters + pagination
- [ ] F47 — Clear logs
- [ ] F48 — Export logs (CSV download)

### 2.9 Scheduling
- [ ] F49 — `{brand}_stock_sync` cron → `Stock_Sync_Pagination::run()`
- [ ] F50 — `{brand}_product_sync` cron → `Product_Mapper_Sync::run_incremental_sync()`
- [ ] F51 — Disabled toggle makes the cron callback a no-op

### 2.10 Build
- [ ] F52 — `php build.php salespay` and `php build.php sellmo` both produce a
  working zip with every `{{PLACEHOLDER}}` substituted

---

## 3. Target Architecture

Deliberately **evolutionary**. Same flat `includes/class-*.php` convention
(required by `build.php` and wp.org), same procedural WordPress bootstrap, same
brand system. What changes is *volume* and *responsibility boundaries*.

```
includes/
├── class-pos-brand.php                     UNCHANGED
├── class-pos-brand-migrator.php            UNCHANGED
├── class-sellmo-settings.php               UNCHANGED
├── class-sellmo-logger.php                 UNCHANGED
├── class-sellmo-sync-guard.php             UNCHANGED
├── class-sellmo-api.php                    UNCHANGED (sole HTTP client)
├── class-sellmo-cleanup.php                UNCHANGED
├── class-sellmo-scheduler.php              UNCHANGED
├── class-sellmo-order-sync.php             UNCHANGED
│
├── class-sellmo-payload.php                NEW  ── single source of truth for
│                                                   reading POS API payloads
│
├── class-sellmo-plugin.php                 -webhook member, -2 requires
│
├── class-sellmo-admin.php                  2760 → ~900  (menus, pages,
│                                                 settings, notices, export)
├── class-sellmo-ajax-products.php          NEW  ── F18–F22 handlers
├── class-sellmo-ajax-mapper.php            NEW  ── F23–F30 handlers
├── class-sellmo-mapper-ajax.php            UNCHANGED (Select2; guard() model)
│
├── class-sellmo-product-sync.php           2235 → ~1800 (WC writer only)
├── class-sellmo-stock-sync.php             984  → ~120  (single live method)
├── class-sellmo-stock-sync-pagination.php  UNCHANGED (the real stock engine)
├── class-sellmo-product-mapper-sync.php    UNCHANGED (the real product engine)
├── class-sellmo-product-variant-sync.php   UNCHANGED
│
├── class-sellmo-product-mapping.php        1776 → ~1400
├── class-sellmo-product-mapping-schema.php NEW  ── DDL, columns, migrations
└── class-sellmo-product-variant-store.php  UNCHANGED

DELETED: class-sellmo-webhook.php  (recoverable from git; re-add when the
                                    secret UI and auth are finished)
```

### 3.1 `Sellmo_Payload` — the DRY fix

One static class replacing 21 scattered implementations:

```php
final class Sellmo_Payload {
    public const PAGE_LIMIT = 50;
    public const MAX_PAGES  = 100;

    public static function cursor( array $response ): string;
    public static function products( array $response ): array;
    public static function inventory_levels( array $response ): array;
    public static function shop_price( array $item, string $shop_id = '' ): ?float;
    public static function representative_price( array $product ): float;
    public static function category_name( array $product, bool $blank_when_none = false ): string;
    public static function is_available_for_sale( array $item ): bool;
    public static function variant_label( array $variant ): string;
}
```

The `$blank_when_none` flag preserves *both* existing category behaviours
verbatim (§1.5) rather than silently unifying them — unifying is a separate,
explicitly-decided change, not a refactoring side effect.

### 3.2 SOLID applied, proportionately

- **SRP** — the admin split (§3) and the schema extraction are the two real
  violations worth fixing. Everything else already has one job.
- **OCP/LSP** — not applicable at meaningful scale; there is no inheritance
  hierarchy and no reason to introduce one.
- **ISP** — no fat interfaces exist (there are no interfaces; correct for this
  codebase — see §5).
- **DIP** — `Sellmo_API` is already the single seam. After deleting
  `Product_Sync`'s private HTTP client it becomes the *only* seam, which is what
  makes the sync engines testable at all.

### 3.3 Error handling & typing

Already strong. Two rules to enforce as invariants going forward:

1. Every public sync entry point returns `array{success:bool,count:int,message:string}`.
2. Every sync entry point is wrapped in `Sellmo_Sync_Guard::run()`.
   *(Currently `Product_Mapper_Sync` and `Product_Variant_Sync` comply;
   `Stock_Sync_Pagination` does not — a one-line fix in Phase 4.)*

---

## 4. Refactoring Strategy

Each phase is independently shippable, independently revertable, and ends with a
green verification run. **No phase depends on a later phase.**

| Phase | Scope | Δ lines | Risk | Verifies |
|---|---|---|---|---|
| 0 | Baseline capture + branch | 0 | none | all |
| 1 | Delete dead code | **−1,300** | LOW | F15–F17, F33–F40 |
| 2 | Extract `Sellmo_Payload` | −250 | LOW-MED | F33–F40 |
| 3 | Split admin god class | ±0 (moves) | MED | F14–F31 |
| 4 | Mapping schema extraction + guard invariant | ±0 | LOW | F02, F06, F16 |
| 5 | *(optional)* single-fetch catalogue | −100 | MED-HIGH | F15, F33–F40 |

### Phase 0 — Baseline (no code change)
1. `git checkout -b refactor/architecture-cleanup`
2. Run `tests/baseline-snapshot.php` (§6.1) → commit `baseline-before.json`
3. Regenerate `build/` from a clean tree to confirm the build is green *before*
   any change: `php build.php salespay && php build.php sellmo`

### Phase 1 — Delete dead code *(do this even if nothing else happens)*
1. Delete `class-sellmo-webhook.php`; remove its `require` and the
   `Sellmo_Plugin::$webhook` property + commented instantiation.
2. Delete from `Sellmo_Product_Sync`: `run_sync`, `run_manual_sync`,
   `run_ajax_trace_sync`, `sync_single`, `run_full_sync`,
   `request_products_page`, `extract_products`, `extract_next_cursor`, and the
   now-orphaned `PRODUCTS_ENDPOINT` / `PAGE_LIMIT` / `MAX_PAGES` /
   `$last_error` members.
3. Reduce `Sellmo_Stock_Sync` to `sync_stock_for_wc_product()` + its one
   private helper. Rename the file's docblock to state it is a single-product
   helper, not an engine.
4. Delete the 4 uncalled AJAX handlers and their `add_action` registrations.
5. Fix the stale docblocks in `Sellmo_Admin` (:1845, :1978) that name the
   deleted methods.
6. **Gate:** every `git grep` for a deleted symbol returns only the deletion
   commit. Full §6 verification run.

### Phase 2 — DRY the payload readers
1. Add `class-sellmo-payload.php` + `require` in bootstrap.
2. Port call sites **one class per commit**, in this order (lowest blast radius
   first): `Product_Variant_Sync` → `Stock_Sync_Pagination` →
   `Product_Mapper_Sync` → `Product_Mapping` → `Product_Sync` → `Admin`.
3. Each commit deletes the private helper it replaced.
4. **Gate:** after each commit, re-run the sync trio and diff row counts against
   the Phase-0 baseline — they must match exactly.

### Phase 3 — Split the admin god class *(pure mechanical moves)*
1. Create `Sellmo_Ajax_Products` and `Sellmo_Ajax_Mapper`, each with the
   `guard()` helper copied from `Sellmo_Mapper_Ajax`.
2. Move handlers **verbatim** — no logic edits in the same commit as a move.
3. Move the `add_action` registrations with them; instantiate both in
   `Sellmo_Plugin::init()`.
4. Move shared private helpers (`resolve_sellmo_payload`, `get_wc_product_map`,
   `find_wc_product_id_by_code`, …) to whichever class uses them; duplicate
   only if genuinely used by both.
5. Replace each handler's inline nonce/cap pair with `$this->guard()`.
6. **Gate:** exercise all of F14–F31 through the browser UI, not just PHP.

### Phase 4 — Mapping schema extraction
1. Move `create_table`, `ensure_table`, `ensure_columns`,
   `maybe_migrate_legacy_mapper`, `maybe_clean_names`, `drop_table` into
   `Sellmo_Product_Mapping_Schema`.
2. `Sellmo_Product_Mapping::ensure_table()` becomes a one-line delegate so no
   call site changes.
3. Wrap `Stock_Sync_Pagination::run()` in `Sellmo_Sync_Guard::run()`.
4. **Gate:** deactivate → drop tables → reactivate → confirm F02; run a brand
   slug change to confirm F06.

### Phase 5 — *(optional, measure first)* single catalogue fetch
Have `Product_Mapper_Sync` pass its already-fetched pages to
`Product_Variant_Sync` instead of letting it re-page `/products`.
**Only take this phase if F15 timing is actually a complaint.** It couples two
engines that are currently independent — the classic trade of clarity for speed
nobody asked for.

### Out of scope (explicitly)
- `admin/js/sellmo-admin.js` (1,616 lines) — same god-object smell, but it is
  the *only* thing driving 5 screens and has no test coverage at all. Splitting
  it is a separate project with its own plan.
- `build/` — should be `.gitignore`d, but that is a repo-hygiene decision for
  the maintainer, not a refactor.

---

## 5. Rejected Approaches

Recorded so they are not re-proposed later.

| Proposal | Why rejected |
|---|---|
| Composer + PSR-4 + namespaces | `build.php` rewrites files by extension and bakes `{{PLACEHOLDERS}}`; wp.org review and the `class-*.php` convention both assume flat includes. Large break, zero behavioural gain. |
| DI container / service locator | 20 classes with ~7 real dependencies. `Sellmo_Plugin::init()` *is* the composition root and it fits on one screen. |
| `src/Domain|Application|Infrastructure` | Layering ceremony for a plugin whose "domain" is 3 tables and 4 API endpoints. Would triple the file count and the import depth. |
| Interfaces for each sync engine | One implementation each. An interface with one implementation is a rename with extra steps. |
| Rewriting the static classes as instance services | Static access is the WordPress idiom here and is consistent across the codebase. Churn without benefit. |
| Unifying the two category behaviours during refactor | It is a **behaviour change**, not a refactor. Must be decided and verified on its own. |

---

## 6. Verification Strategy

**Standing rule: nothing is "done" on the strength of `php -l` alone.** Every
phase gate below runs against the real WordPress install via `wp-load.php`.

### 6.1 Automated regression harness

Add `tests/baseline-snapshot.php` — bootstraps WordPress, captures a JSON
fingerprint, and diffs against a stored baseline:

```php
require_once 'c:/xampp3/htdocs/wordpress/wp-load.php';
// captures, per run:
//   mapping table:  row count, mapped count, per-row {name, category, price, variant_count}
//   variants table: row count, per-parent variant ids
//   WC:             product count, variation count, plugin-created product ids
//   options:        every Sellmo_Settings key
//   cron:           next-run timestamps for both hooks
//   log:            entry count by (type, status)
```

Usage at each gate:

```bash
php tests/baseline-snapshot.php --save baseline-before.json     # Phase 0
php tests/baseline-snapshot.php --compare baseline-before.json  # every gate
```

A gate passes only on an **empty diff** — except where a phase is expected to
change a value, in which case the expected delta is stated in the commit message.

Keep `tests/test-variant-changes.php` (pure-logic, no WP) running in CI-style:
`php tests/test-variant-changes.php` → `ok`.

### 6.2 Per-feature procedures

| Features | Procedure | Pass criterion |
|---|---|---|
| F01 | Rename `woocommerce/` dir, load `/wp-admin` | Notice shown, no fatal, no plugin menu |
| F02–F04 | Deactivate → drop 3 tables → activate | 3 tables recreated, `db_version` set, baseline written |
| F05 | WC → Settings → Advanced → HPOS | Plugin listed compatible |
| F06 | Build `sellmo`, activate over `salespay` data | Tables/options/cron renamed, zero data loss |
| F07 | Load a non-plugin admin page | No `sellmo-admin.css/js` in source |
| F08–F13 | Settings screen, each control | Value persists; cron next-run shifts; token change purges |
| F14–F17 | Dashboard, both buttons | Counts match `baseline-snapshot`; log entries written |
| F18–F22 | Products screen: list, refresh, import 1, toggle, bulk 3 | WC products created; `_sellmo_sync_enabled` flips |
| F23–F32 | Mapper: expand rows, map, import, unmap, bulk, Select2, shop switch | Sub-rows match variants table; scoping hides others |
| F33–F34 | Edit name/category/price/variant in POS → Sync Products Now | Row updated; log names the product and each change |
| F35 | Delete a product in POS → sync | Mapper row + WC product gone |
| F36 | Delete a variant in POS → sync | Variant row + WC variation gone, logged *(regression-tested 2026-08-12: 12 → 2 rows)* |
| F37 | Move a product to another shop → sync | WC product removed, mapper row retained |
| F38–F39 | Sync Stock Now | Stock + mapped fields updated; variant rows refreshed |
| F40 | Force `MAX_PAGES` truncation | `reconcile_absent` skipped; nothing deleted |
| F41–F44 | Order → Processing; meta box; manual button | POS order created; timezone honoured |
| F45–F48 | Log screen: filter, paginate, export, clear | CSV downloads; table empties |
| F49–F51 | `wp cron event run` both hooks; disable and re-run | Same result as manual button; no-op when disabled |
| F52 | `php build.php salespay && php build.php sellmo` | Both zips build; `grep -r '{{' build/` returns nothing |

### 6.3 Static gates (every commit)

```bash
php -l <each changed file>
php tests/test-variant-changes.php          # → ok
git grep -n '<deleted symbol>'              # → only the deletion commit
grep -rn '{{[A-Z_]*}}' includes/ | wc -l    # unchanged: templating intact
```

---

## 7. Recommendation

**Do Phase 1. Then reassess.**

Phase 1 alone removes ~1,300 lines, eliminates the duplicate HTTP client,
closes four unused endpoints, and deletes the disabled webhook — with no
behaviour change and a two-hour verification. It is the entire "refactor" that
this codebase actually needs.

Phases 2–4 are real improvements to a codebase that is already in decent shape.
They are worth doing when someone next has to work in `Sellmo_Admin` — and not
especially before that.

Phase 5 should probably never happen.

---

*Awaiting review. No core code has been modified.*
