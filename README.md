# {{BRAND_NAME}} POS Integration

{{BRAND_NAME}} POS Integration connects WooCommerce to **{{BRAND_NAME}} POS** powered by SalesPlay POS. The plugin is designed to keep your store aligned with your point-of-sale system by importing products, syncing stock, pushing orders, and handling real-time webhooks.

This repository contains the WordPress plugin source, admin UI assets, sync handlers, and a small utility script used to export {{BRAND_NAME}} product data during testing.

## What This Plugin Does

The plugin covers the full sync loop between WooCommerce and {{BRAND_NAME}}:

- Import products from {{BRAND_NAME}} into WooCommerce.
- Update product details, images, and variants when {{BRAND_NAME}} changes.
- Sync stock levels from {{BRAND_NAME}} to WooCommerce on a schedule.
- Push WooCommerce orders to {{BRAND_NAME}} when they move to Processing, addressed to the POS terminal you choose.
- Pull each pushed order's status (Completed, Rejected, …) back into WooCommerce on a schedule.
- Map existing WooCommerce products to {{BRAND_NAME}} products by SKU, manually or automatically (Auto Map).
- Receive webhook callbacks for real-time product and inventory updates.
- Keep an activity log so sync runs are easy to audit.

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 8.0 |

## Repository Structure

| Path | Purpose |
|---|---|
| [{{BRAND_SLUG}}-woocommerce.php]({{BRAND_SLUG}}-woocommerce.php) | Main plugin bootstrap file. |
| [includes/](includes/) | Core plugin classes for admin, API, sync, scheduler, logs, and webhook handling. |
| [admin/](admin/) | Admin styles, scripts, and page templates. |
| [languages/](languages/) | Translation files. |
| [uninstall.php](uninstall.php) | Cleanup routine run when the plugin is deleted. |
| [export_all_products.py](export_all_products.py) | Local helper script for exporting {{BRAND_NAME}} products to JSON. |
| [product.json](product.json) | Exported sample product payload used during testing. |

## Core Features

### Product Sync

Product sync imports or updates WooCommerce products from {{BRAND_NAME}}. The sync supports:

- Simple products.
- Variable products with variants.
- Product images.
- SKU mapping and {{BRAND_NAME}} product code linkage.
- Automatic updates when a linked {{BRAND_NAME}} product changes.

### Stock Sync

Stock sync updates WooCommerce inventory levels from {{BRAND_NAME}} on the configured schedule. It handles:

- Simple products.
- Variations under variable products.
- Shop-scoped stock quantities.
- Sync skipping for products that are not linked to {{BRAND_NAME}}.

### Order Sync

Order sync sends WooCommerce Processing orders to {{BRAND_NAME}} so POS staff can fulfil them. It is intended for merchants who want a single source of truth for online and in-store order handling.

- **Terminal routing.** Each order is sent (`POST /online_orders`) to the shop selected in Settings with `target_terminal` set to the **one terminal** chosen in the Terminal dropdown. Other terminals in the shop do not receive it. Change the terminal and the next order goes to the new one.
- **Status pull-back.** On the Order Status Interval, the plugin asks {{BRAND_NAME}} what happened to each pushed order and moves the WooCommerce order to match (for example Completed or Rejected).
- **Order Time Zone.** The order date and time sent to {{BRAND_NAME}} use the WordPress time zone unless you pick another in Settings.

### Product Mapping and Auto Map

The Product Mapper page links {{BRAND_NAME}} products to WooCommerce products that already exist, so they are updated instead of duplicated. Sync is one-way ({{BRAND_NAME}} → WooCommerce): new {{BRAND_NAME}} products stay staged on the mapper until you import or map them.

With **Auto Map** on, every product sync links an unmapped {{BRAND_NAME}} product to the WooCommerce product whose SKU equals its product code:

- If a code or SKU matches more than one product, nothing is mapped.
- A variable product is mapped only when every variant matches a variation of the same WooCommerce product.
- Once mapped, price, category and stock come from {{BRAND_NAME}}.

Auto Map is off by default.

