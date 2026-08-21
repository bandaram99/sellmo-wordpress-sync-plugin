<?php
/**
 * Scenario 16 — two built packages can coexist on one WordPress site.
 *
 * Both packages are built from one source tree, so without the build-time
 * identifier rename they declare the same 20 classes, 4 constants, 27 AJAX
 * actions, nonce, REST namespace and script handles. Activating the second one
 * then dies on "Cannot declare class ... because the name is already in use",
 * and a reviewer's test site installs plugins side by side.
 *
 * This suite proves isolation two ways:
 *   1. Statically — the two packages' global symbol sets do not intersect.
 *   2. For real — every class file from BOTH packages is loaded into a single
 *      PHP process. A duplicate declaration is a fatal, so surviving the load
 *      is the proof.
 *
 * It also pins the inverse: identifiers that name STORED data (post meta keys,
 * payload keys, the legacy slug, sync-log direction values) must survive the
 * rename untouched, or upgrading an existing install orphans its data.
 *
 * Pure file check — no WordPress needed.
 *
 * Run: php tests/test-16-package-isolation.php
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$failed = 0;

$packages = array_values( array_filter(
	glob( $root . '/build/*', GLOB_ONLYDIR ) ?: [],
	static fn( string $d ): bool => is_dir( $d . '/includes' )
) );

if ( count( $packages ) < 2 ) {
	echo "SKIP only " . count( $packages ) . " package(s) in build/ — build both brands to run this suite.\n";
	exit( 0 );
}

/** Global symbols a WordPress plugin must not share with another plugin. */
function t16_symbols( string $pkg ): array {
	$out = [ 'class' => [], 'function' => [], 'constant' => [], 'hook' => [], 'rest' => [], 'handle' => [] ];

	foreach ( glob( $pkg . '/{,includes/,admin/views/}*.php', GLOB_BRACE ) ?: [] as $file ) {
		$body = (string) file_get_contents( $file );

		preg_match_all( '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $body, $m );
		$out['class'] = array_merge( $out['class'], $m[1] );

		preg_match_all( '/^function\s+([a-z_][a-z0-9_]*)/mi', $body, $m );
		$out['function'] = array_merge( $out['function'], $m[1] );

		preg_match_all( "/define\(\s*'([A-Z_][A-Z0-9_]*)'/", $body, $m );
		$out['constant'] = array_merge( $out['constant'], $m[1] );

		preg_match_all( "/'(wp_ajax_(?:nopriv_)?[a-z0-9_]+)'/", $body, $m );
		$out['hook'] = array_merge( $out['hook'], $m[1] );

		preg_match_all( "/'([a-z0-9-]+-wc\/v[0-9]+)'/", $body, $m );
		$out['rest'] = array_merge( $out['rest'], $m[1] );

		preg_match_all( "/wp_(?:enqueue|register)_(?:script|style)\(\s*'([a-z0-9-]+)'/", $body, $m );
		$out['handle'] = array_merge( $out['handle'], $m[1] );

		preg_match_all( "/wp_localize_script\(\s*'[a-z0-9-]+',\s*'([A-Za-z0-9_]+)'/", $body, $m );
		$out['handle'] = array_merge( $out['handle'], $m[1] );
	}

	// selectWoo / select2 ship with WooCommerce — shared on purpose.
	$out['handle'] = array_values( array_diff( $out['handle'], [ 'select2', 'selectWoo', 'jquery' ] ) );

	return array_map( static fn( array $v ): array => array_values( array_unique( $v ) ), $out );
}

// -- 1. no global symbol appears in more than one package --------------------

$a = t16_symbols( $packages[0] );
$b = t16_symbols( $packages[1] );

$na = basename( $packages[0] );
$nb = basename( $packages[1] );

foreach ( $a as $kind => $symbols ) {
	$shared = array_intersect( $symbols, $b[ $kind ] );

	if ( empty( $symbols ) && empty( $b[ $kind ] ) ) {
		echo "WARN no {$kind} symbols found in either package — check the pattern\n";
		continue;
	}

	if ( empty( $shared ) ) {
		printf( "PASS %-9s disjoint (%d in %s, %d in %s)\n", $kind, count( $symbols ), $na, count( $b[ $kind ] ), $nb );
	} else {
		printf( "FAIL %-9s shared by both packages: %s\n", $kind, implode( ', ', $shared ) );
		$failed++;
	}
}

// -- 2. identifiers that name STORED data must NOT have been renamed ---------

/** Renaming any of these orphans data on an existing install. */
const KEEP = [
	'_sellmo_sync_enabled',   // post meta on every synced product
	'_sellmo_order_id',       // post meta on every synced order
	'_sellmo_last_synced',
	"'sellmo'",               // POS_Brand_Migrator::LEGACY_SLUG
	'sellmo_to_wc',           // legacy sync-log direction value
	'wc_to_sellmo',
	"'sellmo_id'",            // API payload / row keys shared with the JS
	"'sellmo_code'",
	'X-Sellmo-Signature',     // webhook header, set by the POS backend
];

foreach ( $packages as $pkg ) {
	$name = basename( $pkg );
	$all  = '';

	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pkg, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( in_array( strtolower( $f->getExtension() ), [ 'php', 'js' ], true ) ) {
			$all .= (string) file_get_contents( $f->getPathname() );
		}
	}

	foreach ( KEEP as $needle ) {
		if ( false !== strpos( $all, $needle ) ) {
			echo "PASS {$name}: preserved {$needle}\n";
		} else {
			echo "FAIL {$name}: {$needle} was renamed — stored data would be orphaned\n";
			$failed++;
		}
	}
}

// -- 3. load every class from BOTH packages in one process -------------------
//
// The real test. A duplicate class declaration is a fatal, so the child process
// exiting 0 is the proof that the two plugins can be active together.

$loader = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ );
$loaded = 0;
foreach ( $argv as $i => $dir ) {
	if ( 0 === $i ) { continue; }
	foreach ( glob( $dir . '/includes/class-*.php' ) as $file ) {
		require_once $file;
		$loaded++;
	}
}
echo "loaded {$loaded} class files\n";
PHP;

$tmp = sys_get_temp_dir() . '/pos-isolation-' . getmypid() . '.php';
file_put_contents( $tmp, $loader );

$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp )
	. ' ' . escapeshellarg( $packages[0] ) . ' ' . escapeshellarg( $packages[1] );

exec( $cmd . ' 2>&1', $output, $code );
unlink( $tmp );

if ( 0 === $code ) {
	echo "PASS both packages loaded into one PHP process — " . trim( implode( ' ', $output ) ) . "\n";
} else {
	echo "FAIL loading both packages together fatals:\n       " . implode( "\n       ", $output ) . "\n";
	$failed++;
}

echo "\n";

if ( $failed > 0 ) {
	echo "\033[31m{$failed} check(s) failed.\033[0m\n";
	exit( 1 );
}

echo "\033[32m{$na} and {$nb} are fully isolated and can coexist.\033[0m\n";
exit( 0 );
