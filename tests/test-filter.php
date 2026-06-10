<?php
/**
 * Standalone smoke test for fix-customer-meta-query-cast.php.
 *
 * No WordPress install needed — WP/WC dependencies are stubbed below.
 * Run:        php tests/test-filter.php
 * Exit code:  0 all pass, 1 any failure.
 *
 * Re-run after every major WooCommerce upgrade alongside the Phase 2 SQL
 * checks in PROJECT.md — this verifies the shape-guard logic, not the live
 * query WooCommerce actually emits.
 */

// --- Minimal WP/WC stubs ------------------------------------------------

define( 'ABSPATH', '/tmp/' );
define( 'DAY_IN_SECONDS', 86400 );

class WooCommerce {}

$GLOBALS['hcqo_test_transients'] = array();

function add_filter( $hook, $callback, $priority = 10 ) {}

function get_transient( $key ) {
	return isset( $GLOBALS['hcqo_test_transients'][ $key ] ) ? $GLOBALS['hcqo_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl ) {
	$GLOBALS['hcqo_test_transients'][ $key ] = $value;
}

require dirname( __DIR__ ) . '/fix-customer-meta-query-cast.php';

// --- Harness ------------------------------------------------------------

$pass = 0;
$fail = 0;

function check( $name, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS  $name\n";
	} else {
		$fail++;
		echo "FAIL  $name\n";
	}
}

function customer_clause( $overrides = array() ) {
	return array_merge(
		array(
			'key'   => '_customer_user',
			'value' => 123,
			'type'  => 'NUMERIC',
		),
		$overrides
	);
}

function run_filter( $meta_query ) {
	return hcqo_optimize_customer_order_meta_query( array( 'meta_query' => $meta_query ) );
}

// --- Cases that MUST rewrite to CHAR -------------------------------------

$out = run_filter( array( customer_clause() ) );
check( 'canonical WC REST clause rewritten to CHAR', 'CHAR' === $out['meta_query'][0]['type'] );

$out = run_filter( array( customer_clause( array( 'value' => '456' ) ) ) );
check( 'digit-string value rewritten', 'CHAR' === $out['meta_query'][0]['type'] );

$out = run_filter( array( customer_clause( array( 'value' => 0 ) ) ) );
check( 'customer=0 (int) rewritten', 'CHAR' === $out['meta_query'][0]['type'] );

$out = run_filter( array( customer_clause( array( 'type' => 'numeric' ) ) ) );
check( 'lowercase numeric type rewritten', 'CHAR' === $out['meta_query'][0]['type'] );

$out = run_filter( array( customer_clause( array( 'compare' => '=' ) ) ) );
check( "explicit compare '=' rewritten", 'CHAR' === $out['meta_query'][0]['type'] );

// --- Cases that MUST pass through untouched -------------------------------

$untouched = array(
	'range compare (>) left on CAST path'        => customer_clause( array( 'compare' => '>' ) ),
	'BETWEEN compare left on CAST path'          => customer_clause( array( 'compare' => 'BETWEEN', 'value' => array( 1, 9 ) ) ),
	'IN compare left on CAST path'               => customer_clause( array( 'compare' => 'IN', 'value' => array( 1, 2 ) ) ),
	'array value left on CAST path'              => customer_clause( array( 'value' => array( 123 ) ) ),
	'negative value left on CAST path'           => customer_clause( array( 'value' => -1 ) ),
	'non-numeric string value left on CAST path' => customer_clause( array( 'value' => 'abc' ) ),
	'missing value left on CAST path'            => array( 'key' => '_customer_user', 'type' => 'NUMERIC' ),
	'array type left untouched (no fatal)'       => customer_clause( array( 'type' => array( 'NUMERIC' ) ) ),
	'other meta key untouched'                   => array( 'key' => '_billing_email', 'value' => 'a@b.c', 'type' => 'NUMERIC' ),
	'CHAR clause already untouched'              => customer_clause( array( 'type' => 'CHAR' ) ),
);

foreach ( $untouched as $name => $clause ) {
	$in  = array( $clause );
	$out = run_filter( $in );
	check( $name, $out['meta_query'] === $in );
}

// --- Structural pass-throughs ---------------------------------------------

$in  = array( 'relation' => 'AND', customer_clause(), array( 'key' => '_billing_email', 'value' => 'a@b.c' ) );
$out = run_filter( $in );
check( 'relation string survives, sibling clause byte-identical', 'AND' === $out['meta_query']['relation'] && $out['meta_query'][1] === $in[1] );
check( 'sibling _customer_user clause still rewritten', 'CHAR' === $out['meta_query'][0]['type'] );

$in  = array( 'relation' => 'OR', array( customer_clause(), customer_clause( array( 'value' => 9 ) ) ) );
$out = run_filter( $in );
check( 'nested group left entirely untouched (non-recursive by design)', $out['meta_query'] === $in );

check( 'non-array args returned as-is', 'oops' === hcqo_optimize_customer_order_meta_query( 'oops' ) );
check( 'args without meta_query returned as-is', array( 'post_type' => 'shop_order' ) === hcqo_optimize_customer_order_meta_query( array( 'post_type' => 'shop_order' ) ) );
$args_string_mq = array( 'meta_query' => 'bogus' );
check( 'string meta_query returned as-is', $args_string_mq === hcqo_optimize_customer_order_meta_query( $args_string_mq ) );

// --- Summary ---------------------------------------------------------------

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