When a mapped {{BRAND_NAME}} product is deleted or leaves the selected shop, its WooCommerce product is deleted only if Auto Map is on **and** its SKU still equals the POS product code. Otherwise it is kept as an ordinary, unmapped product. Deleting or trashing a mapped product in WooCommerce only unmaps it (the row goes back to "Not Mapped"). Nothing is sent to {{BRAND_NAME}}.

### Webhooks

The webhook endpoint receives {{BRAND_NAME}} notifications in real time. Supported events update the linked WooCommerce product or stock record immediately rather than waiting for the next scheduled sync.

### Admin Tools

The WordPress admin area includes:

- A dashboard with sync status and manual actions.
- A products browser for {{BRAND_NAME}} catalogue items.
- A Product Mapper for linking {{BRAND_NAME}} products to existing WooCommerce products.
- A sync log page for reviewing historical runs.
- Settings for API token, shop and terminal selection, Auto Map, order time zone, schedules, and categories.

## Installation

### WordPress Admin

1. Download the plugin ZIP.
2. In WordPress admin, open Plugins → Add New → Upload Plugin.
3. Upload the ZIP and click Install Now.
4. Activate the plugin.
5. Open {{BRAND_NAME}} POS → Settings and configure the API token.

### WP-CLI

If you manage WordPress from the command line, install the plugin like this:

```bash
wp plugin install {{BRAND_SLUG}}-woocommerce.zip --activate
```

## Configuration

### 1. Get a {{BRAND_NAME}} API token

1. Sign in to your {{BRAND_NAME}} backoffice.
2. Open Settings → Integrations → API Access.
3. Copy the Bearer token.
4. If needed, generate a new token first.

Keep the token private. It grants access to your {{BRAND_NAME}} data.

### 2. Connect the plugin

Setup takes a single Save:

1. Open {{BRAND_NAME}} POS → Settings.
2. Paste the token into the API Token field and click **Connect**. This tests the token you typed and loads your shops without saving anything yet. On first setup it also ticks Product, Stock and Order Sync for you. Untick any you don't want.
3. Select the **Shop**. If your account has only one shop, it is selected for you.
4. Select the **Terminal** that should receive online orders. The dropdown lists every terminal of the selected shop. If the shop has only one terminal, it is selected for you. If the shop has none, the dropdown says "No terminals in this shop".
5. Click **Save Settings**.

### Changing or removing the token

- **Replacing a saved token** with a different one clears the old account's catalogue, so Settings asks you to tick a confirmation box first. The box shows how many imported products and mappings will be removed.
- **Removing the token** clears mappings and stored stock data and stops all scheduled syncs. Imported products are deleted only when Auto Map is on and their SKU still matches the POS product code. All other products are kept as ordinary WooCommerce products. To delete linked products as well, tick "Also delete your existing WooCommerce products that were linked to the POS".

Scheduled syncs only run while a token is saved. Without one they are unscheduled rather than failing every run.

### 3. Choose sync options

| Feature | Direction | Trigger |
|---|---|---|
| Product Sync | {{BRAND_NAME}} → WooCommerce | Cron schedule |
| Stock Sync | {{BRAND_NAME}} → WooCommerce | Cron schedule |
| Order Sync | WooCommerce → {{BRAND_NAME}} (selected terminal) | Order moves to Processing |
| Order Status | {{BRAND_NAME}} → WooCommerce | Cron schedule (Order Status Interval) |

Recommended schedule settings:

- Stock Sync Interval: Hourly.
- Product Sync Interval: Twice Daily or Daily.

### 4. Import products

1. Open {{BRAND_NAME}} POS → Products.
2. Browse the {{BRAND_NAME}} catalogue.
3. Import one product at a time, or use the bulk action.
4. Enable sync on the imported products you want kept in sync.

### 5. Configure the webhook

The webhook is optional, but recommended if you want near real-time updates.

1. Open {{BRAND_NAME}} POS → Settings.
2. Generate a webhook secret.
3. Copy the webhook URL.
4. Add the webhook in {{BRAND_NAME}} backoffice.
5. Use the events `product.updated` and `inventory.updated`.

