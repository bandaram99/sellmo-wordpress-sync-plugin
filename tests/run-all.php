<?php
/**
 * Run every test suite and report a combined result.
 *
 *   php tests/run-all.php
 *
 * Each suite runs in its own PHP process so a fatal in one cannot mask the
 * others, and so WordPress / WooCommerce state never leaks between them.
 *
 * build.php runs this before packaging — a red suite blocks the build.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "Run from the command line.\n" );
	exit( 1 );
}

$quiet = in_array( '--quiet', $argv, true );

$files = glob( __DIR__ . '/test-*.php' );
sort( $files );

if ( empty( $files ) ) {
	fwrite( STDERR, "No test files found.\n" );
	exit( 1 );
}

$passed = [];
$failed = [];

foreach ( $files as $file ) {
	$name = basename( $file );

	if ( ! $quiet ) {
		echo "\n" . str_repeat( '=', 64 ) . "\n  {$name}\n" . str_repeat( '=', 64 ) . "\n";
	}

	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file );

	if ( $quiet ) {
		exec( $cmd . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			echo implode( "\n", $output ) . "\n";
		}
		$output = [];
	} else {
		passthru( $cmd, $code );
	}

	if ( 0 === $code ) {
		$passed[] = $name;
	} else {
		$failed[] = $name;
	}
}

echo "\n" . str_repeat( '=', 64 ) . "\n  SUMMARY\n" . str_repeat( '=', 64 ) . "\n";

foreach ( $passed as $name ) {
	echo "  \033[32mPASS\033[0m  {$name}\n";
}
foreach ( $failed as $name ) {
	echo "  \033[31mFAIL\033[0m  {$name}\n";
}

$total = count( $passed ) + count( $failed );
echo "\n" . count( $passed ) . " of {$total} suites passed.\n";

exit( empty( $failed ) ? 0 : 1 );
