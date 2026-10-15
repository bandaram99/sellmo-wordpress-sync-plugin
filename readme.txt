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

== External services ==

This plugin connects to the {{BRAND_NAME}} POS backend to synchronise your store data. This connection is required for the plugin to function — without it, no products, stock, or orders can be synced.

Requests are made to: {{API_BASE}}

What is sent, and when:

* Product, variation, stock and price data — imported from {{BRAND_NAME}} POS into WooCommerce, and pushed from WooCommerce to {{BRAND_NAME}} POS, on the schedule you configure or when you trigger a manual sync.
* WooCommerce order details (line items, totals, and the customer name and contact on the order) — sent to {{BRAND_NAME}} POS when an order moves to Processing, if order sync is enabled.
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
* Current release.


C:\xampp3\php\php.exe build.php salesplay
C:\xampp3\php\php.exe build.php sellmo
C:\xampp3\php\php.exe build.php vendrex