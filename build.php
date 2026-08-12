<?php
/**
 * White-label build script.
 *
 *   php build.php <brand>        e.g.  php build.php salespay
 *
 * Reads .env.<brand>, stages a copy of the plugin into build/<slug>-woocommerce/,
 * fills the {{PLACEHOLDERS}} in the header + readme + PHP (including the BRAND_*
 * values baked into POS_Brand::DEFAULTS), renames the main file, and zips it.
 * No runtime .env is shipped. The working tree is never modified.
 *
 * Pure PHP, no external deps (ZipArchive ships with PHP).
 */

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Run from the command line.\n");
    exit(1);
}

const ROOT      = __DIR__;
const BUILD_DIR = __DIR__ . '/build';

// Files/dirs never copied into a package (repo/dev/build artifacts).
const EXCLUDE = [
    '.git', '.github', '.gitignore', 'build', 'build.php', 'node_modules',
    'vendor', '.env', '.env1', '.env copy.example',
    // .env.* brand profiles are matched by prefix below.
];

// Header/readme placeholder defaults, overridden by the brand file.
const DEFAULTS = [
    'PLUGIN_NAME'       => 'POS Integration',
    'PLUGIN_URI'        => '',
    'PLUGIN_DESCRIPTION'=> 'Integrates WooCommerce with your POS.',
    'VERSION'           => '1.0.0',
    'WP_VERSION'        => '6.0',
    'WP_TESTED_VERSION' => '6.7',
    'PHP_VERSION'       => '8.0',
    'WC_MIN_VERSION'    => '7.0',
    'WC_TESTED_VERSION' => '9.0',
    'AUTHOR'            => '',
    'AUTHOR_URI'        => '',
    'CONTRIBUTORS'      => '',
    'TERMS_URL'         => '',
    'PRIVACY_URL'       => '',
    'TEXT_DOMAIN'       => 'sellmo-woocommerce',
    'PACKAGE_NAME'      => 'Sellmo_WooCommerce',
];

// Extensions scanned for {{PLACEHOLDER}} replacement. Everything else copies raw.
const TEMPLATE_EXT = ['php', 'txt', 'md'];

/* ------------------------------------------------------------------ helpers */

function step(string $msg): void { echo "  \033[32m✓\033[0m $msg\n"; }
function info(string $msg): void { echo "  → $msg\n"; }

function fail(string $msg): never {
    fwrite(STDERR, "\n  \033[31m✗ $msg\033[0m\n\n");
    exit(1);
}

/** Minimal KEY=VALUE / #-comment / quote-stripping .env parser. */
function parse_env(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        fail("Cannot read $path");
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ('' === $line || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}

/** rm -rf, PHP edition. */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Copy ROOT into $dest, skipping EXCLUDE and any .env.* brand profile. */
function copy_tree(string $src, string $dest): void {
    if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
        fail("Cannot create $dest");
    }
    foreach (scandir($src) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        if (in_array($entry, EXCLUDE, true) || str_starts_with($entry, '.env.')) {
            continue;
        }
        $from = "$src/$entry";
        $to   = "$dest/$entry";
        if (is_dir($from)) {
            copy_tree($from, $to);
        } elseif (!copy($from, $to)) {
            fail("Copy failed: $from");
        }
    }
}

/** Replace {{KEY}} tokens in every TEMPLATE_EXT file under $dir. */
function apply_placeholders(string $dir, array $map): void {
    $tokens = [];
    foreach ($map as $k => $v) {
        $tokens['{{' . $k . '}}'] = $v;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!in_array(strtolower($f->getExtension()), TEMPLATE_EXT, true)) {
            continue;
        }
        $body = file_get_contents($f->getPathname());
        if (false === $body || !str_contains($body, '{{')) {
            continue;
        }
        file_put_contents($f->getPathname(), strtr($body, $tokens));
    }
}

/** [localPath => absolutePath] for every file under $dir, keyed under its basename. */
function zip_entries(string $dir): array {
    $base  = dirname($dir);
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isDir()) {
            continue;
        }
        $local = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
        $files[$local] = $f->getPathname();
    }
    return $files;
}

