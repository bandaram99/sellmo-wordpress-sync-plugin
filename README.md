# Sellmo POS Integration

Sellmo POS Integration connects WooCommerce to **Sellmo POS** powered by SalesPlay POS. The plugin is designed to keep your store aligned with your point-of-sale system by importing products, syncing stock, pushing orders, and handling real-time webhooks.

This repository contains the WordPress plugin source, admin UI assets, sync handlers, and a small utility script used to export Sellmo product data during testing.

## What This Plugin Does

The plugin covers the full sync loop between WooCommerce and Sellmo:

- Import products from Sellmo into WooCommerce.
- Update product details, images, and variants when Sellmo changes.
- Sync stock levels from Sellmo to WooCommerce on a schedule.
- Push WooCommerce orders to Sellmo when they move to Processing.
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
| [sellmo-woocommerce.php](sellmo-woocommerce.php) | Main plugin bootstrap file. |
| [includes/](includes/) | Core plugin classes for admin, API, sync, scheduler, logs, and webhook handling. |
| [admin/](admin/) | Admin styles, scripts, and page templates. |
| [languages/](languages/) | Translation files. |
| [uninstall.php](uninstall.php) | Cleanup routine run when the plugin is deleted. |
| [export_all_products.py](export_all_products.py) | Local helper script for exporting Sellmo products to JSON. |
| [product.json](product.json) | Exported sample product payload used during testing. |

## Core Features

### Product Sync

Product sync imports or updates WooCommerce products from Sellmo. The sync supports:

- Simple products.
- Variable products with variants.
- Product images.
- SKU mapping and Sellmo product code linkage.
- Automatic updates when a linked Sellmo product changes.

### Stock Sync

Stock sync updates WooCommerce inventory levels from Sellmo on the configured schedule. It handles:

- Simple products.
- Variations under variable products.
- Shop-scoped stock quantities.
- Sync skipping for products that are not linked to Sellmo.

### Order Sync

Order sync sends WooCommerce Processing orders to Sellmo so POS staff can fulfil them. It is intended for merchants who want a single source of truth for online and in-store order handling.

### Webhooks

The webhook endpoint receives Sellmo notifications in real time. Supported events update the linked WooCommerce product or stock record immediately rather than waiting for the next scheduled sync.

### Admin Tools

The WordPress admin area includes:

- A dashboard with sync status and manual actions.
- A products browser for Sellmo catalogue items.
- A sync log page for reviewing historical runs.
- Settings for API token, shop selection, schedules, categories, and webhook configuration.

## Installation

### WordPress Admin

1. Download the plugin ZIP.
2. In WordPress admin, open Plugins → Add New → Upload Plugin.
3. Upload the ZIP and click Install Now.
4. Activate the plugin.
5. Open Sellmo POS → Settings and configure the API token.

### WP-CLI

If you manage WordPress from the command line, install the plugin like this:

```bash
wp plugin install sellmo-woocommerce.zip --activate
```

## Configuration

### 1. Get a Sellmo API token

1. Sign in to your Sellmo backoffice.
2. Open Settings → Integrations → API Access.
3. Copy the Bearer token.
4. If needed, generate a new token first.

Keep the token private. It grants access to your Sellmo data.

### 2. Connect the plugin

1. Open Sellmo POS → Settings.
2. Paste the token into the API Token field.
3. Save the settings.
4. Confirm the connection test passes.
5. Select the correct shop if your account has more than one.
6. Save again.

### 3. Choose sync options

| Feature | Direction | Trigger |
|---|---|---|
| Product Sync | Sellmo → WooCommerce | Cron schedule |
| Stock Sync | Sellmo → WooCommerce | Cron schedule |
| Order Sync | WooCommerce → Sellmo | Order moves to Processing |

Recommended schedule settings:

- Stock Sync Interval: Hourly.
- Product Sync Interval: Twice Daily or Daily.

### 4. Import products

1. Open Sellmo POS → Products.
2. Browse the Sellmo catalogue.
3. Import one product at a time, or use the bulk action.
4. Enable sync on the imported products you want kept in sync.

### 5. Configure the webhook

The webhook is optional, but recommended if you want near real-time updates.

1. Open Sellmo POS → Settings.
2. Generate a webhook secret.
3. Copy the webhook URL.
4. Add the webhook in Sellmo backoffice.
5. Use the events `product.updated` and `inventory.updated`.

Webhook endpoint:

```text
POST https://your-site.com/wp-json/sellmo-wc/v1/webhook
```

## How Sync Works

The plugin keeps a clear separation between data import and ongoing sync:

1. Products are imported from Sellmo and linked to WooCommerce by Sellmo code.
2. Each product can be toggled for sync individually.
3. Product sync updates names, prices, images, and variations.
4. Stock sync updates available quantity for linked products and variations.
5. Webhooks can trigger immediate updates when Sellmo changes.

## Testing Workflow

If you want to verify the sync end to end:

1. Clear WooCommerce products and the sync log.
2. Import a small sample from Sellmo.
3. Run product sync.
4. Run stock sync.
5. Review the sync log for any errors or skipped records.
6. Confirm imported items now appear in WooCommerce with matching SKU/product code values.

## Troubleshooting

### Products do not sync

- Confirm the API token is still valid.
- Check that the configured shop is correct.
- Review the sync log for API errors.
- Confirm the product has a matching Sellmo code or SKU link.

### Stock does not update

- Check whether the WooCommerce product is linked to Sellmo.
- For variable products, verify the variation codes match Sellmo variant codes.
- Confirm sync is enabled for that product.

### Webhooks are not firing

- Make sure the webhook URL in Sellmo matches the site URL exactly.
- Verify the secret is the same in both places.
- Ensure WordPress permalinks are not set to Plain.

### Products already exist in WooCommerce

- The plugin will only update products that it can match to Sellmo via Sellmo product code or, for compatibility, an existing matching SKU.

## Security Notes

- The API token is stored in WordPress options.
- The token is not logged by the plugin.
- Webhook requests are verified before processing.
- Admin actions are protected by WordPress capability checks and nonces.

## Changelog

### 1.0.0

- Product sync for simple and variable products.
- Stock sync for WooCommerce inventory.
- Order sync from WooCommerce to Sellmo.
- Webhook receiver for product and inventory updates.
- Admin dashboard, products browser, settings page, and sync log.
- WP-Cron integration for automated sync scheduling.

## Notes for Developers

- Product and stock matching rely on Sellmo product code metadata.
- Existing WooCommerce products can be linked through SKU compatibility during sync.
- Generated exports such as [product.json](product.json) are useful for debugging the Sellmo payload shape.
