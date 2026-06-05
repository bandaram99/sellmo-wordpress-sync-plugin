# Sellmo POS — upgrade wiring

Five new files. Drop the four `includes/` classes into `includes/` and the JS into `admin/js/`.

```
includes/class-sellmo-sync-guard.php             (try-catch + HTTP logging utility)
includes/class-sellmo-product-mapper-store.php   (mapper staging table)
includes/class-sellmo-product-mapper-sync.php    (incremental/delta sync)
includes/class-sellmo-mapper-ajax.php            (Select2 AJAX handlers)
includes/class-sellmo-stock-sync-pagination.php  (stock pagination + CRUD)
admin/js/sellmo-mapper-select2.js                (selectWoo init + validation)
```

## 1. Load the classes

Wherever the plugin requires its other `includes/class-*.php` files (the bootstrap before `Sellmo_Plugin::get_instance()`), add:

```php
require_once SELLMO_WC_PATH . 'includes/class-sellmo-sync-guard.php';
require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapper-store.php';
require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapper-sync.php';
require_once SELLMO_WC_PATH . 'includes/class-sellmo-mapper-ajax.php';
require_once SELLMO_WC_PATH . 'includes/class-sellmo-stock-sync-pagination.php';
```

## 2. Create the staging table on activation

In `sellmo_wc_activate()` (in `sellmo-woocommerce.php`):

```php
require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapper-store.php';
Sellmo_Product_Mapper_Store::create_table();
```

In `uninstall.php` add `Sellmo_Product_Mapper_Store::drop_table();`.

## 3. Register the AJAX module

In `Sellmo_Plugin::init()`:

```php
$this->mapper_ajax = new Sellmo_Mapper_Ajax();
```

## 4. Point "Sync Products Now" at the delta sync (Req 1)

In `Sellmo_Admin::ajax_sync_products_now()` swap the sync call:

```php
// OLD:
// $sync   = new Sellmo_Product_Sync();
// $result = $sync->run_ajax_trace_sync();

// NEW — delta into the mapper table, never overwrites WooCommerce:
$sync   = new Sellmo_Product_Mapper_Sync();
$result = $sync->run_incremental_sync();
```

The product → WooCommerce push stays where it is: the user maps in the UI, then
the existing `import_product()` / Map action writes to WooCommerce.

## 5. Point "Sync Stock Now" at the paginated sync (Req 4)

In `Sellmo_Admin::ajax_sync_stock_now()`:

```php
$sync   = new Sellmo_Stock_Sync_Pagination();
$result = $sync->run();
```

## 6. Mapper table markup for Select2 (Req 2)

In the row builder in `admin/js/sellmo-admin.js`, replace the hand-rolled
`.sellmo-wc-custom-select` block with a plain select that selectWoo upgrades:

```js
// WooCommerce column (Sellmo rows):
var wcSelect = '<select class="sellmo-wc-select2 sellmo-mapper-wc-select" style="width:100%"></select>';

// Sellmo column (WC-only rows): replace the static sellmoSel <select> with:
var sellmoSel = '<select class="sellmo-sellmo-select2 sellmo-mapper-sellmo-select" style="width:100%"></select>';
```

After appending rows, fire the re-init event so the dropdowns initialise:

```js
$( document ).trigger( 'sellmo:mapper-rendered' );
```

The server no longer needs to ship `wc_products` / `sellmo_products` arrays in
`ajax_get_mapper_list` — Select2 pulls them on demand from the two new endpoints.

## 7. Dashboard badge accuracy (Req 3)

In `admin/views/page-dashboard.php`, the Product Sync "Last result" block prints
`Error (%d failed)` using the synced count, so a clean run with errors can read
"Error (0 failed)". Gate the badge on a real failure count:

```php
$p_status = (string) ( $product_meta['status'] ?? '' );
$p_count  = absint( $product_meta['count'] ?? 0 );

if ( 'error' === $p_status ) : ?>
    <span class="sellmo-result-badge error">
        <?php printf( esc_html__( 'Error — %d synced before failure', 'sellmo-woocommerce' ), $p_count ); ?>
    </span>
<?php else : ?>
    <span class="sellmo-result-badge success">
        <?php printf( esc_html__( 'Success (%d synced)', 'sellmo-woocommerce' ), $p_count ); ?>
    </span>
<?php endif;
```

`Sellmo_Sync_Guard` (Req 3) is already used internally by the delta and stock
syncs: every API call routes through `Sellmo_Sync_Guard::log_response()`, which
captures the HTTP code + response body on failure, and the whole run is wrapped
in `Sellmo_Sync_Guard::run()` so a thrown error is logged and normalised rather
than fatalling.

## Note on the auth header / GET-with-body

The new code follows the SalesPlay contract literally: `wp_remote_request()` with
`method => 'GET'` and a `wp_json_encode()` body, header `token: Bearer …`. The
existing `class-sellmo-product-sync.php` sends the same params as a query string
instead. Both are in the wild — if the API rejects a body-less GET in one path,
align them. Header name is case-insensitive (`token` vs `Token`), so either works.
