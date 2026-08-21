<?php
/**
 * Shared test harness for the integration suites.
 *
 * Boots the real WordPress install, then provides:
 *   - assertions with a pass/fail tally and a non-zero exit code
 *   - a fake POS API bound to WordPress' own `pre_http_request` filter, so the
 *     whole sync stack runs offline against canned payloads (no mock library)
 *   - a sync-log watermark so each scenario can assert what it logged
 *   - a cleanup registry that runs even on a fatal, so no test data is left behind
 *
 * Every fixture this harness creates is namespaced with ZZTEST so it can never
 * collide with, or be mistaken for, real catalogue data.
 *
 * Not a test itself — required by the test-*.php files.
 */

declare(strict_types=1);

const T_WP_LOAD = 'c:/xampp3/htdocs/wordpress/wp-load.php';
const T_PREFIX  = 'ZZTEST';

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

require_once T_WP_LOAD;

/**
 * Bridge the source class names onto whatever brand is actually installed.
 *
 * The suites are written against the source naming (Sellmo_*, POS_Brand,
 * SELLMO_WC_*, sellmo_wc_* hooks). A BUILT package renames all of those to its
 * own brand prefix so two packages can coexist (see build.php), which means the
 * site may be running SalesPlay_Product_Mapping rather than Sellmo_*.
 *
 * Rather than rewrite every assertion, alias the source names onto the loaded
 * ones. The suites then run unchanged against the source tree OR any built
 * brand — which is the more useful guarantee, since it is the built package
 * that actually ships.
 */
function t_bridge_brand_names(): void {
	if ( class_exists( 'Sellmo_Product_Mapping' ) ) {
		return; // Source naming — nothing to bridge.
	}

	$prefix = '';
	foreach ( get_declared_classes() as $class ) {
		if ( preg_match( '/^(.+)_Product_Variant_Store$/', $class, $m ) ) {
			$prefix = $m[1];
			break;
		}
	}

	if ( '' === $prefix ) {
		fwrite( STDERR, "Plugin not active — activate it before running the tests.
" );
		exit( 1 );
	}

	foreach ( get_declared_classes() as $class ) {
		if ( 0 !== strpos( $class, $prefix . '_' ) ) {
			continue;
		}
		$alias = 'Sellmo_' . substr( $class, strlen( $prefix ) + 1 );
		if ( ! class_exists( $alias, false ) ) {
			class_alias( $class, $alias );
		}
	}

	// The brand engine keeps its generic source name in the tests.
	foreach ( [ '_Brand' => 'POS_Brand', '_Brand_Migrator' => 'POS_Brand_Migrator' ] as $suffix => $alias ) {
		if ( class_exists( $prefix . $suffix ) && ! class_exists( $alias, false ) ) {
			class_alias( $prefix . $suffix, $alias );
		}
	}

	// The path constant follows the same rename.
	$upper = strtoupper( $prefix );
	if ( ! defined( 'SELLMO_WC_PATH' ) && defined( $upper . '_WC_PATH' ) ) {
		define( 'SELLMO_WC_PATH', constant( $upper . '_WC_PATH' ) );
	}

	define( 'T_HOOK_PREFIX', strtolower( $prefix ) . '_wc_' );
	define( 'T_BRAND_SLUG', strtolower( $prefix ) );

	echo "  (running against the \"{$prefix}\" build — source names aliased)
";
}

t_bridge_brand_names();

/** Hook / nonce / AJAX-action prefix of the installed package. */
if ( ! defined( 'T_HOOK_PREFIX' ) ) {
	define( 'T_HOOK_PREFIX', 'sellmo_wc_' );
}
if ( ! defined( 'T_BRAND_SLUG' ) ) {
	define( 'T_BRAND_SLUG', 'sellmo' );
}

/** Translate a source hook or nonce name to the installed package's equivalent. */
function t_hook( string $source_name ): string {
	return str_replace( 'sellmo_wc_', T_HOOK_PREFIX, $source_name );
}

/**
 * Resolve a source global-function name to the installed package's equivalent.
 *
 * These are declared lazily inside the view files, so this must run at call
 * time rather than during the bridge.
 */
function t_fn( string $source_name ): string {
	if ( function_exists( $source_name ) ) {
		return $source_name;
	}
	$renamed = preg_replace( '/^sellmo_/', T_BRAND_SLUG . '_', $source_name );
	return function_exists( $renamed ) ? $renamed : $source_name;
}

/** REST namespace of the installed package, e.g. "sellmo-wc/v1" or "salesplay-wc/v1". */
function t_rest_namespace(): string {
	return rtrim( str_replace( '_', '-', T_HOOK_PREFIX ), '-' ) . '/v1';
}

// -----------------------------------------------------------------------------
// Assertions
// -----------------------------------------------------------------------------

$GLOBALS['t_pass']    = 0;
$GLOBALS['t_fail']    = 0;
$GLOBALS['t_cleanup'] = [];

function t_head( string $title ): void {
	echo "\n\033[1m{$title}\033[0m\n";
}

function t_ok( string $label, bool $cond, string $detail = '' ): bool {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
		echo "  \033[32mPASS\033[0m {$label}\n";
		return true;
	}

	$GLOBALS['t_fail']++;
	echo "  \033[31mFAIL\033[0m {$label}\n";
	if ( '' !== $detail ) {
		echo "       {$detail}\n";
	}
	return false;
}

