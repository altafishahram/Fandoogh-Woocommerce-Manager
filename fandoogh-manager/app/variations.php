<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the limited variation contract under a parent product. The module
 * uses WC_Product_Variation CRUD only; arbitrary post fields and variation
 * metadata are intentionally outside the API.
 *
 * @return void
 */
function register_variation_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/products/(?P<product_id>\\d+)/variations',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\list_product_variations',
				'permission_callback' => __NAMESPACE__ . '\\product_variations_read_permission',
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\create_product_variation',
				'permission_callback' => __NAMESPACE__ . '\\product_variations_write_permission',
			),
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/products/(?P<product_id>\\d+)/variations/(?P<id>\\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\get_product_variation',
				'permission_callback' => __NAMESPACE__ . '\\product_variations_read_permission',
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => __NAMESPACE__ . '\\update_product_variation',
				'permission_callback' => __NAMESPACE__ . '\\product_variations_write_permission',
			),
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return object|\WP_Error
 */
function product_variation_parent( $request ) {
	$parent_id = absint( $request->get_param( 'product_id' ) );
	$parent    = $parent_id && function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : false;

	if ( ! $parent || ! method_exists( $parent, 'get_id' ) || ( method_exists( $parent, 'is_type' ) && $parent->is_type( 'variation' ) ) || ( method_exists( $parent, 'get_status' ) && 'trash' === $parent->get_status() ) ) {
		return new \WP_Error( 'fandoogh_variation_parent_not_found', __( 'محصول والد variation پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	if ( ! method_exists( $parent, 'is_type' ) || ! $parent->is_type( 'variable' ) ) {
		return new \WP_Error( 'fandoogh_variation_parent_invalid', __( 'فقط محصول variable می‌تواند variation داشته باشد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $parent;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @param bool              $write Whether write scope/capability is required.
 * @return true|\WP_Error
 */
function product_variation_permission( $request, $write = false ) {
	$base_permission = $write ? products_write_permission( $request ) : products_read_permission( $request );
	if ( is_wp_error( $base_permission ) ) {
		return $base_permission;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$parent = product_variation_parent( $request );
	if ( is_wp_error( $parent ) ) {
		return $parent;
	}

	apply_session_user_context( $session );
	$user_id   = absint( $session['user']->ID );
	$parent_id = absint( $parent->get_id() );

	if ( ! product_status_is_readable( $user_id, $parent->get_status() ) ) {
		return new \WP_Error( 'fandoogh_variation_parent_not_found', __( 'محصول والد variation پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	if ( $write && ! product_user_can_edit_existing( $user_id, $parent_id ) ) {
		return new \WP_Error( 'fandoogh_variation_write_forbidden', __( 'کاربر اجازهٔ ویرایش variationهای این محصول را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function product_variations_read_permission( $request ) {
	return product_variation_permission( $request, false );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function product_variations_write_permission( $request ) {
	return product_variation_permission( $request, true );
}

/**
 * @param \WP_REST_Response $response REST response.
 * @return \WP_REST_Response
 */
function product_variation_no_store_response( $response ) {
	return product_no_store_response( $response );
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function product_variation_query_integer( $value, $default, $min, $max ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return $default;
	}

	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * @param object $variation WooCommerce variation object.
 * @return array<string, mixed>
 */
function serialize_product_variation( $variation ) {
	$image_id  = absint( $variation->get_image_id() );
	$image_url = $image_id ? public_asset_url( wp_get_attachment_image_url( $image_id, 'medium' ) ) : null;
	$attributes = array();

	foreach ( (array) $variation->get_attributes() as $key => $value ) {
		$attributes[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
	}

	return array(
		'id'             => absint( $variation->get_id() ),
		'parent_id'      => absint( $variation->get_parent_id() ),
		'sku'            => sanitize_text_field( $variation->get_sku() ),
		'status'         => sanitize_key( $variation->get_status() ),
		'price'          => (string) $variation->get_price(),
		'regular_price'  => (string) $variation->get_regular_price(),
		'sale_price'     => (string) $variation->get_sale_price(),
		'stock_status'   => sanitize_key( $variation->get_stock_status() ),
		'stock_quantity' => null === $variation->get_stock_quantity() ? null : (int) $variation->get_stock_quantity(),
		'manage_stock'   => (bool) $variation->get_manage_stock(),
		'attributes'     => $attributes,
		'image'          => $image_url,
		'image_id'       => $image_id,
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_product_variations( $request ) {
	$parent = product_variation_parent( $request );
	if ( is_wp_error( $parent ) ) {
		return $parent;
	}

	$page     = product_variation_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = product_variation_query_integer( $request->get_param( 'per_page' ), 50, 1, 100 );
	$search   = isset( $request['search'] ) ? sanitize_text_field( (string) $request['search'] ) : '';
	$search   = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
	$children = method_exists( $parent, 'get_children' ) ? (array) $parent->get_children() : array();
	$items    = array();

	foreach ( $children as $child_id ) {
		$variation = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $child_id ) ) : false;
		if ( ! $variation || ! method_exists( $variation, 'is_type' ) || ! $variation->is_type( 'variation' ) ) {
			continue;
		}

		if ( '' !== $search ) {
			$haystack = strtolower( (string) $variation->get_sku() . ' ' . implode( ' ', array_values( (array) $variation->get_attributes() ) ) );
			if ( false === strpos( $haystack, strtolower( $search ) ) ) {
				continue;
			}
		}

		$items[] = $variation;
	}

	$total = count( $items );
	$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
	$data  = array_map( __NAMESPACE__ . '\\serialize_product_variation', $items );

	return product_variation_no_store_response(
		rest_ensure_response(
			array(
				'data' => $data,
				'meta' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
				),
			)
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return object|\WP_Error
 */
function product_variation_from_request( $request ) {
	$variation_id = absint( $request->get_param( 'id' ) );
	$variation    = $variation_id && function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : false;
	$parent_id    = absint( $request->get_param( 'product_id' ) );

	if ( ! $variation || ! method_exists( $variation, 'is_type' ) || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $parent_id ) {
		return new \WP_Error( 'fandoogh_variation_not_found', __( 'variation پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	return $variation;
}

/**
 * @param mixed  $value Candidate text.
 * @param string $field Field name.
 * @param int    $max_length Maximum character count.
 * @param bool   $multiline Whether line breaks are allowed.
 * @return string|\WP_Error
 */
function product_variation_plain_text( $value, $field, $max_length, $multiline = false ) {
	return product_write_plain_text( $value, $field, $max_length, $multiline );
}

/**
 * Normalize variation attributes against the parent product's declared
 * attributes. The API accepts keys with or without WooCommerce's
 * `attribute_` prefix, but never accepts an arbitrary key.
 *
 * @param mixed  $value Candidate attributes.
 * @param object $parent Parent product.
 * @return array|\WP_Error
 */
function product_variation_attributes( $value, $parent ) {
	if ( ! is_array( $value ) || count( $value ) > 50 ) {
		return new \WP_Error( 'fandoogh_variation_invalid_attributes', __( 'ویژگی‌های variation معتبر نیستند.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$declared = method_exists( $parent, 'get_attributes' ) ? (array) $parent->get_attributes() : array();
	$allowed  = array();
	foreach ( array_keys( $declared ) as $key ) {
		$key = sanitize_title( (string) $key );
		if ( '' !== $key ) {
			$allowed[ $key ] = true;
			$allowed[ 'attribute_' . $key ] = true;
		}
	}

	$attributes = array();
	foreach ( $value as $key => $candidate ) {
		if ( ! is_string( $key ) || ! is_scalar( $candidate ) || is_bool( $candidate ) ) {
			return new \WP_Error( 'fandoogh_variation_invalid_attributes', __( 'ساختار ویژگی‌های variation معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}

		$key = sanitize_title( $key );
		if ( ! isset( $allowed[ $key ] ) ) {
			return new \WP_Error( 'fandoogh_variation_attribute_forbidden', __( 'یکی از ویژگی‌های variation در محصول والد تعریف نشده است.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}

		$canonical_key = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
		$attribute     = product_variation_plain_text( $candidate, 'attribute', 200 );
		if ( is_wp_error( $attribute ) ) {
			return $attribute;
		}

		$attributes[ $canonical_key ] = taxonomy_exists( $canonical_key ) ? sanitize_title( $attribute ) : sanitize_text_field( $attribute );
	}

	return $attributes;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function product_variation_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return new \WP_Error( 'fandoogh_variation_json_required', __( 'بدنهٔ درخواست variation باید JSON باشد.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return new \WP_Error( 'fandoogh_variation_invalid_body', __( 'بدنهٔ درخواست variation معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$allowed_fields = array( 'sku', 'regular_price', 'sale_price', 'description', 'status', 'manage_stock', 'stock_quantity', 'attributes', 'image_id' );
	if ( ! empty( array_diff( array_keys( $body ), $allowed_fields ) ) ) {
		return new \WP_Error( 'fandoogh_variation_unknown_field', __( 'یکی از فیلدهای variation در قرارداد مجاز نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $body;
}

/**
 * @param array       $body Request body.
 * @param int         $user_id WordPress user ID.
 * @param object      $parent Parent product.
 * @param bool        $is_create Whether this is a new variation.
 * @param object|null $variation Existing variation.
 * @return array|\WP_Error
 */
function product_variation_values( $body, $user_id, $parent, $is_create = false, $variation = null ) {
	$values = array();

	if ( array_key_exists( 'sku', $body ) ) {
		$values['sku'] = product_variation_plain_text( $body['sku'], 'sku', 100 );
		if ( is_wp_error( $values['sku'] ) ) {
			return $values['sku'];
		}

		if ( '' !== $values['sku'] && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$existing_id = absint( wc_get_product_id_by_sku( $values['sku'] ) );
			$current_id  = $variation && method_exists( $variation, 'get_id' ) ? absint( $variation->get_id() ) : 0;
			if ( $existing_id && $existing_id !== $current_id ) {
				return new \WP_Error( 'fandoogh_variation_duplicate_sku', __( 'این SKU قبلاً استفاده شده است.', 'fandoogh-manager' ), array( 'status' => 409 ) );
			}
		}
	}

	foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
		if ( ! array_key_exists( $field, $body ) ) {
			continue;
		}

		$values[ $field ] = product_write_price( $body[ $field ], $field );
		if ( is_wp_error( $values[ $field ] ) ) {
			return $values[ $field ];
		}
	}

	if ( array_key_exists( 'description', $body ) ) {
		$values['description'] = product_variation_plain_text( $body['description'], 'description', 5000, true );
		if ( is_wp_error( $values['description'] ) ) {
			return $values['description'];
		}
	}

	if ( array_key_exists( 'status', $body ) ) {
		$values['status'] = product_write_status( $body['status'], $user_id );
		if ( is_wp_error( $values['status'] ) ) {
			return $values['status'];
		}
	} elseif ( $is_create ) {
		$values['status'] = 'draft';
	}

	if ( array_key_exists( 'manage_stock', $body ) ) {
		$values['manage_stock'] = product_write_boolean( $body['manage_stock'], 'manage_stock' );
		if ( is_wp_error( $values['manage_stock'] ) ) {
			return $values['manage_stock'];
		}
	}

	if ( array_key_exists( 'stock_quantity', $body ) ) {
		$values['stock_quantity'] = product_write_non_negative_integer( $body['stock_quantity'], 'stock_quantity', true );
		if ( is_wp_error( $values['stock_quantity'] ) ) {
			return $values['stock_quantity'];
		}

		if ( null !== $values['stock_quantity'] && array_key_exists( 'manage_stock', $values ) && ! $values['manage_stock'] ) {
			return new \WP_Error( 'fandoogh_variation_stock_management_required', __( 'برای تعیین موجودی، manage_stock باید true باشد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}

		if ( null !== $values['stock_quantity'] && ! array_key_exists( 'manage_stock', $values ) ) {
			$values['manage_stock'] = true;
		}
	}

	if ( array_key_exists( 'attributes', $body ) ) {
		$values['attributes'] = product_variation_attributes( $body['attributes'], $parent );
		if ( is_wp_error( $values['attributes'] ) ) {
			return $values['attributes'];
		}
	}

	if ( array_key_exists( 'image_id', $body ) ) {
		$values['image_id'] = product_write_attachment( $body['image_id'], 'image_id', $user_id );
		if ( is_wp_error( $values['image_id'] ) ) {
			return $values['image_id'];
		}
	}

	if ( ! $is_create && empty( $values ) ) {
		return new \WP_Error( 'fandoogh_variation_empty_update', __( 'برای ویرایش variation حداقل یک فیلد ارسال کنید.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $values;
}

/**
 * @param object               $variation Variation CRUD object.
 * @param array<string, mixed> $values Validated values.
 * @return true|\WP_Error
 */
function product_variation_apply_values( $variation, $values ) {
	try {
		if ( array_key_exists( 'status', $values ) ) {
			$variation->set_status( $values['status'] );
		}
		if ( array_key_exists( 'sku', $values ) ) {
			$variation->set_sku( $values['sku'] );
		}
		if ( array_key_exists( 'regular_price', $values ) ) {
			$variation->set_regular_price( $values['regular_price'] );
		}
		if ( array_key_exists( 'sale_price', $values ) ) {
			$variation->set_sale_price( $values['sale_price'] );
		}
		if ( array_key_exists( 'description', $values ) ) {
			$variation->set_description( $values['description'] );
		}
		if ( array_key_exists( 'manage_stock', $values ) ) {
			$variation->set_manage_stock( $values['manage_stock'] );
		}
		if ( array_key_exists( 'stock_quantity', $values ) ) {
			$variation->set_stock_quantity( $values['stock_quantity'] );
		}
		if ( array_key_exists( 'attributes', $values ) ) {
			$variation->set_attributes( $values['attributes'] );
		}
		if ( array_key_exists( 'image_id', $values ) ) {
			$variation->set_image_id( $values['image_id'] );
		}
	} catch ( \Throwable $exception ) {
		return new \WP_Error( 'fandoogh_variation_crud_rejected', __( 'WooCommerce داده‌های variation را نپذیرفت.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return true;
}

/**
 * @param object $variation Variation CRUD object.
 * @return object|\WP_Error
 */
function product_variation_save( $variation ) {
	try {
		$saved_id = absint( $variation->save() );
	} catch ( \Throwable $exception ) {
		return new \WP_Error( 'fandoogh_variation_save_failed', __( 'ذخیرهٔ variation انجام نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	if ( 0 === $saved_id && method_exists( $variation, 'get_id' ) ) {
		$saved_id = absint( $variation->get_id() );
	}

	$saved_variation = $saved_id && function_exists( 'wc_get_product' ) ? wc_get_product( $saved_id ) : false;
	if ( ! $saved_variation || ! method_exists( $saved_variation, 'is_type' ) || ! $saved_variation->is_type( 'variation' ) ) {
		return new \WP_Error( 'fandoogh_variation_save_failed', __( 'variation پس از ذخیره قابل بازیابی نیست.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	return $saved_variation;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function get_product_variation( $request ) {
	$variation = product_variation_from_request( $request );
	if ( is_wp_error( $variation ) ) {
		return $variation;
	}

	return product_variation_no_store_response( rest_ensure_response( array( 'data' => serialize_product_variation( $variation ) ) ) );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function create_product_variation( $request ) {
	$parent = product_variation_parent( $request );
	if ( is_wp_error( $parent ) ) {
		return $parent;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	$body    = product_variation_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$values = product_variation_values( $body, $user_id, $parent, true );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$variation = new \WC_Product_Variation();
	$variation->set_parent_id( absint( $parent->get_id() ) );
	$applied = product_variation_apply_values( $variation, $values );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}

	$saved_variation = product_variation_save( $variation );
	if ( is_wp_error( $saved_variation ) ) {
		return $saved_variation;
	}

	$response = product_variation_no_store_response( rest_ensure_response( array( 'data' => serialize_product_variation( $saved_variation ) ) ) );
	record_audit_event( 'variation_created', $session['user']->ID, $session['id'], $session['device_label'], 'variation', $saved_variation->get_id(), array( 'product_id' => $parent->get_id() ) );
	$response->set_status( 201 );
	return $response;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function update_product_variation( $request ) {
	$parent = product_variation_parent( $request );
	if ( is_wp_error( $parent ) ) {
		return $parent;
	}

	$variation = product_variation_from_request( $request );
	if ( is_wp_error( $variation ) ) {
		return $variation;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	$body    = product_variation_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$values = product_variation_values( $body, $user_id, $parent, false, $variation );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$applied = product_variation_apply_values( $variation, $values );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}

	$saved_variation = product_variation_save( $variation );
	if ( is_wp_error( $saved_variation ) ) {
		return $saved_variation;
	}

	record_audit_event( 'variation_updated', $session['user']->ID, $session['id'], $session['device_label'], 'variation', $saved_variation->get_id(), array( 'product_id' => $parent->get_id() ) );
	return product_variation_no_store_response( rest_ensure_response( array( 'data' => serialize_product_variation( $saved_variation ) ) ) );
}
