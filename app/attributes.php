<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/product-attributes.php';

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
	/** @var \Fandoogh_Manager\Catalog\ProductAttributeList $attributes */
	$attributes = compose_product_attribute_list();
	if ( ! $attributes->isAvailable() ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API ویژگی‌ها در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	return product_no_store_response( rest_ensure_response( array( 'data' => $attributes->items() ) ) );
}
