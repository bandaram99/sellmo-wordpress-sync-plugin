<?php
/**
 * Scenario 15 — built packages contain only what WordPress.org should receive.
 *
 * Three rules, all learned from a review pass over build/:
 *
 *   1. No development files. The test suite bootstraps WordPress from a
 *      hard-coded local path and mutates catalogue data, and the .md docs
 *      describe the internal white-label build system. Docs must be dropped by
 *      pattern rather than by name — a hard-coded list silently ships the next
 *      .md anyone adds to the repo root.
 *   2. No misspelt brand. "SalesPay" (missing the l) shipped as the plugin name
 *      while the readme body, brand config and domain all said SalesPlay.
 *
 * Checks build.php's exclusion rules so it fails before a bad package is cut,
 * and every package already sitting in build/ so a stale one cannot be
 * submitted.
 *
 * Pure file check — no WordPress needed.
 *
 * Run: php tests/test-15-package-contents.php
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$failed = 0;

/** Directories that must never reach a built package. */
const DEV_ONLY = [ 'tests' ];

/** Brand misspellings that must not appear in any shipped or templated text. */
const BAD_BRAND = [ 'SalesPay', 'salespay' ];

$build = (string) file_get_contents( $root . '/build.php' );

// -- 1a. build.php excludes every development directory ----------------------

if ( preg_match( '/const EXCLUDE = \[(.*?)\];/s', $build, $m ) ) {
	foreach ( DEV_ONLY as $entry ) {
		if ( false !== strpos( $m[1], "'{$entry}'" ) ) {
			echo "PASS build.php EXCLUDE covers {$entry}\n";
		} else {
			echo "FAIL build.php EXCLUDE is missing {$entry}\n";
			$failed++;
		}
	}
} else {
	echo "FAIL build.php has no EXCLUDE list\n";
	$failed++;
}

// -- 1b. ...and drops docs by pattern, not by name ---------------------------

if ( false !== strpos( $build, "'.md'" ) && false !== strpos( $build, 'str_ends_with' ) ) {
	echo "PASS build.php drops every *.md by pattern\n";
} else {
	echo "FAIL build.php does not exclude *.md by pattern\n";
	$failed++;
}

// -- 2. no brand misspelling in the profiles that feed the header/readme -----

foreach ( glob( $root . '/.env*' ) ?: [] as $env ) {
	$body = (string) file_get_contents( $env );
	$hit  = false;

	foreach ( BAD_BRAND as $bad ) {
		if ( false !== strpos( $body, $bad ) ) {
			echo 'FAIL ' . basename( $env ) . ": contains \"{$bad}\"\n";
			$failed++;
			$hit = true;
		}
	}

	if ( ! $hit ) {
		echo 'PASS ' . basename( $env ) . ": brand name spelt correctly\n";
	}
}

// -- 3. packages already in build/ are clean ---------------------------------

foreach ( glob( $root . '/build/*', GLOB_ONLYDIR ) ?: [] as $pkg ) {
	$name = basename( $pkg );

	foreach ( DEV_ONLY as $entry ) {
		if ( file_exists( $pkg . '/' . $entry ) ) {
			echo "FAIL build/{$name}/ ships {$entry}\n";
			$failed++;
		}
	}

	foreach ( glob( $pkg . '/*.md' ) ?: [] as $stray ) {
		echo "FAIL build/{$name}/ ships " . basename( $stray ) . "\n";
		$failed++;
	}

	// The header name is what the directory listing shows — it must be right.
	foreach ( glob( $pkg . '/*.php' ) ?: [] as $file ) {
		$head = (string) file_get_contents( $file, false, null, 0, 2048 );

		if ( ! str_contains( $head, 'Plugin Name:' ) ) {
			continue;
		}

		foreach ( BAD_BRAND as $bad ) {
			if ( false !== strpos( $head, $bad ) ) {
				echo "FAIL build/{$name}/" . basename( $file ) . ": header contains \"{$bad}\"\n";
				$failed++;
			}
		}
	}

	// WordPress.org requires the text domain to equal the plugin slug, or the
	// directory's language packs are never loaded for this plugin.
	$main = $pkg . '/' . $name . '.php';

	if ( is_file( $main ) ) {
		$head = (string) file_get_contents( $main, false, null, 0, 4096 );

		if ( preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)/mi', $head, $m ) ) {
			if ( $m[1] === $name ) {
				echo "PASS build/{$name}/: text domain matches the slug\n";
			} else {
				echo "FAIL build/{$name}/: text domain is {$m[1]}, slug is {$name}\n";
				$failed++;
			}
		} else {
			echo "FAIL build/{$name}/: no Text Domain header\n";
			$failed++;
		}
	} else {
		echo "FAIL build/{$name}/: no {$name}.php main file\n";
		$failed++;
	}

	echo "  checked build/{$name}/\n";
}

echo "\n";

if ( $failed > 0 ) {
	echo "\033[31m{$failed} check(s) failed.\033[0m\n";
	exit( 1 );
}

echo "\033[32mBuilt packages carry no development files and no misspelt brand.\033[0m\n";
exit( 0 );