/**
 * Zip the staged folder (folder is the top-level entry, as WordPress expects).
 * Prefers ext-zip; falls back to PharData when it is disabled. Both are stdlib.
 */
function zip_dir(string $dir, string $zipPath): void {
    @unlink($zipPath);
    $entries = zip_entries($dir);

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE)) {
            fail("Cannot create $zipPath");
        }
        foreach ($entries as $local => $abs) {
            $zip->addFile($abs, $local);
        }
        $zip->close();
        return;
    }

    if (class_exists('PharData')) {
        $phar = new PharData($zipPath, 0, null, Phar::ZIP);
        foreach ($entries as $local => $abs) {
            $phar->addFile($abs, $local);
        }
        return;
    }

    fail('No zip support — enable the PHP zip (or phar) extension.');
}

/* --------------------------------------------------------------------- main */

$brand = $argv[1] ?? '';
if ('' === $brand) {
    // List available brands so a bare invocation is self-documenting.
    $found = array_map(
        static fn($p) => substr(basename($p), strlen('.env.')),
        glob(ROOT . '/.env.*') ?: []
    );
    fail("Usage: php build.php <brand>\n  Available: " . (implode(', ', $found) ?: '(none)'));
}

$brand    = strtolower($brand);
$envFile  = ROOT . "/.env.$brand";
if (!is_file($envFile)) {
    fail("Unknown brand \"$brand\" — no $envFile.\n  Create it, or copy an existing .env.* profile.");
}

echo "\nBuilding \033[1m$brand\033[0m...\n";

// Gate: the integration suite must be green before anything is packaged.
// Skip with --skip-tests only when you know why (e.g. no local WordPress).
if (!in_array('--skip-tests', $argv, true)) {
    $runner = ROOT . '/tests/run-all.php';

    if (!is_file($runner)) {
        fail("Test runner missing: $runner");
    }

    echo "  → Running test suite...\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' --quiet', $testCode);

    if (0 !== $testCode) {
        fail("Tests failed — build aborted.\n  Fix the failures, or re-run with --skip-tests to override.");
    }
    step('Test suite passed');
}

$env = parse_env($envFile);

// Identity slug drives file/folder/zip names.
$slug = strtolower(preg_replace('/[^a-z0-9_]/i', '', $env['BRAND_SLUG'] ?? $brand) ?? '');
if ('' === $slug) {
    fail("BRAND_SLUG missing/invalid in $envFile");
}
$pkg = "$slug-woocommerce";

// Placeholder map: DEFAULTS as the base, then every non-empty key from the
// brand file (BUILD block AND BRAND_* block), so {{BRAND_NAME}}, {{BRAND_SLUG}},
// etc. are usable in templates alongside the header placeholders.
$map = array_merge(DEFAULTS, array_filter($env, static fn($v) => '' !== $v));
step("Loaded $envFile ({$map['PLUGIN_NAME']})");

// Stage a clean copy.
$stage = BUILD_DIR . "/$pkg";
rrmdir($stage);
copy_tree(ROOT, $stage);
step("Copied source → build/$pkg/");

// Fill placeholders in header + readme files.
apply_placeholders($stage, $map);
step('Replaced placeholders in .php / .txt / .md');

// No runtime .env is shipped: the BRAND_* values are baked into the PHP by the
// placeholder pass above (POS_Brand::DEFAULTS). A .env only exists as an
// optional override for rebranding a live install (see POS_Brand).

// Rename the main plugin file to match the slug.
$mainSrc = "$stage/sellmo-woocommerce.php";
if (is_file($mainSrc) && "$slug-woocommerce" !== 'sellmo-woocommerce') {
    if (!rename($mainSrc, "$stage/$pkg.php")) {
        fail('Could not rename main plugin file');
    }
    step("Renamed main file → $pkg.php");
}

// Zip it.
$zipPath = BUILD_DIR . "/$pkg.zip";
zip_dir($stage, $zipPath);
$size = round((filesize($zipPath) ?: 0) / 1024, 1);
step("Packaged build/$pkg.zip ({$size} KB)");

echo "\n\033[1mDone.\033[0m Install build/$pkg.zip via Plugins → Add New → Upload.\n\n";
