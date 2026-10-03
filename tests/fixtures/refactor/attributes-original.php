<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expose the registered WooCommerce global attributes and their terms to the
 * manager UI. Attribute writes remain part of the product contract so a
 * product and its declared variation attributes are saved atomically.
 *
 * @return void
 */
function register_product_attribute_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/product-attributes',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_product_attributes',
			'permission_callback' => __NAMESPACE__ . '\\product_attributes_read_permission',
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function product_attributes_read_permission( $request ) {
	return products_read_permission( $request );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_product_attributes( $request ) {
	if ( ! function_exists( 'wc_get_attribute_taxonomies' ) || ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API ویژگی‌ها در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	$items = array();
	foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
		$slug     = sanitize_title( (string) ( isset( $attribute->attribute_name ) ? $attribute->attribute_name : '' ) );
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms      = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		$term_items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( (array) $terms as $term ) {
				$term_items[] = array(
					'id'   => absint( $term->term_id ),
					'name' => sanitize_text_field( $term->name ),
					'slug' => sanitize_title( $term->slug ),
				);
			}
		}

		$items[] = array(
			'id'    => absint( isset( $attribute->attribute_id ) ? $attribute->attribute_id : 0 ),
			'label' => sanitize_text_field( isset( $attribute->attribute_label ) ? $attribute->attribute_label : $slug ),
			'slug'  => $slug,
			'name'  => $taxonomy,
			'terms' => $term_items,
		);
	}

	return product_no_store_response( rest_ensure_response( array( 'data' => $items ) ) );
}
