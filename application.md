Here's the complete guide — how the system works end to end, how to run it, and the direct answer to your includes/ question.

1. The big picture: two layers of branding
Your plugin is branded in two independent ways, and it's important to know which does what:

Layer	When it runs	What it controls	Driven by
Build-time templating	Once, when you run php build.php	The static plugin-header comment, readme.txt, README.md, the main filename	{{PLACEHOLDERS}} filled from .env.<brand>
Runtime config	Every WordPress page load	UI strings, menu name, table/option/meta names, logos, URLs	The .env shipped inside the zip, read by POS_Brand
Why two layers? WordPress reads the Plugin Name: / Author: header comment before any PHP executes, so those literals cannot be set at runtime — they must be baked in at build time. Everything else (menu labels, DB table prefixes, etc.) can be set at runtime, and already is, by POS_Brand reading the .env file.

build.php is only responsible for the things runtime can't reach, plus dropping the correct .env into the package so the runtime layer knows which brand it is.

2. The files involved

sellmo-wordpress-sync-plugin/
├── build.php              ← the build script (you run this)
├── .env.salespay          ← SalesPay brand profile
├── .env.sellmo            ← Sellmo brand profile
├── .env                   ← DEV-ONLY runtime config (used when running from the repo, NOT shipped)
├── sellmo-woocommerce.php ← main file, header is now a {{template}}
├── readme.txt             ← WordPress.org readme {{template}}
├── README.md              ← docs {{template}}
├── includes/              ← all plugin logic (copied verbatim into every build)
├── admin/                 ← CSS/JS/views (copied verbatim)
├── languages/             ← translations (copied verbatim)
├── uninstall.php          ← copied verbatim
└── build/                 ← OUTPUT — regenerated every run, git-ignored
    ├── salespay-woocommerce/      (staged folder)
    ├── salespay-woocommerce.zip   (installable package)
    ├── sellmo-woocommerce/
    └── sellmo-woocommerce.zip
Each .env.<brand> has two blocks:

BUILD block (PLUGIN_NAME, VERSION, AUTHOR, …) → fills the {{PLACEHOLDERS}}.
BRAND_ block (BRAND_NAME, BRAND_SLUG, …) → shipped verbatim as the package's runtime .env.
3. What php build.php salespay does internally, step by step
Reading build.php top to bottom, one run does exactly this:

Reject web access — exits unless run from the CLI.
Read the brand argument (salespay). With no argument, it lists available brands and exits.
Locate .env.salespay. If it doesn't exist → error listing valid brands. (This is your "validate brand exists" requirement.)
Parse the brand file into key=value pairs (comments and quotes stripped).
Derive the slug from BRAND_SLUG (sanitized to [a-z0-9_]). This becomes the package name salespay-woocommerce.
Build the placeholder map — DEFAULTS, then every non-empty key from the brand file layered on top.
Wipe and re-create build/salespay-woocommerce/ (clean staging every time).
Copy the whole repo root into the staging folder — except the EXCLUDE list (.git, build, build.php, node_modules, vendor, all .env* files, etc.). This is the step that matters for your question — see §5.
Replace {{PLACEHOLDERS}} in every .php, .txt, and .md file in the staging copy. Files without {{ are skipped untouched.
Write the package .env from the brand file's BRAND_* lines, so the runtime layer identifies as SalesPay.
Rename the main file sellmo-woocommerce.php → salespay-woocommerce.php.
Zip the staging folder into build/salespay-woocommerce.zip, with the plugin folder as the top-level entry (what WordPress expects). Uses ZipArchive, or falls back to PharData (your XAMPP CLI has zip disabled).
Print the result and the install hint.
The working tree is never modified — everything happens in build/.

4. How to execute it
Open a terminal in the plugin root and run:


# Build one brand
php build.php salespay

# Build the other
php build.php sellmo

# See available brands (bare invocation)
php build.php
Output lands in build/salespay-woocommerce.zip and build/sellmo-woocommerce.zip.

Install into WordPress:

WP admin → Plugins → Add New → Upload Plugin
Choose build/salespay-woocommerce.zip → Install Now → Activate
Or via WP-CLI:


wp plugin install build/salespay-woocommerce.zip --activate
Adding a third brand later — no code changes needed:

Copy .env.salespay → .env.acme
Edit the values
php build.php acme → build/acme-woocommerce.zip
5. Your core question: change non-brand code in includes/, then build — does it reflect?
Yes. Automatically. In every branded build.

Here's the exact mechanism. Step 8 above copies the entire repo root fresh on every run, and includes/ is not in the exclude list:


copy_tree(ROOT, staging)   →   copies includes/, admin/, languages/, uninstall.php, …
So the flow for a non-brand code change (say you fix a bug in class-sellmo-api.php, the file you have open) is:

Edit includes/class-sellmo-api.php in the repo root. Save.
php build.php salespay
php build.php sellmo
Both zips now contain your edited class-sellmo-api.php — identical code, different branding.
There is one single source of truth (the repo root). You never edit anything inside build/ — it's overwritten on the next run. You never maintain a separate copy per brand. Fix a bug once → every brand gets it on the next build.

Rules for editing includes/ (and admin/, uninstall.php):

✅ Edit the file in the repo root, never in build/.
✅ Rebuild after editing (build/ is a snapshot, not live).
✅ Pure logic/bugfix/feature changes flow through verbatim — no placeholders needed.
⚠️ Only add a {{PLACEHOLDER}} if the value genuinely differs per brand. For non-brand code, just write normal code — it's copied as-is.
⚠️ Don't introduce a literal {{ into a .php/.txt/.md file unless you mean it as a placeholder (the templater would try to fill it).
Concrete example — say you add a retry to the API client:


[edit] includes/class-sellmo-api.php   (add retry logic — no brand strings)
  │
  ├─ php build.php salespay → salespay-woocommerce.zip  (has retry)
  └─ php build.php sellmo   → sellmo-woocommerce.zip    (has retry)
Both packages get the retry. Zero per-brand duplication.

6. Quick reference: what to edit for what change
You want to change…	Edit this	Then
Bug fix / new feature / any logic	the file in includes/ (or admin/)	rebuild both brands
A visible brand name, URL, logo, email	.env.<brand> BRAND_ block	rebuild that brand
Plugin header title / author / version	.env.<brand> BUILD block	rebuild that brand
Add a whole new brand	new .env.<newbrand>	php build.php newbrand
A value that's the same for all brands but appears in the header	DEFAULTS in build.php	rebuild
A value that differs per brand in a code file	add a {{KEY}} placeholder + set KEY in each .env.<brand>	rebuild
7. Gotchas worth knowing
build/ is disposable. It's git-ignored and wiped each run. Never edit or commit it.
The repo .env is dev-only. When you run the plugin directly from the repo (not from a built zip), POS_Brand reads the root .env. The built packages instead carry their own generated .env. The root .env is never shipped.
The unbuilt source header shows {{PLUGIN_NAME}}. That's expected — the header is a template now. WordPress still loads it fine; only the built packages have real values.
Text domain is intentionally frozen to sellmo-woocommerce across all brands (it must match the __() calls baked into the PHP). This is correct for translations, not a bug — see the README "WordPress limitations" note.
Include filenames stay class-sellmo-*.php in every brand. They're internal — the main file requires them by those names, so renaming them would break the requires and buys nothing.
TL;DR on your question: edit includes/ in the repo root, run php build.php salespay and php build.php sellmo, and both zips pick up your change — one codebase, many brands.

c:\xampp3\htdocs\sellmo-wordpress-sync-plugin\includes\class-sellmo-api.php
What are the APIs available here? full api

Read c:\xampp3\htdocs\sellmo-wordpress-sync-plugin\includes\class-sellmo-api.php
Two things sit inside class-sellmo-api.php: the public PHP methods other classes call, and the SalesPlay REST endpoints those methods hit. Here's the full picture.

Public API — the methods you call
Method	HTTP	Endpoint	Cached	Purpose
__construct()	—	—	—	Reads the API token from Sellmo_Settings::get('api_token')
get_shops()	GET	/shops	No	All shops on the account — used to populate the settings shop dropdown
get_categories()	GET	/categories	5 min	All product categories
get_products( $cursor = '' )	GET	/products	5 min	One page of products (250/page), cursor-paginated
get_inventory( $cursor = '' )	GET	/inventory	1 min	One page of stock levels (250/page), cursor-paginated
create_order( array $order_data )	POST	/online_orders	No	Push a WooCommerce order to the POS
That's the complete public surface — 6 methods (5 calls + constructor). Everything else in the file is private plumbing.

Private helpers (not callable from outside)
Method	Role
make_request()	Standard request path via wp_remote_get/post — used by /shops, /categories, /online_orders
request_get_with_body()	Special GET-with-body path via WP's bundled WpOrg\Requests — used by /products and /inventory (see note below)
resolve_sync_type_from_endpoint()	Maps endpoint → log category (order/stock/product/webhook)
truncate_for_log()	Caps log messages at 500 chars
The external REST API it talks to
Base URL: https://api.salesplaypos.com/v1.0 (API_BASE)

Endpoint	Method	Params
/shops	GET	none
/categories	GET	none
/products	GET	body: product_ids, created_at_min/max, limit=250, cursor
/inventory	GET	body: product_ids, shop_ids, limit=250, cursor
/online_orders	POST	JSON order payload

Cross-cutting behavior (applies to all calls)
Auth: Bearer token, but with two different header names depending on the path — make_request sends Authorization: Bearer <token> (L80); request_get_with_body sends token: Bearer <token> (L366). Not a typo — the SalesPlay body-cursor endpoints expect the token header.
Rate limiting: 300 req / 300 s. On HTTP 429 it backs off 2^attempt seconds and retries up to max_retries = 3. After 3 failures → WP_Error('sellmo_rate_limited').
Errors: connection failures and non-2xx return a WP_Error (sellmo_api_error / sellmo_http_error / sellmo_invalid_json); the raw response body is used as the message so callers see the exact API detail.
Token safety: the token is never logged — request args are deliberately kept out of Sellmo_Logger.
The /products + /inventory quirk (L346-L361): those two only paginate when cursor+limit are sent in the body of a GET. WordPress's wp_remote_* forces GET data into the query string (which the API ignores, so the cursor never advances), so those two bypass it and call the Requests library directly with data_format => 'body'.
In short: 5 callable API operations — get_shops, get_categories, get_products, get_inventory, create_order — over 5 SalesPlay REST endpoints, all funneled through one of two private request methods.