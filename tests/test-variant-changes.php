<?php
/**
 * Self-check for Sellmo_Product_Mapping's variant diffing.
 *
 * Run: php tests/test-variant-changes.php
 *
 * Loads the mapping class standalone (it touches no WordPress functions at
 * load time) and exercises the private variant_changes() helper via reflection.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-product-mapping.php';

$diff = static function ( array $old, array $new ): array {
	$method = new ReflectionMethod( 'Sellmo_Product_Mapping', 'variant_changes' );
	$method->setAccessible( true );

	return $method->invoke( null, wp_json_encode_stub( $old ), $new );
};

function wp_json_encode_stub( array $data ): string {
	return json_encode( $data );
}

$v = static fn( string $id, string $code, string $opt, float $price ): array => [
	'variant_id'    => $id,
	'product_code'  => $code,
	'option1_value' => $opt,
	'selling_price' => $price,
];

// No variants on either side -> nothing to report.
assert( [] === $diff( [ 'variants' => [] ], [ 'variants' => [] ] ) );

// Identical variants -> nothing to report.
$same = [ 'variants' => [ $v( '1', 'A', 'Red', 100.0 ) ] ];
assert( [] === $diff( $same, $same ) );

// Price change.
$out = $diff(
	[ 'variants' => [ $v( '1', 'A', 'Red', 100.0 ) ] ],
	[ 'variants' => [ $v( '1', 'A', 'Red', 150.0 ) ] ]
);
assert( [ 'variant "Red" price 100.00 → 150.00' ] === $out, print_r( $out, true ) );

// Rename keeps the same variant_id -> reported as a rename, not add + remove.
$out = $diff(
	[ 'variants' => [ $v( '1', 'A', 'Red', 100.0 ) ] ],
	[ 'variants' => [ $v( '1', 'A', 'Crimson', 100.0 ) ] ]
);
assert( [ 'variant "Red" → "Crimson"' ] === $out, print_r( $out, true ) );

// Added and removed variants.
$out = $diff(
	[ 'variants' => [ $v( '1', 'A', 'Red', 100.0 ) ] ],
	[ 'variants' => [ $v( '2', 'B', 'Blue', 200.0 ) ] ]
);
assert( [ 'variant "Blue" added', 'variant "Red" removed' ] === $out, print_r( $out, true ) );

// Shop price wins over selling_price.
$with_shop                    = $v( '1', 'A', 'Red', 100.0 );
$with_shop['shops']           = [ [ 'price' => 250.0 ] ];
$out = $diff(
	[ 'variants' => [ $v( '1', 'A', 'Red', 100.0 ) ] ],
	[ 'variants' => [ $with_shop ] ]
);
assert( [ 'variant "Red" price 100.00 → 250.00' ] === $out, print_r( $out, true ) );

// Overflow is capped with a summary line.
$old_many = [];
$new_many = [];
for ( $i = 1; $i <= 15; $i++ ) {
	$old_many[] = $v( (string) $i, 'C' . $i, 'Opt' . $i, 10.0 );
	$new_many[] = $v( (string) $i, 'C' . $i, 'Opt' . $i, 20.0 );
}
$out = $diff( [ 'variants' => $old_many ], [ 'variants' => $new_many ] );
assert( 11 === count( $out ), print_r( $out, true ) );
assert( 'and 5 more variant change(s)' === end( $out ), print_r( $out, true ) );

echo "ok\n";
