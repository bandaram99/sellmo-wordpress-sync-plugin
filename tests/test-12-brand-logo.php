<?php
/**
 * Scenario 12 — every brand profile's BRAND_LOGO points at a file that exists.
 *
 * A missing icon file only shows up as a broken <img> in the admin menu, which
 * no other test would catch. Pure file check — no WordPress needed.
 *
 * Run: php tests/test-12-brand-logo.php
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$failed = 0;

$paths = [];
foreach ( glob( $root . '/.env*' ) as $env ) {
	foreach ( file( $env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( 0 === strpos( trim( $line ), 'BRAND_LOGO=' ) ) {
			$paths[ basename( $env ) ] = trim( substr( trim( $line ), 11 ) );
		}
	}
}

// The default baked into POS_Brand (build.php replaces the placeholder).
if ( preg_match( "#'logo'\s*=>\s*'([^']+)'#", (string) file_get_contents( $root . '/includes/class-pos-brand.php' ), $m )
	&& false === strpos( $m[1], '{{' ) ) {
	$paths['class-pos-brand.php'] = $m[1];
}

foreach ( $paths as $source => $logo ) {
	if ( '' === $logo || str_starts_with( $logo, 'dashicons-' ) || preg_match( '#^(https?:)?//#', $logo ) || str_starts_with( $logo, 'data:' ) ) {
		echo "SKIP {$source}: {$logo}\n";
		continue;
	}
	if ( is_file( $root . '/' . ltrim( $logo, '/' ) ) ) {
		echo "PASS {$source}: {$logo}\n";
	} else {
		echo "FAIL {$source}: {$logo} does not exist\n";
		$failed++;
	}
}

exit( $failed > 0 ? 1 : 0 );
