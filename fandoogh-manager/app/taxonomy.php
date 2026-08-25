<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the product-category contract. Terms are read and written through
 * WordPress taxonomy APIs; this module deliberately has no SQL or term-meta
 * surface.
 *
 * @return void
 */
function register_taxonomy_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/product-categories',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\list_product_categories',
				'permission_callback' => __NAMESPACE__ . '\\product_categories_read_permission',
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\create_product_category',
				'permission_callback' => __NAMESPACE__ . '\\product_categories_write_permission',
			),
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/product-categories/(?P<id>\\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\get_product_category',
				'permission_callback' => __NAMESPACE__ . '\\product_categories_read_permission',
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => __NAMESPACE__ . '\\update_product_category',
				'permission_callback' => __NAMESPACE__ . '\\product_categories_write_permission',
			),
		)
	);
}

/**
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function product_category_user_can_read( $user_id ) {
	return user_can( $user_id, 'edit_products' )
		|| user_can( $user_id, 'manage_woocommerce' )
		|| user_can( $user_id, 'manage_options' )
		|| user_can( $user_id, 'manage_product_terms', 'product_cat' )
		|| user_can( $user_id, 'edit_product_terms', 'product_cat' );
}

/**
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function product_category_user_can_write( $user_id ) {
	return user_can( $user_id, 'manage_woocommerce' )
		|| user_can( $user_id, 'manage_options' )
		|| user_can( $user_id, 'manage_product_terms', 'product_cat' )
		|| user_can( $user_id, 'edit_product_terms', 'product_cat' );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function product_categories_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'categories.read', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_categories_read_scope', __( 'نشست فعلی مجوز خواندن دسته‌بندی‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	if ( ! taxonomy_exists( 'product_cat' ) || ! product_category_user_can_read( absint( $session['user']->ID ) ) ) {
		return new \WP_Error( 'fandoogh_categories_read_forbidden', __( 'کاربر WordPress مجوز خواندن دسته‌بندی‌های محصول را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function product_categories_write_permission( $request ) {
	$csrf_result = csrf_permission( $request );
	if ( is_wp_error( $csrf_result ) ) {
		return $csrf_result;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'categories.write', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_categories_write_scope', __( 'نشست فعلی مجوز نوشتن دسته‌بندی‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return new \WP_Error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی و دسته‌بندی‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	if ( ! taxonomy_exists( 'product_cat' ) || ! product_category_user_can_write( absint( $session['user']->ID ) ) ) {
		return new \WP_Error( 'fandoogh_categories_write_forbidden', __( 'کاربر WordPress مجوز نوشتن دسته‌بندی‌های محصول را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function product_category_query_integer( $value, $default, $min, $max ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return $default;
	}

	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * @param \WP_REST_Response $response REST response.
 * @return \WP_REST_Response
 */
function product_category_no_store_response( $response ) {
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	return $response;
}

/**
 * @param mixed  $value Candidate text.
 * @param string $field Field name.
 * @param int    $max_length Maximum character count.
 * @param bool   $multiline Whether line breaks are allowed.
 * @return string|\WP_Error
 */
