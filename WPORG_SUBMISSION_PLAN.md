# WordPress.org Submission Plan

Remediation plan for the two packages in `build/`. Last audited against the **built output**, not the source tree.

| Package | Slug | Author / Contributor | Brand profile | Files | ZIP |
|---|---|---|---|---|---|
| SalesPlay POS Integration | `salesplay` | `salesplay` | `.env.salesplay` | 36 | 635 KB |
| Sellmo POS Integration | `sellmo` | `sellmo` | `.env.sellmo` | 36 | 624 KB |

Every fix belongs in **source**, **`.env.<brand>`**, or **`build.php`** — never in `build/`, which is wiped and regenerated on each run. Guideline numbers refer to the [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

---

## Status at a glance

| # | Issue | Severity | Status |
|---|---|---|---|
| D1 | Developer files shipped in the ZIP | Blocker | Done |
| D2 | "SalesPay" vs "SalesPlay" | Blocker | Done |
| D3 | Webhook accepted unsigned payloads | Blocker | Done — feature withdrawn from the UI |
| B1 | Text domain does not match the slug | Blocker | Done |
| B4 | Both brand icons ship in both packages | Blocker | Done |
| B5 | Both plugins share every PHP identifier | Blocker | Done — Option A |
| B6 | `POS_` is not a unique prefix | Blocker | Done — folded into B5 |
| P3 | Slug contains "woocommerce" | Please-fix | Done — name, slug and domain now agree |
| B2 | `Domain Path` points at a nonexistent folder | Blocker | Done |
| B3 | Cross-brand names in user-facing strings | Blocker | Done |
| P1 | Stale `Tested up to` / `WC tested up to` | Please-fix | Done — WP 7.1 / WC 10.7 |
| P2 | `Contributors` must be real wp.org usernames | Please-fix | Verify |
| P4 | Unescaped output — 20 sites | Please-fix | Done |
| P5 | `set_time_limit()` — 6 calls | Please-fix | Done |
| P6 | 10 `console.*` statements in production JS | Cosmetic | Done |
| P7 | 4 `.gitkeep` files per package | Cosmetic | Open |
| N1 | `class-pos-brand.php` filename no longer matches its class | Cosmetic | Open |
| N2 | No `Requires Plugins: woocommerce` header | Cosmetic | Open |
| N3 | Sellmo readme discloses "powered by SalesPlay POS" | Decision | Done — removed |

**No blockers remain, and every please-fix item is closed except P2** (confirming the two wp.org accounts exist, which only you can do). What is left is one verification (P2), two cosmetics (P7, N1) and one header addition (N2) — none of which block submission.

See **Log audit** at the end for two findings that are not wp.org issues but are real.

---

## Verified clean in this audit

Re-checked directly against `build/salesplay/` and `build/sellmo/`:

- **Package contents** — 36 files each, no `tests/`, no `.md`, no cross-brand icon. Each package carries only its own `admin/images/<brand>-icon.png`.
- **Identifier isolation** — class, function, constant, AJAX-hook, REST-namespace and script-handle sets are disjoint between the two packages (20/2/4/27/1/4 each). All 40 class files load into one PHP process without a fatal.
- **Stored-data identifiers preserved** — `_sellmo_*` post meta, `sellmo_id`/`sellmo_code` payload keys, the `'sellmo'` legacy slug, `sellmo_to_wc`/`wc_to_sellmo` log values, and the `X-Sellmo-Signature` header all survive the rename in both packages.
- **Text domain** — header matches the slug in both (`salesplay`, `sellmo`).
- **`uninstall.php`** — guarded on `WP_UNINSTALL_PLUGIN`, and correctly references the renamed classes (`SalesPlay_Cleanup`, `SalesPlay_Brand`).
- **`ABSPATH` guard** present in every PHP file in both packages.
- **No unreplaced placeholders.** The three apparent hits are JSDoc type annotations and one docblock describing the templating system.
- **External-services disclosure** *(Guideline 7)* — each readme names its own endpoint (`api.salesplaypos.com` / `api.backofficewebportal.com`), enumerates what is sent and when, and links that brand's Terms and Privacy Policy.
- **readme header block** — all required fields, 4 tags (max 5), short description ~112 chars, `Stable tag: 1.0.1` matching the header `Version`.
- **ZIP structure** — single top-level folder matching the slug, 36 entries.
- **No trialware, upsells, dashboard hijacking, obfuscation, `eval`/`base64`/`exec`, bundled libraries, or raw cURL** *(Guidelines 4, 5, 8, 10, 11, 13)*.
- **Input handling and SQL** — every `$_GET`/`$_POST` read goes through `wp_unslash()` plus a sanitiser; all 27 AJAX handlers check a nonce and `current_user_can( 'manage_woocommerce' )`; every user value in SQL goes through `$wpdb->prepare()`.

---

## Recently fixed

### B2 — `Domain Path` — done

`includes/languages/` moved to `languages/` at the plugin root, so the folder the header names now exists in both packages. It does **not** reduce the `.gitkeep` count — the file moved with the folder, so P7 is still 4 per package.

### B3 — Cross-brand names — done

Resolved at runtime through `POS_Brand::name()`, not at build time, so each package names itself correctly whatever brand it is built as. 18 strings rewritten across six files, every one with a `translators:` comment.

The trickiest was the WooCommerce dependency notice in the main plugin file: it fires *before* the brand engine is loaded, since the bootstrap bails out early when WooCommerce is missing. `class-pos-brand.php` is now required inside that branch so `POS_Brand::plugin_name()` is available — it is the first thing a merchant sees, so it has to be right.

Four residual leaks the first pass missed, caught by re-auditing the built output rather than the source:

| Where | Was | Now |
|---|---|---|
| `admin/js/…-admin.js` | `cfg.brandName \|\| 'Sellmo'` | `\|\| 'POS'` |
| `…-mapper-select2.js` | `i18n.searchSellmo \|\| 'Search Sellmo product…'` | neutral `'Search POS product…'` fallback |
| `…-mapper-select2.js` | `'Select a Sellmo product first.'` | new localized `selectSellmoFirst` key |
| `class-product-mapper-sync.php` | `'SalesPlay API token is not configured.'` | `sprintf( '%s API token…', POS_Brand::name() )` |

That last one leaked in the *opposite* direction — a SalesPlay string inside the Sellmo package — which is exactly why the audit has to read both packages, not just the source.

> **Still do not solve this with a build rule.** A word-boundary `Sellmo` replacement also matches inside `X-Sellmo-Signature`, the webhook header the POS backend sets.

### P1 — Compatibility headers — done

`Tested up to: 7.1`, `WC tested up to: 10.7` in both packages, matching the environment the suite actually runs against. Updated in both `.env` profiles and in `build.php`'s defaults.

### P6 — `console.*` statements — done

All ten removed from `admin/js/<brand>-admin.js`, along with the `isSalesPlaySync` flag that guarded them — which was itself the bulk of the Sellmo package's cross-brand leak.

### N3 — Platform disclosure — done

`(powered by SalesPlay POS)` removed from the readme template. Both disclosures remain accurate: each names its own endpoint (`api.salesplaypos.com` / `api.backofficewebportal.com`) with that brand's Terms and Privacy links, which is what Guideline 7 requires.

### P3 — Plugin name, slug and text domain now agree — done

WordPress.org derives the permanent slug from the **Plugin Name**, so a descriptive title would have produced `salesplay-pos-integration` while the folder said `salesplay`. `PLUGIN_NAME` is now the bare brand in both profiles:

| | Plugin Name | readme title | folder / main file | Text Domain |
|---|---|---|---|---|
| SalesPlay | `SalesPlay` | `=== SalesPlay ===` | `salesplay/salesplay.php` | `salesplay` |
| Sellmo | `Sellmo` | `=== Sellmo ===` | `sellmo/sellmo.php` | `sellmo` |

All four now agree, and the derived slug is the one you asked for. The descriptive wording moved to `PLUGIN_DESCRIPTION`, which is the readme short description — the line the directory shows under the title. Each `.env` carries a comment explaining why the name must stay bare, so nobody "improves" it later and silently changes the slug.

Note the Plugins-screen row still reads "SalesPlay POS Integration": `POS_Brand::plugin_name()` appends the suffix at runtime through the `all_plugins` filter. Only the static header — the part wp.org reads — is bare.

### P4 — Output escaping — done

Twenty flagged sites, of which **six were false positives**: multi-line ternaries whose branches are already `esc_html__()` / `esc_attr__()`, split across lines so a single-line grep sees only `echo $var`. Left as they are.

The rest, all real:

| Site | Was | Now |
|---|---|---|
| `page-dashboard.php` ×4 | `echo $x ? 'enabled' : 'disabled'` in a class attribute | wrapped in `esc_attr()` |
| `page-settings.php` | `echo $has_token ? '' : 'disabled'` | core `disabled( $has_token, false )` |
| `page-settings.php` | `class="…<?php echo $has_token ? '' : ' disconnected'; ?>"` | wrapped in `esc_attr()` |
| `page-settings.php` | `style="<?php echo $has_token ? '' : ''; ?>"` | deleted — both branches were empty |
| `page-sync-log.php` ×2 | `echo $pagination_html();` behind a `phpcs:ignore` | `wp_kses_post()` |
| `page-sync-log.php` ×4 | `echo $display_date / $item_html / $msg_html / $type_label` marked `// pre-escaped` | `wp_kses_post()` |
| `page-sync-log.php` | `<tr<?php echo $row_class; ?>>` where `$row_class` was a raw ` style="…"` fragment | CSS class `sellmo-log-row-error`, escaped with `esc_attr()` |
| `page-sync-log.php` | `<div … <?php echo $total_pages <= 1 ? 'style="display:none"' : ''; ?>>` | CSS class `sellmo-hidden`, escaped |

Two of those replaced inline styles with classes, so `admin/css/<brand>-admin.css` gained `.sellmo-log-row-error` and `.sellmo-hidden` — both renamed per brand by the build like every other selector. No `// pre-escaped` comment or `phpcs:ignore` for escaping remains in the views; reviewers do not accept either as escaping.

### P5 — `set_time_limit()` — done

All six calls now route through one helper, `Sellmo_Sync_Guard::extend_time_limit( int $seconds ): bool`, which checks `function_exists()`, checks the `disable_functions` ini list, and returns false instead of raising if the host refuses. The sync engines are batched and resume on the next run, so a refused extension is not an error — the one silenced call carries a `phpcs:ignore` explaining exactly that.

### Test harness — now brand-aware

Installing a built package broke the suite: it asserts against source names (`Sellmo_Product_Mapping`, `sellmo_wc_admin`, `sellmo-wc/v1`) while a build renames all of them. `tests/bootstrap.php` now detects the installed prefix and aliases the source names onto it, plus `t_hook()`, `t_fn()` and `t_rest_namespace()` for the hook, function and REST names that cannot be aliased.

This is a real upgrade in coverage, not a workaround: the suite now runs against **the package that actually ships**, not only the source tree. It prints which build it is testing on start-up.

---

## Will draw a "please fix"

### P2 — `Contributors` must be real wp.org usernames

`Contributors: salesplay` and `Contributors: sellmo` must be existing WordPress.org accounts — a display name produces a broken contributor card. Confirm both exist, and that each is the submitting account for its own package.

### P7 — `.gitkeep` files

Four per package: `admin/css/`, `admin/js/`, `admin/views/`, `languages/`. Harmless, but they signal a repo copied rather than a package built. B2 did not reduce the count — the file moved with its folder.

**Fix:** skip `.gitkeep` in `copy_tree()`, the same way `*.md` is skipped.

---

## Remaining cosmetics, explained

### N1 — `class-pos-brand.php` no longer matches its class name

**What it is.** WordPress convention names a class file after the class inside it: `class-` plus the class name, lowercased, underscores turned into hyphens. `class-product-mapping.php` holds `Product_Mapping` — consistent. But the B5 rename rewrites the class *inside* `class-pos-brand.php` to `SalesPlay_Brand` (or `Sellmo_Brand`), while the **filename** stays `class-pos-brand.php`. By convention it should be `class-salesplay-brand.php`.

**Why it does not break anything.** Nothing autoloads by filename. Every load is an explicit `require_once SELLMO_WC_PATH . 'includes/class-pos-brand.php'`, and the build rewrites the class references but not the paths, so the two stay consistent with each other. The plugin runs correctly, and `test-16` proves both packages load together.

**Why it is worth a line anyway.** It is the one place in the package where a filename no longer describes its contents. A reviewer skimming `includes/` sees a `pos-brand` file that declares a `SalesPlay_Brand` class, which reads as a leftover from an incomplete rename. Neither Plugin Check nor any guideline enforces this — it is a readability cost, nothing more.

**Fix, if you want it:** extend the build's asset-rename sweep to cover `class-pos-brand.php` and `class-pos-brand-migrator.php`, rewriting both the filenames and the two `require_once` paths that name them. Roughly ten lines in `build.php`. **Skipping it is entirely reasonable.**

### N2 — No `Requires Plugins: woocommerce` header

**What it is.** WordPress 6.5 added a plugin header field for declaring a dependency on another plugin from the directory:

```php
 * Requires Plugins: woocommerce
```

With it present, WordPress itself enforces the dependency: the Plugins screen shows "Requires: WooCommerce", the Activate link is disabled until WooCommerce is installed and active, and deactivating WooCommerce warns that dependent plugins will stop working.

**What the plugin does instead.** Both packages check at runtime — `if ( ! class_exists( 'WooCommerce' ) )` in the `plugins_loaded` callback — and register an admin notice saying the plugin requires WooCommerce. That works on every WordPress version, and it is the notice B3 just fixed to name the right brand.

**Why add the header anyway.** The runtime check only fires *after* someone has already activated the plugin, so a merchant can activate it into a broken state and then read a notice explaining why nothing works. The header prevents that activation happening at all. Since WP 6.5 this is the expected form, and the Plugin Check plugin now flags its absence for plugins that clearly depend on another.

**Fix:** add the one header line, and keep the runtime check as the fallback for WordPress older than 6.5 — the two coexist fine. Worth noting that `Requires at least: 6.0` means a real slice of installs will still rely on the runtime path.

---

## Completed — what changed

### D1 — Developer files shipped in the ZIP *(Guideline 2, 4)*

15 test scripts and 3 internal docs were being packaged, including a `tests/bootstrap.php` that hard-coded `c:/xampp3/htdocs/wordpress/wp-load.php`. `EXCLUDE` now drops `tests`, and `copy_tree()` drops every `*.md` **by pattern** — a name list would silently ship the next doc anyone adds, including this file. 55 → 36 entries, 880 KB → 635 KB.

### D2 — "SalesPay" vs "SalesPlay" *(Guideline 17)*

Seven occurrences across `.env.salesplay` and `class-pos-brand.php`, plus a `BRAND_DOCS` URL pointing at `salespay.com` and a usage comment naming a brand profile that does not exist. Guarded by `test-15`.

### D3 — Webhook accepted unsigned payloads

The handler skipped HMAC verification whenever the shared secret was empty, and the secret shipped empty — so a fresh install accepted anonymous payloads into product/stock sync.

`Webhook::ensure_secret()` now generates a 64-character secret on activation and lazily on `admin_init`; the handler rejects with 401 when the secret is missing and verifies the signature unconditionally. Guarded by `test-14` (18 checks).

The settings card had been wrapped in an HTML comment, which does not remove it — PHP inside `<!-- -->` still executes, so the card still rendered as an empty "Under Development" box and echoed the stored secret into the page source. Section E of `page-settings.php` was deleted outright (62 lines), along with its two variables and an orphaned `admin_notices` warning pointing at a Generate button that no longer exists. `Plugin::init()` still does not instantiate the webhook, so no route is registered.

### B1 / B4 / B5 / B6 — the build-time rename pass

An ordered regex pass over the staged `.php` / `.js` / `.css`, driven by `BRAND_NAMESPACE` and `BRAND_SLUG`:

| Rule | SalesPlay build | Sellmo build |
|---|---|---|
| `SELLMO_WC_` | `SALESPLAY_WC_` | unchanged |
| `Sellmo_` + uppercase | `SalesPlay_` | unchanged |
| `POS_Brand` | `SalesPlay_Brand` | `Sellmo_Brand` |
| `sellmo_wc_` | `salesplay_wc_` | unchanged |
| `sellmo_dashboard_` | `salesplay_dashboard_` | unchanged |
| `run_salesplay_sync` | unchanged | `run_sellmo_sync` |
| `sellmo-woocommerce` | `salesplay` | `sellmo` |
| `sellmo-` | `salesplay-` | unchanged |
| `sellmoAdmin` / `sellmoMapperSelect2` | `salesplayAdmin` / … | unchanged |

Staged asset files starting with `sellmo-` are renamed to match the rewritten handles; the main plugin file is renamed **before** that sweep so it is not caught by it. `prune_images()` keeps only the file `BRAND_LOGO` names and fails the build if it is missing. The text domain is forced to the package slug.

**Two bugs found while building this:**

1. A word boundary before `sellmo_wc_` does not match inside `'wp_ajax_sellmo_wc_x'` — `_` is a word character. The PHP hook names were left alone while the JS `action:` strings were renamed, which would have broken every admin AJAX call with no error anywhere. The rule now omits the boundary.
2. `run_salesplay_sync` is brand-named in the source, so it came out identical in both builds — a collision the prefix rules structurally could not see. It has its own rule.

**Deliberately not renamed**, because they name stored data rather than code — `test-16` asserts each survives: the `_sellmo_*` post-meta keys, `sellmo_id` / `sellmo_code` / `sellmo_product*` payload keys shared with the JS, `'sellmo'` (`POS_Brand_Migrator::LEGACY_SLUG`), `sellmo_to_wc` / `wc_to_sellmo` log values, and `X-Sellmo-Signature`.

Two *un*-renamed packages were confirmed to fatal on `Cannot declare class Sellmo_Admin`, so the isolation test is load-bearing rather than decorative.

### Source layout — `includes/` filenames

`class-sellmo-*.php` put a brand name in the file layout of both packages. Renamed with `git mv` to drop the segment — 18 files — with every `require_once` updated in `sellmo-woocommerce.php`, `uninstall.php` and the tests, and mirrored into the live install. See N1 for the one leftover.

---

## Suggested order

1. **P3 confirmation** — decide the slug form. It is the only decision that is expensive to reverse, and it sets the text domain.
2. **P4, P5** — the last two code-quality items, each with a test.
3. **N2, P7** — one header line and one `copy_tree()` filter.
4. **P2** — confirm both wp.org accounts exist, then submit.

N1 is optional and can be skipped.

## Log audit

Read on 2026-08-21 across three sources: the Apache error log, the PHP error log, and the plugin's own `wp_salesplay_sync_log` table (1,213 rows). `wp-content/debug.log` does not exist because `WP_DEBUG` is `false`.

### Clean

- **No PHP error of any kind from the plugin since 11:53 today.** The last entries were the `strict_types` fatals from the stray leading space in `class-sellmo-api.php`, which stop at `11:53:43` — the fix held, and nothing has been logged since across a full day of rebuilds, reinstalls and suite runs.
- No warnings, notices or deprecations from `plugins/salesplay/` at all. The only PHP warning in the log is `Undefined array key "HTTP_HOST"` in XAMPP's own `htdocs/index.php`, unrelated to this plugin.

### Finding 1 — order sync fails permanently on an order with no billing email

The only genuine recurring error in the sync log, 44 rows across 8 orders:

```
POST /online_orders -> HTTP 400
{"errors":{"code":"INVALID_VALUE","details":"Invalid email format","field":"customer_email"}}
```

Every affected order has an empty billing email. `class-order-sync.php:697` sends the field unconditionally:

```php
'customer_email' => $order->get_billing_email(),
```

An empty string is not a valid email, so the POS rejects the **entire order** — not just the field. The order stays in `processing` and the sync retries and fails again on every run, which is where the repeat count comes from.

This matters beyond the test fixtures that triggered it: any real order placed without a billing address — a phone order, a manual admin order, a checkout with email made optional — will silently never reach the POS, and the merchant sees only an opaque `INVALID_VALUE` in the log.

**Fixed:** `build_payload()` now adds `customer_email` only when the order has one, instead of always sending the key. Pinned by `test-6-order-sync.php` ("no billing email -> customer_email key is omitted, not empty").

**Still unverified:** whether the POS treats the field as *required*. Answering that needs a live `POST /online_orders`, which the sandbox blocked as an outbound write to a third-party API. The probe is written and ready at `scratchpad/probe.php` — it sends three variants (omitted / empty / valid) with an **empty `order_items` array** so validation must fail and no order can be created in the live POS.

The fix is an improvement either way: if the field is optional, order sync starts working; if it is required, an omitted key produces a clear "required" error instead of the misleading "Invalid email format". If it turns out to be required, the right follow-up is to skip the push and tell the merchant the order needs a billing email — **not** to fabricate a placeholder address, which would pollute POS customer records and bounce.

### Finding 2 — the suite leaves order fixtures behind, and writes its errors to the live log

Eight orders remain in the WooCommerce store from today's runs (ids 2952 → 3281, evenly spaced 47 apart — one batch per suite run), all with empty name, empty email and a `0.00` total. `t_defer()` cleans up products and mappings but evidently not orders.

Separately, **127 of the 171 error rows in the sync log are test artifacts** — "Test made an unstubbed HTTP call…", "Webhook rejected: invalid signature" (that one is `test-14` proving rejection works, so an error row is the correct outcome). They are indistinguishable from real failures in the admin Sync Log screen, which makes the screen much less useful for diagnosing an actual problem.

**Suggested fix:** register the order fixtures with `t_defer()` like every other fixture, and have the harness delete the log rows it created — `t_log_mark()` already records the watermark needed to do it.

Neither finding affects a WordPress.org submission. Both are worth fixing before the plugin is in front of merchants.

## Verification gate

`build.php` runs `tests/run-all.php` and aborts on failure. Current suite: **17 files, all green**.

Package-level guarantees are covered by three static suites that read `build/*/` directly, so they hold for whatever was last built:

- `test-13` — every PHP file opens with `<?php` and parses, in both the source tree and any installed copy
- `test-15` — no dev files, no `.md`, no misspelt brand, text domain matches the slug
- `test-16` — symbol sets disjoint, stored-data identifiers preserved, both packages load into one process

The suite now runs against the **installed build**, not just the source tree — `tests/bootstrap.php` aliases the source class names onto whatever brand prefix is installed, so the assertions exercise the package that actually ships.

The remaining coverage gap: B2 (Domain Path directory exists), B3 (no cross-brand string in any package) and P4 (escaping) have no automated check. B2 and B3 belong in `test-15`, which already reads `build/*/` — both are a handful of lines and would have caught the four residual leaks that this audit found by hand.
