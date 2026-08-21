<?php
/**
 * Scenario 13 — every PHP file opens with "<?php" and parses.
 *
 * A single stray byte before the opening tag (a space, a UTF-8 BOM) makes
 * declare(strict_types=1) illegal and takes the whole site down with
 * "strict_types declaration must be the very first statement in the script".
 * It is invisible in an editor, so nothing else would catch it — and it can
 * creep into the installed copy under wp-content/plugins without ever
 * touching the source tree, so both trees are checked.
 *
 * Run: php tests/test-13-php-file-headers.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

$trees = [ $root ];

// The installed copy, when this checkout sits next to a WordPress install.
foreach ( glob( dirname( $root ) . '/*/wp-content/plugins/*' ) as $installed ) {
	if ( is_dir( $installed ) && is_file( $installed . '/includes/class-plugin.php' ) ) {
		$trees[] = $installed;
	}
}

$checked = 0;
$failed  = 0;

foreach ( $trees as $tree ) {
	echo "\n-- {$tree}\n";

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tree, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) || false !== strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR ) ) {
			continue;
		}

		$path = $file->getPathname();
		$head = (string) file_get_contents( $path, false, null, 0, 5 );
		$checked++;

		if ( '<?php' !== $head ) {
			echo 'FAIL ' . $path . ': starts with ' . json_encode( $head ) . ", not \"<?php\"\n";
			$failed++;
			continue;
		}

		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			echo 'FAIL ' . $path . ': ' . implode( ' ', $output ) . "\n";
			$failed++;
		}
		$output = [];
	}
}

echo "\n{$checked} PHP files checked.\n";

if ( $failed > 0 ) {
	echo "\n\033[31m{$failed} file(s) failed.\033[0m\n";
	exit( 1 );
}

echo "\n\033[32mAll PHP files open with <?php and parse.\033[0m\n";
exit( 0 );