Webhook endpoint:

```text
POST https://your-site.com/wp-json/sellmo-wc/v1/webhook
```

## How Sync Works

The plugin keeps a clear separation between data import and ongoing sync:

1. Products are imported from {{BRAND_NAME}} and linked to WooCommerce by {{BRAND_NAME}} code.
2. Each product can be toggled for sync individually.
3. Product sync updates names, prices, images, and variations.
4. Stock sync updates available quantity for linked products and variations.
5. Webhooks can trigger immediate updates when {{BRAND_NAME}} changes.

## Testing Workflow

If you want to verify the sync end to end:

1. Clear WooCommerce products and the sync log.
2. Import a small sample from {{BRAND_NAME}}.
3. Run product sync.
4. Run stock sync.
5. Review the sync log for any errors or skipped records.
6. Confirm imported items now appear in WooCommerce with matching SKU/product code values.

## Troubleshooting

### Products do not sync

- Confirm the API token is still valid.
- Check that the configured shop is correct.
- Review the sync log for API errors.
- Confirm the product has a matching {{BRAND_NAME}} code or SKU link.

### Stock does not update

- Check whether the WooCommerce product is linked to {{BRAND_NAME}}.
- For variable products, verify the variation codes match {{BRAND_NAME}} variant codes.
- Confirm sync is enabled for that product.

### Orders do not appear on the POS terminal

- Check Settings → Terminal: orders go only to the terminal selected there.
- If the Terminal dropdown says "No terminals in this shop", add a terminal to that shop in {{BRAND_NAME}} backoffice, then reload Settings.
- Confirm Order Sync is enabled and the order has reached Processing.
- Review the sync log for the order push result.

### Webhooks are not firing

- Make sure the webhook URL in {{BRAND_NAME}} matches the site URL exactly.
- Verify the secret is the same in both places.
- Ensure WordPress permalinks are not set to Plain.

### Products already exist in WooCommerce

- The plugin will only update products that it can match to {{BRAND_NAME}} via {{BRAND_NAME}} product code or, for compatibility, an existing matching SKU.

## Security Notes

- The API token is stored in WordPress options.
- The token is not logged by the plugin.
- Webhook requests are verified before processing.
- Admin actions are protected by WordPress capability checks and nonces.

## Changelog

### Unreleased

- Terminal dropdown in Settings lists every terminal of the selected shop. Online orders are sent only to the chosen terminal (`target_terminal`).
- Order status is pulled back from {{BRAND_NAME}} on a configurable interval. The Order Time Zone setting controls the time zone of order times sent to {{BRAND_NAME}}.
- Auto Map links {{BRAND_NAME}} products to existing WooCommerce products by SKU.
- One-pass setup: Connect tests the typed token and loads shops in one step. Replacing a saved token requires confirmation.
- Removing the token deletes only imported products whose SKU matches the POS code while Auto Map is on, and keeps the rest unless you opt in. Syncs stop until a token is saved again.
- Deleting or trashing a mapped product in WooCommerce unmaps it instead of leaving a broken link.

### 1.0.0

- Product sync for simple and variable products.
- Stock sync for WooCommerce inventory.
- Order sync from WooCommerce to {{BRAND_NAME}}.
- Webhook receiver for product and inventory updates.
- Admin dashboard, products browser, settings page, and sync log.
- WP-Cron integration for automated sync scheduling.

## Notes for Developers

- Product and stock matching rely on {{BRAND_NAME}} product code metadata.
- Existing WooCommerce products can be linked through SKU compatibility during sync.
- Generated exports such as [product.json](product.json) are useful for debugging the {{BRAND_NAME}} payload shape.
- [tests/](tests/) holds an integration suite that boots the local WordPress install with the POS API stubbed. Run it with `php tests/run-all.php`. `build.php` runs it before packaging. `test-21-terminal-dropdown.php` runs the real admin JS in headless Microsoft Edge. `test-22-order-target-terminal.php` places real orders and checks each one is sent only to the selected terminal.
