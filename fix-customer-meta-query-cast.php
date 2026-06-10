<?php
/**
 * Plugin Name: Customer Meta Query Optimization
 * Description: Optimizes WooCommerce REST API ?customer=X query by removing CAST.
 * Purpose: Changes the _customer_user meta query type from NUMERIC to CHAR to make the query sargable.
 * Companion Index: Requires composite index `idx_customer_user_post` on wp_postmeta.
 * Obsolescence: Obsolete after HPOS migration — _customer_user lookups leave wp_postmeta; remove this file at that point.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'woocommerce_rest_shop_order_object_query', 'hcqo_optimize_customer_order_meta_query', 99 );
add_filter( 'woocommerce_rest_orders_prepare_object_query', 'hcqo_optimize_customer_order_meta_query', 99 );

/**
 * Optimizes the _customer_user meta query by changing type to CHAR.
 *
 * @param array $args The query arguments.
 * @return array Modified query arguments.
 */
function hcqo_optimize_customer_order_meta_query( $args ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return $args;
	}

	if ( ! is_array( $args ) || ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		return $args;
	}

	$modified = false;

	foreach ( $args['meta_query'] as $index => $clause ) {
		if ( hcqo_is_rewritable_customer_clause( $clause ) ) {
			$args['meta_query'][ $index ]['type'] = 'CHAR';
			$modified = true;
		}
	}

	if ( $modified ) {
		// Confirmation canary: once per request (static), once per 30 days across
		// requests (transient). The transient stores the WooCommerce version, so an
		// upgrade re-fires the event immediately — silence after an upgrade therefore
		// unambiguously means the filter stopped matching, not that the log is suppressed.
		static $logged = false;
		if ( ! $logged ) {
			$logged = true;
			$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
			if ( class_exists( 'Hypercart_Logger' ) && method_exists( 'Hypercart_Logger', 'info' ) && get_transient( 'hcqo_cast_fix_logged' ) !== $wc_version ) {
				set_transient( 'hcqo_cast_fix_logged', $wc_version, 30 * DAY_IN_SECONDS );
				Hypercart_Logger::info( 'customer_meta_query_cast_fixed', array(
					'message'    => 'Rewrote _customer_user meta query type from NUMERIC to CHAR.',
					'wc_version' => $wc_version,
				) );
			}
		}
	}

	return $args;
}

/**
 * True only for the exact clause shape WooCommerce REST emits for ?customer=X:
 * a flat _customer_user clause, type NUMERIC, equality compare ('=' or absent,
 * which WP_Meta_Query defaults to '='), and a scalar non-negative integer value.
 *
 * CHAR comparison is equivalent to NUMERIC only for integer equality. Range
 * compares (>, BETWEEN, ...) are lexicographic under CHAR ('10' < '9'), so any
 * other shape is deliberately left on the slow-but-correct CAST() path.
 *
 * @param mixed $clause A meta_query element (clause array, nested group, or 'relation' string).
 * @return bool Whether the clause is safe to rewrite to CHAR.
 */
function hcqo_is_rewritable_customer_clause( $clause ) {
	if ( ! is_array( $clause ) ) {
		return false;
	}

	if ( ! isset( $clause['key'], $clause['type'], $clause['value'] ) ) {
		return false;
	}

	if ( '_customer_user' !== $clause['key'] ) {
		return false;
	}

	if ( ! is_string( $clause['type'] ) || 'NUMERIC' !== strtoupper( $clause['type'] ) ) {
		return false;
	}

	if ( isset( $clause['compare'] ) && '=' !== $clause['compare'] ) {
		return false;
	}

	$value = $clause['value'];

	return is_int( $value ) ? $value >= 0 : ( is_string( $value ) && '' !== $value && ctype_digit( $value ) );
}
