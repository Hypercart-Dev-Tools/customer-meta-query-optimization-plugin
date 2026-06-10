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
		if ( ! is_array( $clause ) ) {
			continue;
		}

		if ( isset( $clause['key'], $clause['type'] ) && '_customer_user' === $clause['key'] && 'NUMERIC' === strtoupper( $clause['type'] ) ) {
			$args['meta_query'][ $index ]['type'] = 'CHAR';
			$modified = true;
		}
	}

	if ( $modified ) {
		static $logged = false;
		if ( ! $logged ) {
			$logged = true;
			if ( class_exists( 'Hypercart_Logger' ) && method_exists( 'Hypercart_Logger', 'info' ) ) {
				Hypercart_Logger::info( 'customer_meta_query_cast_fixed', array(
					'message' => 'Rewrote _customer_user meta query type from NUMERIC to CHAR.',
				) );
			}
		}
	}

	return $args;
}