function product_category_plain_text( $value, $field, $max_length, $multiline = false ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return new \WP_Error( 'fandoogh_category_invalid_' . sanitize_key( $field ), __( 'یکی از فیلدهای دسته‌بندی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$value = (string) $value;
	if ( false !== strpos( $value, "\0" ) || wp_strip_all_tags( $value ) !== $value ) {
		return new \WP_Error( 'fandoogh_category_invalid_' . sanitize_key( $field ), __( 'HTML خام یا کاراکتر غیرمجاز در دسته‌بندی پذیرفته نمی‌شود.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	if ( ! $multiline && preg_match( '/[\r\n\t]/', $value ) ) {
		return new \WP_Error( 'fandoogh_category_invalid_' . sanitize_key( $field ), __( 'این فیلد دسته‌بندی نباید چندخطی باشد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	if ( $length > absint( $max_length ) ) {
		return new \WP_Error( 'fandoogh_category_field_too_long', __( 'طول یکی از فیلدهای دسته‌بندی بیشتر از حد مجاز است.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
}

/**
 * @param mixed $value Candidate parent term ID.
 * @param int   $current_id Current term ID, if updating.
 * @return int|\WP_Error
 */
function product_category_parent_id( $value, $current_id = 0 ) {
	if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
		return 0;
	}

	if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $value ) ) ) {
		return new \WP_Error( 'fandoogh_category_invalid_parent', __( 'والد دسته‌بندی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$parent_id = absint( $value );
	if ( 0 === $parent_id || $parent_id === absint( $current_id ) || ! term_exists( $parent_id, 'product_cat' ) ) {
		return new \WP_Error( 'fandoogh_category_invalid_parent', __( 'والد دسته‌بندی پیدا نشد یا معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $parent_id;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function product_category_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return new \WP_Error( 'fandoogh_category_json_required', __( 'بدنهٔ درخواست دسته‌بندی باید JSON باشد.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return new \WP_Error( 'fandoogh_category_invalid_body', __( 'بدنهٔ درخواست دسته‌بندی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$allowed_fields = array( 'name', 'slug', 'parent', 'description' );
	if ( ! empty( array_diff( array_keys( $body ), $allowed_fields ) ) ) {
		return new \WP_Error( 'fandoogh_category_unknown_field', __( 'یکی از فیلدهای دسته‌بندی در قرارداد مجاز نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $body;
}

/**
 * @param array       $body Request body.
 * @param bool        $is_create Whether this is a new term.
 * @param object|null $term Existing term.
 * @return array|\WP_Error
 */
function product_category_values( $body, $is_create = false, $term = null ) {
	$values = array();
	$current_id = $term && isset( $term->term_id ) ? absint( $term->term_id ) : 0;

	if ( $is_create && ! array_key_exists( 'name', $body ) ) {
		return new \WP_Error( 'fandoogh_category_name_required', __( 'نام دسته‌بندی الزامی است.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	if ( array_key_exists( 'name', $body ) ) {
		$values['name'] = product_category_plain_text( $body['name'], 'name', 200 );
		if ( is_wp_error( $values['name'] ) ) {
			return $values['name'];
		}
		if ( '' === trim( $values['name'] ) ) {
			return new \WP_Error( 'fandoogh_category_name_required', __( 'نام دسته‌بندی نمی‌تواند خالی باشد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}
	}

	if ( array_key_exists( 'slug', $body ) ) {
		$slug_text = product_category_plain_text( $body['slug'], 'slug', 200 );
		if ( is_wp_error( $slug_text ) ) {
			return $slug_text;
		}
		$slug = sanitize_title( $slug_text );
		if ( '' !== trim( $slug_text ) && '' === $slug ) {
			return new \WP_Error( 'fandoogh_category_invalid_slug', __( 'شناسهٔ دسته‌بندی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}
		$values['slug'] = $slug;
	}

	if ( array_key_exists( 'description', $body ) ) {
		$values['description'] = product_category_plain_text( $body['description'], 'description', 2000, true );
		if ( is_wp_error( $values['description'] ) ) {
			return $values['description'];
		}
	}

	if ( array_key_exists( 'parent', $body ) ) {
		$values['parent'] = product_category_parent_id( $body['parent'], $current_id );
		if ( is_wp_error( $values['parent'] ) ) {
			return $values['parent'];
		}
	}

	if ( ! $is_create && empty( $values ) ) {
		return new \WP_Error( 'fandoogh_category_empty_update', __( 'برای ویرایش دسته‌بندی حداقل یک فیلد ارسال کنید.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	return $values;
}

/**
 * @param object $term Product category term.
 * @return array<string, mixed>
 */
function serialize_product_category( $term ) {
	return array(
		'id'          => absint( $term->term_id ),
		'name'        => sanitize_text_field( $term->name ),
		'slug'        => sanitize_title( $term->slug ),
		'parent'      => absint( $term->parent ),
		'description' => sanitize_textarea_field( wp_strip_all_tags( $term->description ) ),
		'count'       => absint( $term->count ),
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_product_categories( $request ) {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'Taxonomy دسته‌بندی محصول در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	$page     = product_category_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = product_category_query_integer( $request->get_param( 'per_page' ), 50, 1, 100 );
	$search   = isset( $request['search'] ) ? sanitize_text_field( (string) $request['search'] ) : '';
	$search   = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
	$args     = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'number'     => $per_page,
		'offset'     => ( $page - 1 ) * $per_page,
		'orderby'    => 'name',
		'order'      => 'ASC',
	);

	if ( '' !== $search ) {
		$args['search'] = $search;
	}

	if ( isset( $request['parent'] ) && '' !== (string) $request['parent'] ) {
		$parent = product_category_query_integer( $request->get_param( 'parent' ), 0, 0, PHP_INT_MAX );
		$args['parent'] = $parent;
	}

	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		return new \WP_Error( 'fandoogh_category_read_failed', __( 'خواندن دسته‌بندی‌ها انجام نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	$count_args          = $args;
	$count_args['number'] = 0;
	$count_args['offset'] = 0;
	$count_args['fields'] = 'ids';
	$all_ids              = get_terms( $count_args );
	$total                = is_wp_error( $all_ids ) || ! is_array( $all_ids ) ? count( $terms ) : count( $all_ids );
	$items                = array_map( __NAMESPACE__ . '\\serialize_product_category', is_array( $terms ) ? $terms : array() );

	return product_category_no_store_response(
		rest_ensure_response(
			array(
				'data' => $items,
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
 * @return \WP_REST_Response|\WP_Error
 */
function get_product_category( $request ) {
	$term_id = absint( $request['id'] );
	$term    = $term_id ? get_term( $term_id, 'product_cat' ) : false;
	if ( ! $term || is_wp_error( $term ) ) {
		return new \WP_Error( 'fandoogh_category_not_found', __( 'دسته‌بندی پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	return product_category_no_store_response( rest_ensure_response( array( 'data' => serialize_product_category( $term ) ) ) );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function create_product_category( $request ) {
	$body = product_category_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$values = product_category_values( $body, true );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$args = array();
	foreach ( array( 'slug', 'parent', 'description' ) as $field ) {
		if ( array_key_exists( $field, $values ) ) {
			$args[ $field ] = $values[ $field ];
		}
	}

	$inserted = wp_insert_term( $values['name'], 'product_cat', $args );
	if ( is_wp_error( $inserted ) || empty( $inserted['term_id'] ) ) {
		return new \WP_Error( 'fandoogh_category_save_failed', __( 'ساخت دسته‌بندی انجام نشد.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	$term = get_term( absint( $inserted['term_id'] ), 'product_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		return new \WP_Error( 'fandoogh_category_save_failed', __( 'دسته‌بندی پس از ساخت قابل بازیابی نیست.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	record_current_session_audit( 'category_created', 'category', $term->term_id );
	$response = product_category_no_store_response( rest_ensure_response( array( 'data' => serialize_product_category( $term ) ) ) );
	$response->set_status( 201 );
	return $response;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function update_product_category( $request ) {
	$term_id = absint( $request['id'] );
	$term    = $term_id ? get_term( $term_id, 'product_cat' ) : false;
	if ( ! $term || is_wp_error( $term ) ) {
		return new \WP_Error( 'fandoogh_category_not_found', __( 'دسته‌بندی پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	$body = product_category_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$values = product_category_values( $body, false, $term );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$args = array();
	foreach ( array( 'name', 'slug', 'parent', 'description' ) as $field ) {
		if ( array_key_exists( $field, $values ) ) {
			$args[ $field ] = $values[ $field ];
		}
	}

	$updated = wp_update_term( $term_id, 'product_cat', $args );
	if ( is_wp_error( $updated ) || empty( $updated['term_id'] ) ) {
		return new \WP_Error( 'fandoogh_category_save_failed', __( 'ویرایش دسته‌بندی انجام نشد.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	$updated_term = get_term( $term_id, 'product_cat' );
	if ( ! $updated_term || is_wp_error( $updated_term ) ) {
		return new \WP_Error( 'fandoogh_category_save_failed', __( 'دسته‌بندی پس از ویرایش قابل بازیابی نیست.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	record_current_session_audit( 'category_updated', 'category', $updated_term->term_id );
	return product_category_no_store_response( rest_ensure_response( array( 'data' => serialize_product_category( $updated_term ) ) ) );
}