function t_eq( string $label, $expected, $actual ): bool {
	return t_ok(
		$label,
		$expected === $actual,
		'expected: ' . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true )
	);
}

/** Float compare with a cent-level tolerance. */
function t_near( string $label, float $expected, float $actual ): bool {
	return t_ok( $label, abs( $expected - $actual ) < 0.005, "expected: {$expected}  actual: {$actual}" );
}

/** Assert at least one string in $haystack contains $needle. */
function t_contains( string $label, string $needle, array $haystack ): bool {
	foreach ( $haystack as $line ) {
		if ( false !== strpos( (string) $line, $needle ) ) {
			return t_ok( $label, true );
		}
	}

	return t_ok( $label, false, "no entry contained: {$needle}\n       saw: " . implode( "\n            ", array_map( 'strval', $haystack ) ) );
}

function t_absent( string $label, string $needle, array $haystack ): bool {
	foreach ( $haystack as $line ) {
		if ( false !== strpos( (string) $line, $needle ) ) {
			return t_ok( $label, false, "unexpectedly found: {$line}" );
		}
	}

	return t_ok( $label, true );
}

function t_summary(): int {
	$pass = (int) $GLOBALS['t_pass'];
	$fail = (int) $GLOBALS['t_fail'];

	echo "\n" . str_repeat( '-', 60 ) . "\n";
	if ( 0 === $fail ) {
		echo "\033[32mALL {$pass} CHECKS PASSED\033[0m\n";
		return 0;
	}
	echo "\033[31m{$fail} FAILED\033[0m, {$pass} passed\n";
	return 1;
}

// -----------------------------------------------------------------------------
// Cleanup registry — runs on normal exit AND on fatal.
// -----------------------------------------------------------------------------

function t_defer( callable $fn ): void {
	$GLOBALS['t_cleanup'][] = $fn;
}

