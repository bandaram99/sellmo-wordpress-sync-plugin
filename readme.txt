=== Sellmo POS Integration ===
Contributors: sellmo
Tags: sellmo, pos, point of sale, salesplay, woocommerce, product sync, stock sync, order sync
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0
WC tested up to: 9.0

Connects WooCommerce with Sellmo POS — sync products, stock levels, and orders between your online store and point-of-sale system.

== Description ==

**Sellmo POS Integration** links WooCommerce with Sellmo POS (powered by SalesPlay POS), keeping your online store and physical point-of-sale system in sync.

= Features =

* **Product sync** — Import and update WooCommerce products from Sellmo, including variable products with variants, prices, descriptions, and images.
* **Stock sync** — Keep WooCommerce inventory levels current with Sellmo POS on a configurable cron schedule.
* **Order sync** — Push WooCommerce orders to Sellmo automatically when status moves to Processing.
* **Webhook receiver** — Accept real-time push notifications from Sellmo for instant product and stock updates.
* **Selective sync** — Choose which Sellmo products to sync into WooCommerce via the Products page.
* **Sync log** — Filterable, paginated log of every sync operation with CSV export and auto-refresh.
* **Dashboard** — Connection status, last-run times, and manual "Sync Now" buttons.

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* A Sellmo / SalesPlay POS account with API access

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Sellmo POS → Settings** and enter your Sellmo API token.

= Getting your API token =

1. Log in to your Sellmo backoffice.
2. Go to **Settings → Integrations → API Access**.
3. Copy the Bearer token. Click **Generate Token** if none exists.

== Configuration ==

**Step 1 — Connect**

1. Go to **Sellmo POS → Settings**.
2. Paste your API token and click **Save Settings**.
3. A green notice confirms the connection. Select your **Shop** from the dropdown.
4. Save again.

**Step 2 — Enable sync features**

Under **Sync Features**, check Product Sync, Stock Sync, and/or Order Sync.

Under **Sync Schedule**, choose your preferred intervals.

**Step 3 — Import products**

1. Go to **Sellmo POS → Products**.
2. Click **Import to WooCommerce** on each product you want in your store.
3. Toggle the **Sync** switch to keep each product updated automatically.

**Step 4 — Set up the webhook (recommended)**

1. Go to **Sellmo POS → Settings → Webhook Configuration**.
2. Click **Generate** to create a secure secret, then **Save Settings**.
3. Click **Copy** next to the Webhook URL.
4. In Sellmo backoffice, go to **Settings → Integrations → Webhooks → Add Webhook**.
5. Paste the URL and secret, select `product.updated` and `inventory.updated`, and save.

== Frequently Asked Questions ==

= What happens if I save settings without entering a new token? =

The existing token is kept. Leave the API Token field blank to preserve the saved value.

= Does the plugin delete WooCommerce products removed from Sellmo? =

No. The plugin only creates and updates products. Deletions must be done manually in WooCommerce.

= My stock sync shows "Skipped" for variable products. =

Variable parent products do not hold stock — only their variations do. Ensure each variation has a matching `_sellmo_product_code` meta value linked to the correct Sellmo variant code.

= Webhook events are not being processed. =

Check: (1) the Webhook URL and secret in the plugin match what is configured in Sellmo; (2) WordPress permalinks are not set to Plain (go to Settings → Permalinks and click Save to flush rewrite rules).

= How do I remove all plugin data? =

Deactivate the plugin to remove scheduled events while keeping your data. To remove all data including the sync log, deactivate and then **Delete** the plugin from the Plugins list.

= Is the API token stored securely? =

The token is stored as a WordPress option. It is never logged, never included in AJAX responses, and never exposed in rendered HTML.

== Screenshots ==

1. Dashboard — connection status, sync summaries, and manual sync buttons.
2. Settings — API token, shop selection, sync toggles, schedule, and webhook configuration.
3. Products — browse Sellmo catalogue, import to WooCommerce, toggle sync per product.
4. Sync Log — filterable, paginated activity log with CSV export.

== Changelog ==

= 1.0.0 =
* Initial release.
* Product sync: import and update WooCommerce products from Sellmo, including variable products with variants, images, and prices.
* Stock sync: scheduled inventory synchronisation from Sellmo to WooCommerce.
* Order sync: automatic push of WooCommerce Processing orders to Sellmo POS, with manual re-sync from the order edit screen.
* Webhook receiver for real-time `product.updated` and `inventory.updated` events.
* Admin dashboard, Products page, and Sync Log.
* WP-Cron integration with automatic rescheduling.
* HPOS (High-Performance Order Storage) compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
