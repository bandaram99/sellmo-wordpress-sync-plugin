# TASKS.md — Sellmo WooCommerce Plugin

> Maintained by Claude. Updated at the start and end of each working session.
> Last updated: 2026-05-06

---

## Completed

- [x] **Phase 0 – Scaffold**
  - [x] `sellmo-woocommerce.php` — plugin entry, constants, WC dependency check, bootstrap
  - [x] `uninstall.php` — drops sync log table, deletes all `sellmo_wc_*` options
  - [x] `includes/class-sellmo-settings.php` — static get/set option helpers + defaults map
  - [x] `includes/class-sellmo-logger.php` — DB table creation, log insert, paginated retrieval, drop
  - [x] `includes/class-sellmo-api.php` — full API client with retry/backoff and transient caching
  - [x] `includes/class-sellmo-plugin.php` — singleton bootstrap, module instantiation, asset enqueue
  - [x] `includes/class-sellmo-admin.php` — stub
  - [x] `includes/class-sellmo-product-sync.php` — stub
  - [x] `includes/class-sellmo-stock-sync.php` — stub
  - [x] `includes/class-sellmo-order-sync.php` — stub
  - [x] `includes/class-sellmo-webhook.php` — stub
  - [x] `includes/class-sellmo-scheduler.php` — stub
  - [x] Asset folders: `admin/css/`, `admin/js/`, `admin/views/`, `languages/`

---

## Backlog

### Phase 1 – Settings & Admin Menu

- [ ] Implement `Sellmo_Admin` constructor — register `admin_menu` hook
- [ ] `add_menu_page()` — "Sellmo POS", dashicons-store icon, position 56
- [ ] Sub-pages: Dashboard, Settings, Products, Sync Log
- [ ] Create `admin/views/page-settings.php`
  - [ ] API token field (password input, never pre-filled)
  - [ ] Shop dropdown — populated via `Sellmo_API::get_shops()`
  - [ ] Feature toggles: product sync, stock sync, order sync (yes/no)
  - [ ] Sync interval selectors: stock (hourly / twicedaily / daily), product (twicedaily / daily)
  - [ ] Webhook secret display + regenerate button
- [ ] Settings save handler: nonce check + `current_user_can('manage_woocommerce')` + sanitise + `Sellmo_Settings::set()`
- [ ] Admin notice when API token is not configured

### Phase 2 – Product Sync

- [ ] Implement `Sellmo_Product_Sync`
- [ ] `sync_all(): void` — cursor-loop through `Sellmo_API::get_products()`, match via `_sellmo_product_code`
- [ ] `import_product( array $sellmo_product ): int|WP_Error` — create new WC product, set post meta
- [ ] `update_product( int $post_id, array $sellmo_product ): bool` — update title, price, description, images
- [ ] Variant handling: Sellmo variants → `WC_Product_Variable` + `WC_Product_Variation`
- [ ] Set `_sellmo_product_code`, `_sellmo_last_synced`, `_sellmo_sync_enabled` on each sync
- [ ] Create `admin/views/page-products.php`
  - [ ] Paginated list of Sellmo products from API
  - [ ] Sync-enable checkbox per product
  - [ ] "Import to WooCommerce" button for unmatched products
  - [ ] Sync status column (last synced timestamp or "Not synced")

### Phase 3 – Stock Sync

- [ ] Implement `Sellmo_Stock_Sync`
- [ ] `sync_all(): void` — cursor-loop `Sellmo_API::get_inventory()`, match `_sellmo_product_code`, call `wc_update_product_stock()`
- [ ] Register cron callback: `sellmo_wc_stock_sync` → `Sellmo_Stock_Sync::sync_all()`
- [ ] Log each update via `Sellmo_Logger::log( 'stock', 'sellmo_to_wc', ... )`
- [ ] Respect `sellmo_wc_stock_sync_enabled` option — bail early if 'no'

### Phase 4 – Order Sync

- [ ] Implement `Sellmo_Order_Sync`
- [ ] Hook `woocommerce_order_status_processing` → `push_order( int $order_id ): void`
- [ ] `build_order_payload( WC_Order $order ): array` — format WC order for Sellmo API
- [ ] Call `Sellmo_API::create_order()`, save `_sellmo_order_id` on success
- [ ] On failure: add WC order note + `Sellmo_Logger::log( 'order', 'wc_to_sellmo', 'error', ... )`
- [ ] Respect `sellmo_wc_order_sync_enabled` option — bail early if 'no'

### Phase 5 – Webhooks

- [ ] Implement `Sellmo_Webhook`
- [ ] Register REST route `POST /wp-json/sellmo-wc/v1/webhook` on `rest_api_init`
- [ ] Validate `X-Sellmo-Signature`: `hash_hmac( 'sha256', $raw_body, $secret )`, compare with `hash_equals()`
- [ ] Return `WP_REST_Response` with status 200 immediately after validation
- [ ] Queue async processing: `wp_schedule_single_event( time(), 'sellmo_wc_process_webhook', [ $payload ] )`
- [ ] Async handler: route `product.updated` → `Sellmo_Product_Sync`, `inventory.updated` → `Sellmo_Stock_Sync`

### Phase 6 – Sync Log UI

- [ ] Create `admin/views/page-sync-log.php`
- [ ] Implement `WP_List_Table` subclass backed by `Sellmo_Logger::get_logs()`
- [ ] Columns: Date, Type, Direction, Status, Message, Item ID
- [ ] Filter bar: type dropdown + status dropdown
- [ ] Pagination via `WP_List_Table::set_pagination_args()`
- [ ] "Clear log" action with nonce + capability check

### Phase 7 – Scheduler

- [ ] Implement `Sellmo_Scheduler`
- [ ] Register custom cron intervals via `cron_schedules` filter if needed
- [ ] On `init`: schedule `sellmo_wc_stock_sync` and `sellmo_wc_product_sync` if not already scheduled
- [ ] Reschedule when interval setting changes
- [ ] On deactivation: `wp_clear_scheduled_hook()` for both events (table preserved)
- [ ] Register deactivation hook in `sellmo-woocommerce.php`

### Phase 8 – i18n

- [ ] Add `load_plugin_textdomain( 'sellmo-woocommerce', false, SELLMO_WC_BASENAME . '/languages' )` in `Sellmo_Plugin::init()`
- [ ] Wrap all user-facing strings with `__()`, `_e()`, or `esc_html_e()`
- [ ] Generate `.pot` file via WP-CLI: `wp i18n make-pot . languages/sellmo-woocommerce.pot`

### Phase 9 – QA & Hardening

- [ ] Audit all form submissions: nonce present + `wp_verify_nonce()` or `check_admin_referer()`
- [ ] Audit all capability checks: `current_user_can( 'manage_woocommerce' )` on every admin action
- [ ] Audit all output: `esc_html()`, `esc_attr()`, `esc_url()` on every dynamic value
- [ ] Audit all DB queries: `$wpdb->prepare()` on every parameterised query
- [ ] Verify API token never appears in log entries, admin notices, or HTML source
- [ ] Test on WooCommerce minimum 7.0 (check `WC_VERSION` constant)
- [ ] Test deactivation does NOT drop `{prefix}sellmo_sync_log` table
- [ ] Test full uninstall drops table and removes all `sellmo_wc_*` options
- [ ] Check PHP error log for zero warnings/notices after activating
- [ ] Test "Sellmo POS requires WooCommerce" admin notice when WC is deactivated