register_shutdown_function( static function (): void {
	foreach ( array_reverse( $GLOBALS['t_cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo "  cleanup error: {$e->getMessage()}\n";
		}
	}
} );

// -----------------------------------------------------------------------------
// Reflection access to private/protected methods (documented as unit-test seams)
// -----------------------------------------------------------------------------

function t_call( object $object, string $method, array $args = [] ) {
	$m = new ReflectionMethod( $object, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $object, $args );
}

function t_call_static( string $class, string $method, array $args = [] ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

// -----------------------------------------------------------------------------
// AJAX endpoint invocation
// -----------------------------------------------------------------------------

/** Thrown in place of wp_die() so a handler's wp_send_json* can be captured. */
final class T_Died extends \Exception {}

/**
 * Call a real wp_ajax_* handler and return its decoded JSON response.
 *
 * Handlers finish with wp_send_json*(), which echoes and then calls wp_die().
 * The ajax die handler is swapped for one that throws, so the echoed body can
 * be captured and the test can carry on.
 *
 * @param  object $handler      Instance owning the handler method.
 * @param  string $method       Handler method name, e.g. 'ajax_get_mapper_list'.
 * @param  array  $post         POST/REQUEST fields; the nonce is added for you.
 * @param  string $nonce_action Nonce action the handler verifies.
 * @return array                Decoded response, or ['_raw' => output] if not JSON.
 */
function t_ajax( object $handler, string $method, array $post = [], string $nonce_action = '' ): array {
	// Empty means "this package's admin nonce", whatever brand prefix it carries.
	$nonce_action = '' === $nonce_action ? t_hook( 'sellmo_wc_admin' ) : t_hook( $nonce_action );
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true );
	}

	// Nonce must be generated as the acting user.
	wp_set_current_user( t_admin_user_id() );
	$post['nonce'] = wp_create_nonce( $nonce_action );

	$_POST    = $post;
	$_REQUEST = $post;
	$_GET     = [];

	$die = static fn(): callable => static function ( $message = '' ): void {
		throw new T_Died( is_string( $message ) ? $message : '' );
	};

	add_filter( 'wp_die_ajax_handler', $die, 99 );

	ob_start();
	try {
		$handler->$method();
	} catch ( T_Died $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Expected — the handler ended via wp_send_json*().
	} finally {
		$output = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die, 99 );
		$_POST    = [];
		$_REQUEST = [];
	}

	$decoded = json_decode( $output, true );

	return is_array( $decoded ) ? $decoded : [ '_raw' => $output ];
}

/** An administrator user id, for capability-gated handlers. */
function t_admin_user_id(): int {
	static $id = null;

	if ( null !== $id ) {
		return (int) $id;
	}

	$users = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	$id    = ! empty( $users ) ? (int) $users[0] : 0;

	if ( 0 === $id ) {
		fwrite( STDERR, "No administrator user found — AJAX tests cannot run.\n" );
		exit( 1 );
	}

	return (int) $id;
}

// -----------------------------------------------------------------------------
// Fake POS API — bound to WordPress' own pre_http_request filter.
// -----------------------------------------------------------------------------

final class T_Fake_API {

	/** @var array<string, callable|array> endpoint fragment => response body */
	private static array $routes = [];

	/** @var array<int, array{url: string, method: string, body: mixed}> */
	public static array $requests = [];

	private static bool $installed = false;

	/** Wrap a list of product payloads as one /products page. */
	public static function products_page( array $items ): array {
		return [ 'products' => $items ];
	}

	/** Wrap a list of inventory records as one /inventory page. */
	public static function inventory_page( array $levels ): array {
		return [ 'inventory_levels' => $levels ];
	}

	/**
	 * Route POS API calls to canned payloads.
	 *
	 * @param array $routes {
	 *     @type array $products   List of /products pages (cursor-chained).
	 *     @type array $inventory  List of /inventory pages (cursor-chained).
	 *     @type array $/shops         Body for GET /shops.
	 *     @type array $/categories    Body for GET /categories.
	 *     @type array|callable $/online_orders Body or handler for POST /online_orders.
	 * }
	 */
	/** @var array<string, array<int, array>> Cursor-chained pages by endpoint kind. */
	public static array $pages = [];

	public static function install( array $routes ): void {
		self::$requests = [];
		self::$pages    = [];

		self::flush_cache();

		// Cached-endpoint seam. Sellmo_API::get_products()/get_inventory() consult a
		// per-cursor transient first, so seeding it covers every caller that leaves
		// force_fresh off.
		Sellmo_API::set_force_fresh( false );

		foreach ( [ 'products', 'inventory' ] as $kind ) {
			if ( isset( $routes[ $kind ] ) ) {
				self::$pages[ $kind ] = array_values( $routes[ $kind ] );
				self::seed_pages( $kind, $routes[ $kind ] );
			}
		}

		// wp_remote_* seam.
		self::$routes = array_filter(
			$routes,
			static fn( string $k ): bool => str_starts_with( $k, '/' ),
			ARRAY_FILTER_USE_KEY
		);

		if ( self::$installed ) {
			return;
		}

		add_filter( 'pre_http_request', [ self::class, 'intercept' ], 10, 3 );
		T_Transport::install();

		self::$installed = true;
		t_defer( [ self::class, 'uninstall' ] );
	}

