=== {{PLUGIN_NAME}} ===
Contributors: {{CONTRIBUTORS}}
Tags: woocommerce, pos, inventory, sync
Requires at least: {{WP_VERSION}}
Tested up to: {{WP_TESTED_VERSION}}
Requires PHP: {{PHP_VERSION}}
Stable tag: {{VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

{{PLUGIN_DESCRIPTION}}

== Description ==

{{PLUGIN_DESCRIPTION}}

Product sync, stock sync, and order sync between WooCommerce and your POS.

* Import products, variations, prices, categories and stock from {{BRAND_NAME}} POS.
* Map existing WooCommerce products to POS products by SKU, by hand or automatically (Auto Map).
* Send online orders to the POS terminal you choose, and pull each order's status back into WooCommerce.

== External services ==

This plugin connects to the {{BRAND_NAME}} POS backend to synchronise your store data. This connection is required for the plugin to function — without it, no products, stock, or orders can be synced.

Requests are made to: {{API_BASE}}

What is sent, and when:

* Product, variation, stock and price data — requested from {{BRAND_NAME}} POS and imported into WooCommerce, on the schedule you configure or when you trigger a manual sync. Product data only flows from {{BRAND_NAME}} POS to WooCommerce; no product or stock data is sent from your store.
* WooCommerce order details (line items, totals, and the customer name and contact on the order), together with the shop and terminal ID selected in the settings — sent to {{BRAND_NAME}} POS when an order moves to Processing, if order sync is enabled. The order's status is then requested back from {{BRAND_NAME}} POS on the schedule you configure.
* The API token you enter in the plugin settings — sent with every request to authenticate your store.

By using this plugin you agree to the service provider's terms and privacy policy:

* Terms of Use: {{TERMS_URL}}
* Privacy Policy: {{PRIVACY_URL}}

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Configure the POS integration under the {{BRAND_NAME}} menu.

== Changelog ==

= {{VERSION}} =
* New: Terminal setting. Choose which POS terminal receives online orders; orders go only to that terminal.
* New: Order status is pulled back from the POS on a configurable interval; Order Time Zone setting.
* New: Auto Map links POS products to existing WooCommerce products by SKU.
* Improved: one-pass setup — Connect loads your shops before the first save; replacing a token asks for confirmation.
* Improved: removing the token keeps your products unless they were imported with a matching SKU while Auto Map is on, or you opt in to delete them.
* Fix: deleting a mapped product in WooCommerce now unmaps it.


C:\xampp3\php\php.exe build.php salesplay
C:\xampp3\php\php.exe build.php sellmo
C:\xampp3\php\php.exe build.php vendrex