	/**
	 * Resolve a request URL to a canned response body.
	 *
	 * Shared by both seams. /products and /inventory are cursor-paginated, so the
	 * cursor in the request body selects the page.
	 *
	 * @param  string $url  Request URL.
	 * @param  mixed  $body Decoded request body, when there was one.
	 * @return array|null   Response body, or null when the endpoint is not stubbed.
	 */
	public static function resolve( string $url, $body = null ): ?array {
		foreach ( [ 'products', 'inventory' ] as $kind ) {
			if ( false === strpos( $url, '/' . $kind ) || empty( self::$pages[ $kind ] ) ) {
				continue;
			}

			$pages  = self::$pages[ $kind ];
			$cursor = is_array( $body ) ? (string) ( $body['cursor'] ?? '' ) : '';
			$index  = 0;

			if ( '' !== $cursor && preg_match( '/^T_CURSOR_' . preg_quote( $kind, '/' ) . '_(\d+)$/', $cursor, $m ) ) {
				$index = (int) $m[1] + 1;
			}

			if ( ! isset( $pages[ $index ] ) ) {
				return [ 'cursor' => '' ];
			}

			$page           = $pages[ $index ];
			$page['cursor'] = isset( $pages[ $index + 1 ] ) ? "T_CURSOR_{$kind}_{$index}" : '';

			return $page;
		}

		foreach ( self::$routes as $fragment => $response ) {
			if ( false !== strpos( $url, (string) $fragment ) ) {
				return is_callable( $response ) ? $response( $body, $url ) : $response;
			}
		}

		return null;
	}

	/**
	 * Write one transient per page, chaining each page's cursor to the next.
	 *
	 * Mirrors POS_Brand::transient( "{kind}_" . md5( $cursor ) ) — the exact key
	 * get_products() / get_inventory() consult before making a request.
	 */
	private static function seed_pages( string $kind, array $pages ): void {
		$pages  = array_values( $pages );
		$cursor = '';

		foreach ( $pages as $i => $page ) {
			$next           = isset( $pages[ $i + 1 ] ) ? "T_CURSOR_{$kind}_{$i}" : '';
			$page['cursor'] = $next;

			set_transient( POS_Brand::transient( $kind . '_' . md5( $cursor ) ), $page, 5 * MINUTE_IN_SECONDS );
			$cursor = $next;
		}
	}

	public static function uninstall(): void {
		if ( ! self::$installed ) {
			return;
		}
		remove_filter( 'pre_http_request', [ self::class, 'intercept' ], 10 );
		T_Transport::uninstall();
		self::$installed = false;
		self::$routes    = [];
		self::$pages     = [];
		Sellmo_API::set_force_fresh( false );
		self::flush_cache();
	}

	/** Record a request for later assertion. */
	public static function record( string $url, string $method, $body ): void {
		self::$requests[] = [ 'url' => $url, 'method' => $method, 'body' => $body ];
	}

	/**
	 * Drop the plugin's cached API transients — value AND timeout rows.
	 *
	 * Deleting only `_transient_X` and leaving `_transient_timeout_X` behind is a
	 * trap: set_transient() sees the missing value, takes its add_option() branch,
	 * and add_option() silently no-ops on the surviving timeout row. The stale
	 * (usually expired) timeout then makes get_transient() discard the value we
	 * just seeded and fall through to a real HTTP call.
	 */
	public static function flush_cache(): void {
		global $wpdb;

		$prefix = POS_Brand::transient( '' );

		foreach ( [ '_transient_', '_transient_timeout_' ] as $option_prefix ) {
			$like = $wpdb->esc_like( $option_prefix . $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			foreach ( $names as $name ) {
				delete_option( (string) $name );
			}
		}

		wp_cache_flush();
	}

	/**
	 * @param  false|array|WP_Error $pre  Short-circuit value.
	 * @param  array                $args Request args.
	 * @param  string               $url  Request URL.
	 * @return array|false
	 */
	public static function intercept( $pre, $args, $url ) {
		$sent = isset( $args['body'] ) && is_string( $args['body'] )
			? json_decode( $args['body'], true )
			: ( $args['body'] ?? null );

		$body = self::resolve( (string) $url, $sent );

		if ( null === $body ) {
			// An endpoint the test did not stub is a bug in the test, not a network call.
			return new WP_Error( 't_unstubbed_endpoint', "Test made an unstubbed HTTP call to {$url}" );
		}

		self::record( (string) $url, (string) ( $args['method'] ?? 'GET' ), $sent );

		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/** Requests captured for an endpoint fragment. */
	public static function requests_for( string $fragment ): array {
		return array_values( array_filter(
			self::$requests,
			static fn( array $r ): bool => false !== strpos( $r['url'], $fragment )
		) );
	}
}

/**
 * Fake WpOrg\Requests transport.
 *
 * Sellmo_API::request_get_with_body() calls Requests::request() directly, so
 * `pre_http_request` never sees /products or /inventory. Worse, the stock sync
 * calls Sellmo_API::set_force_fresh( true ), which bypasses the transient seam
 * too — leaving no way to stub it from outside without swapping the transport.
 *
 * Requests picks the first entry of its private static $transports whose test()
 * returns true, and memoises the winner in $transport. Both are replaced here
 * (and restored on uninstall) so every outbound call is served locally.
 */
final class T_Transport implements \WpOrg\Requests\Transport {

	private static ?array $saved_transports = null;
	private static ?array $saved_transport  = null;

	public static function install(): void {
		$reflection = new ReflectionClass( \WpOrg\Requests\Requests::class );

		$transports = $reflection->getProperty( 'transports' );
		$transports->setAccessible( true );
		$chosen = $reflection->getProperty( 'transport' );
		$chosen->setAccessible( true );

		if ( null === self::$saved_transports ) {
			self::$saved_transports = (array) $transports->getValue();
			self::$saved_transport  = (array) $chosen->getValue();
		}

		$transports->setValue( null, [ self::class => self::class ] );
		$chosen->setValue( null, [] ); // Drop the memoised winner.
	}

	public static function uninstall(): void {
		if ( null === self::$saved_transports ) {
			return;
		}

		$reflection = new ReflectionClass( \WpOrg\Requests\Requests::class );

		$transports = $reflection->getProperty( 'transports' );
		$transports->setAccessible( true );
		$transports->setValue( null, self::$saved_transports );

		$chosen = $reflection->getProperty( 'transport' );
		$chosen->setAccessible( true );
		$chosen->setValue( null, (array) self::$saved_transport );

		self::$saved_transports = null;
		self::$saved_transport  = null;
	}

	/**
	 * @param  string $url
	 * @param  array  $headers
	 * @param  string|array $data
	 * @param  array  $options
	 * @return string Raw HTTP response.
	 */
	public function request( $url, $headers = [], $data = [], $options = [] ) {
		$sent = is_string( $data ) ? json_decode( $data, true ) : $data;
		$body = T_Fake_API::resolve( (string) $url, $sent );

		if ( null === $body ) {
			throw new \WpOrg\Requests\Exception( "Test made an unstubbed HTTP call to {$url}", 'transport.unstubbed' );
		}

		T_Fake_API::record( (string) $url, (string) ( $options['type'] ?? 'GET' ), $sent );

		$json = (string) wp_json_encode( $body );

		return "HTTP/1.1 200 OK\r\n"
			. "Content-Type: application/json\r\n"
			. 'Content-Length: ' . strlen( $json ) . "\r\n"
			. "\r\n"
			. $json;
	}

	public function request_multiple( $requests, $options ) {
		$responses = [];
		foreach ( $requests as $id => $request ) {
			$responses[ $id ] = $this->request(
				$request['url'],
				$request['headers'] ?? [],
				$request['data'] ?? [],
				$request['options'] ?? []
			);
		}
		return $responses;
	}

	public static function test( $capabilities = [] ) {
		return true;
	}
}

// -----------------------------------------------------------------------------
// Sync-log watermark
// -----------------------------------------------------------------------------

function t_log_mark(): int {
	global $wpdb;
	$table = POS_Brand::table( 'sync_log' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM `{$table}`" );
}

/**
 * Log messages written since a watermark.
 *
 * @param  int    $mark   Watermark from t_log_mark().
 * @param  string $status Optional status filter ('success', 'error', 'skipped').
 * @return array<int, string>
 */
function t_log_since( int $mark, string $status = '' ): array {
	global $wpdb;
	$table = POS_Brand::table( 'sync_log' );

	$sql    = "SELECT message FROM `{$table}` WHERE id > %d";
	$params = [ $mark ];

	if ( '' !== $status ) {
		$sql     .= ' AND status = %s';
		$params[] = $status;
	}

	$sql .= ' ORDER BY id ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) );
}

// -----------------------------------------------------------------------------
// Fixture builders — all namespaced with ZZTEST and auto-removed.
// -----------------------------------------------------------------------------

function t_uid( string $suffix = '' ): string {
	static $n = 0;
	$n++;
	return T_PREFIX . '_' . $n . ( '' !== $suffix ? '_' . $suffix : '' );
}

/**
 * Build a POS variant payload fragment.
 */
function t_variant( string $id, string $code, string $o1, string $o2, float $price, string $shop_id ): array {
	return [
		'variant_id'    => $id,
		'product_code'  => $code,
		'product_name'  => "Variant {$o1} / {$o2}",
		'option1_value' => $o1,
		'option2_value' => $o2,
		'option3_value' => '',
		'shops'         => [
			[ 'shop_id' => $shop_id, 'price' => $price, 'available_for_sale' => true ],
		],
	];
}

/**
 * Build a POS product payload.
 */
function t_product( string $pos_id, string $code, string $name, string $category, float $price, string $shop_id, array $variants = [] ): array {
	$product = [
		'id'                  => $pos_id,
		'product_code'        => $code,
		'product_name'        => $name,
		'category'            => $category,
		'selling_price'       => $price,
		'product_description' => 'Test fixture.',
		'variants'            => $variants,
	];

	// Simple products carry shops at the product level; variable products carry
	// them on each variant (mirrors the real API shape).
	if ( empty( $variants ) ) {
		$product['shops'] = [ [ 'shop_id' => $shop_id, 'price' => $price, 'available_for_sale' => true ] ];
	}

	return $product;
}

/**
 * Stage a POS product into the mapper table and schedule its removal.
 *
 * @return int Mapping row id.
 */
function t_stage_product( array $payload ): int {
	$shop_ids = [];
	foreach ( ( $payload['shops'] ?? [] ) as $s ) {
		$shop_ids[] = (string) $s['shop_id'];
	}
	foreach ( ( $payload['variants'] ?? [] ) as $v ) {
		foreach ( ( $v['shops'] ?? [] ) as $s ) {
			$shop_ids[] = (string) $s['shop_id'];
		}
	}
	$shop_ids = array_values( array_unique( $shop_ids ) );

	Sellmo_Product_Mapping::insert_new( [
		'pos_product_id'   => (string) $payload['id'],
		'pos_product_code' => (string) $payload['product_code'],
		'pos_product_name' => (string) $payload['product_name'],
		'category'         => (string) $payload['category'],
		'shop_id'          => implode( ',', $shop_ids ),
		'shop_name'        => implode( ',', array_map( static fn( $i ) => 'Shop ' . substr( $i, -2 ), $shop_ids ) ),
		'price'            => (float) ( $payload['selling_price'] ?? 0 ),
		'variant_count'    => count( $payload['variants'] ?? [] ),
		'raw_payload'      => $payload,
	] );

	$id = Sellmo_Product_Mapping::get_id_by_sellmo( (string) $payload['id'], (string) $payload['product_code'] );

	t_defer( static function () use ( $id ): void {
		t_purge_mapping( $id );
	} );

	return $id;
}

/** Stage each variant of a product into the variant store. */
function t_stage_variants( int $mapping_id, array $payload ): void {
	foreach ( ( $payload['variants'] ?? [] ) as $v ) {
		$shop_ids = array_map( static fn( $s ) => (string) $s['shop_id'], $v['shops'] ?? [] );

		Sellmo_Product_Variant_Store::upsert_variant( [
			'product_id'    => $mapping_id,
			'variant_id'    => (string) $v['variant_id'],
			'product_code'  => (string) $v['product_code'],
			'default_cost'  => 0,
			'option1_value' => (string) $v['option1_value'],
			'option2_value' => (string) $v['option2_value'],
			'option3_value' => '',
			'shop_ids'      => $shop_ids,
			'shop_names'    => array_map( static fn( $i ) => 'Shop ' . substr( $i, -2 ), $shop_ids ),
			'stock_count'   => 0,
		] );
	}
}

/** Create a WooCommerce simple product and schedule its deletion. */
function t_wc_simple( string $name, float $price, string $pos_code = '', string $pos_id = '', int $stock = 0 ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	if ( $stock > 0 ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
	}
	$id = $product->save();

	if ( '' !== $pos_code ) {
		update_post_meta( $id, '_pos_product_code', $pos_code );
	}
	if ( '' !== $pos_id ) {
		update_post_meta( $id, '_salesplay_pos_product_id', $pos_id );
	}
	update_post_meta( $id, '_sellmo_sync_enabled', 'yes' );

	t_defer( static function () use ( $id ): void {
		t_delete_wc_product( $id );
	} );

	return (int) $id;
}

/** Hard-delete a WC product and its variations. */
function t_delete_wc_product( int $id ): void {
	if ( $id <= 0 ) {
		return;
	}
	$product = wc_get_product( $id );
	if ( $product instanceof WC_Product ) {
		foreach ( $product->get_children() as $child ) {
			wp_delete_post( (int) $child, true );
		}
	}
	wp_delete_post( $id, true );
}

/** Delete a mapping row, its variant rows, and any WC product it created. */
function t_purge_mapping( int $mapping_id ): void {
	if ( $mapping_id <= 0 ) {
		return;
	}

	global $wpdb;

	$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
	if ( is_array( $row ) ) {
		$wc_id = (int) ( $row['wc_product_id'] ?? 0 );
		if ( $wc_id > 0 ) {
			t_delete_wc_product( $wc_id );
		}
	}

	foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) as $v ) {
		$vid = (int) ( $v['wc_variation_id'] ?? 0 );
		if ( $vid > 0 ) {
			wp_delete_post( $vid, true );
		}
	}

	$wpdb->delete( Sellmo_Product_Variant_Store::table_name(), [ 'product_id' => $mapping_id ], [ '%d' ] );
	$wpdb->delete( Sellmo_Product_Mapping::table_name(), [ 'id' => $mapping_id ], [ '%d' ] );
}

/**
 * Temporarily override a plugin setting, restoring it on cleanup.
 */
function t_set_setting( string $key, $value ): void {
	$old = Sellmo_Settings::get( $key );
	Sellmo_Settings::set( $key, $value );
	t_defer( static function () use ( $key, $old ): void {
		Sellmo_Settings::set( $key, $old );
	} );
}

/** Row counts used to prove a test left real data untouched. */
function t_real_counts(): array {
	global $wpdb;
	$map = Sellmo_Product_Mapping::table_name();
	$var = Sellmo_Product_Variant_Store::table_name();
	$like = T_PREFIX . '%';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$mapping = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$map}` WHERE pos_product_id NOT LIKE %s", $like ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$variants = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$var}` WHERE product_code NOT LIKE %s", $like ) );

	return [ 'mapping' => $mapping, 'variants' => $variants ];
}
