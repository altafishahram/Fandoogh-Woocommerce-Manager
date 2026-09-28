<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/coupons.php';

/**
 * Site-local coupon management built on the WooCommerce CRUD API. The PWA
 * exposes only the fields required for day-to-day promotion management and
 * never proxies wp-admin or accepts arbitrary coupon meta.
 */
function register_coupon_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/coupons',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\list_coupons',
				'permission_callback' => __NAMESPACE__ . '\\coupons_read_permission',
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\create_coupon',
				'permission_callback' => __NAMESPACE__ . '\\coupons_write_permission',
			),
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/coupons/(?P<id>\\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\get_coupon_detail',
				'permission_callback' => __NAMESPACE__ . '\\coupons_read_permission',
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => __NAMESPACE__ . '\\update_coupon',
				'permission_callback' => __NAMESPACE__ . '\\coupons_write_permission',
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\delete_coupon',
				'permission_callback' => __NAMESPACE__ . '\\coupons_write_permission',
			),
		)
	);
}

function coupons_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
	);
}

function coupons_response( $payload, $status = 200 ) {
	$response = rest_ensure_response( $payload );
	$response->set_status( absint( $status ) );
	foreach ( coupons_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}

function coupons_error( $code, $message, $status = 422 ) {
	return new \WP_Error( $code, $message, array( 'status' => absint( $status ), 'headers' => coupons_headers() ) );
}

function coupons_available() {
	return compose_coupon_repository()->isAvailable();
}

function coupons_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'coupons.read', $session['scopes'] ) ) {
		return coupons_error( 'fandoogh_coupons_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ کوپن‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return coupons_error( 'fandoogh_coupons_forbidden', __( 'کاربر WordPress مجوز مدیریت کوپن‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function coupons_write_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'coupons.write', $session['scopes'] ) ) {
		return coupons_error( 'fandoogh_coupons_write_scope', __( 'نشست فعلی مجوز تغییر کوپن‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return coupons_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی و مدیریت کوپن‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return coupons_error( 'fandoogh_coupons_forbidden', __( 'کاربر WordPress مجوز تغییر کوپن‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function coupon_clean_text( $value, $max = 160 ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return '';
	}
	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
}

function coupon_decimal( $value, $field, $allow_empty = true ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return coupons_error( 'fandoogh_coupon_invalid_' . sanitize_key( $field ), __( 'مقدار عددی کوپن معتبر نیست.', 'fandoogh-manager' ) );
	}
	$value = trim( (string) $value );
	if ( $allow_empty && '' === $value ) {
		return '';
	}
	if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$/D', $value ) ) {
		return coupons_error( 'fandoogh_coupon_invalid_' . sanitize_key( $field ), __( 'مقدار عددی کوپن معتبر نیست.', 'fandoogh-manager' ) );
	}
	return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, false, false ) : $value;
}

function coupon_boolean( $value, $field ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( 1 === $value || '1' === $value || 'true' === $value ) {
		return true;
	}
	if ( 0 === $value || '0' === $value || 'false' === $value ) {
		return false;
	}
	return coupons_error( 'fandoogh_coupon_invalid_' . sanitize_key( $field ), __( 'مقدار بولی کوپن معتبر نیست.', 'fandoogh-manager' ) );
}

function coupon_id_list( $value, $field, $max = 100 ) {
	if ( ! is_array( $value ) || count( $value ) > $max ) {
		return coupons_error( 'fandoogh_coupon_invalid_' . sanitize_key( $field ), __( 'فهرست محدودیت کوپن معتبر نیست.', 'fandoogh-manager' ) );
	}
	$ids = array();
	foreach ( $value as $candidate ) {
		if ( ! is_scalar( $candidate ) || ! preg_match( '/^[0-9]+$/', (string) $candidate ) || absint( $candidate ) < 1 ) {
			return coupons_error( 'fandoogh_coupon_invalid_' . sanitize_key( $field ), __( 'یکی از شناسه‌های کوپن معتبر نیست.', 'fandoogh-manager' ) );
		}
		$ids[] = absint( $candidate );
	}
	return array_values( array_unique( $ids ) );
}

function coupon_date( $value ) {
	if ( null === $value || '' === (string) $value ) {
		return null;
	}
	if ( ! is_scalar( $value ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', (string) $value ) ) {
		return coupons_error( 'fandoogh_coupon_invalid_expiry', __( 'تاریخ انقضای کوپن معتبر نیست.', 'fandoogh-manager' ) );
	}
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
	$errors = \DateTimeImmutable::getLastErrors();
	if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
		return coupons_error( 'fandoogh_coupon_invalid_expiry', __( 'تاریخ انقضای کوپن معتبر نیست.', 'fandoogh-manager' ) );
	}
	return $date->format( 'Y-m-d' );
}

function coupon_request_body( $request ) {
	return compose_coupon_request_parser()->parse( $request );
}

function coupon_values( $body, $is_create = false ) {
	$values = array();
	if ( $is_create || array_key_exists( 'code', $body ) ) {
		$values['code'] = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', coupon_clean_text( isset( $body['code'] ) ? $body['code'] : '', 80 ) ) );
		if ( '' === $values['code'] ) {
			return coupons_error( 'fandoogh_coupon_code_required', __( 'کد کوپن الزامی است.', 'fandoogh-manager' ) );
		}
	}
	foreach ( array( 'description' => 255 ) as $field => $max ) {
		if ( array_key_exists( $field, $body ) ) {
			$values[ $field ] = coupon_clean_text( $body[ $field ], $max );
		}
	}
	if ( array_key_exists( 'discount_type', $body ) || $is_create ) {
		$type = sanitize_key( (string) ( isset( $body['discount_type'] ) ? $body['discount_type'] : 'fixed_cart' ) );
		if ( ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
			return coupons_error( 'fandoogh_coupon_invalid_type', __( 'نوع تخفیف کوپن معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['discount_type'] = $type;
	}
	if ( array_key_exists( 'amount', $body ) || $is_create ) {
		$values['amount'] = coupon_decimal( isset( $body['amount'] ) ? $body['amount'] : '', 'amount', false );
		if ( is_wp_error( $values['amount'] ) ) {
			return $values['amount'];
		}
	}
	if ( array_key_exists( 'date_expires', $body ) ) {
		$values['date_expires'] = coupon_date( $body['date_expires'] );
		if ( is_wp_error( $values['date_expires'] ) ) {
			return $values['date_expires'];
		}
	}
	foreach ( array( 'free_shipping', 'individual_use', 'exclude_sale_items' ) as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$values[ $field ] = coupon_boolean( $body[ $field ], $field );
			if ( is_wp_error( $values[ $field ] ) ) {
				return $values[ $field ];
			}
		}
	}
	foreach ( array( 'minimum_amount', 'maximum_amount' ) as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$values[ $field ] = coupon_decimal( $body[ $field ], $field );
			if ( is_wp_error( $values[ $field ] ) ) {
				return $values[ $field ];
			}
		}
	}
	foreach ( array( 'usage_limit', 'usage_limit_per_user' ) as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$value = $body[ $field ];
			if ( '' === (string) $value || null === $value ) {
				$values[ $field ] = 0;
			} elseif ( is_scalar( $value ) && preg_match( '/^[0-9]{1,9}$/', (string) $value ) ) {
				$values[ $field ] = absint( $value );
			} else {
				return coupons_error( 'fandoogh_coupon_invalid_' . $field, __( 'محدودیت استفادهٔ کوپن معتبر نیست.', 'fandoogh-manager' ) );
			}
		}
	}
	foreach ( array( 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories' ) as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$values[ $field ] = coupon_id_list( $body[ $field ], $field );
			if ( is_wp_error( $values[ $field ] ) ) {
				return $values[ $field ];
			}
		}
	}
	if ( array_key_exists( 'status', $body ) ) {
		$status = sanitize_key( (string) $body['status'] );
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return coupons_error( 'fandoogh_coupon_invalid_status', __( 'وضعیت کوپن معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['status'] = $status;
	} elseif ( $is_create ) {
		$values['status'] = 'publish';
	}
	return $values;
}

function serialize_coupon( $coupon ) {
	$date_expires = method_exists( $coupon, 'get_date_expires' ) ? $coupon->get_date_expires() : null;
	return array(
		'id'                       => absint( $coupon->get_id() ),
		'code'                     => coupon_clean_text( $coupon->get_code(), 80 ),
		'description'              => coupon_clean_text( $coupon->get_description(), 255 ),
		'discount_type'            => sanitize_key( $coupon->get_discount_type() ),
		'amount'                   => (string) $coupon->get_amount(),
		'date_expires'             => $date_expires ? $date_expires->date( 'Y-m-d' ) : null,
		'free_shipping'            => (bool) $coupon->get_free_shipping(),
		'individual_use'           => (bool) $coupon->get_individual_use(),
		'exclude_sale_items'       => (bool) $coupon->get_exclude_sale_items(),
		'minimum_amount'           => (string) $coupon->get_minimum_amount(),
		'maximum_amount'           => (string) $coupon->get_maximum_amount(),
		'usage_limit'              => absint( $coupon->get_usage_limit() ),
		'usage_limit_per_user'     => absint( $coupon->get_usage_limit_per_user() ),
		'usage_count'              => absint( $coupon->get_usage_count() ),
		'product_ids'              => array_values( array_map( 'absint', (array) $coupon->get_product_ids() ) ),
		'excluded_product_ids'     => array_values( array_map( 'absint', (array) $coupon->get_excluded_product_ids() ) ),
		'product_categories'       => array_values( array_map( 'absint', (array) $coupon->get_product_categories() ) ),
		'excluded_product_categories' => array_values( array_map( 'absint', (array) $coupon->get_excluded_product_categories() ) ),
		'status'                   => sanitize_key( get_post_status( $coupon->get_id() ) ),
	);
}

function coupon_apply_values( $coupon, $values ) {
	try {
		$setters = array(
			'code' => 'set_code', 'description' => 'set_description', 'discount_type' => 'set_discount_type',
			'amount' => 'set_amount', 'free_shipping' => 'set_free_shipping', 'individual_use' => 'set_individual_use',
			'exclude_sale_items' => 'set_exclude_sale_items', 'minimum_amount' => 'set_minimum_amount',
			'maximum_amount' => 'set_maximum_amount', 'usage_limit' => 'set_usage_limit',
			'usage_limit_per_user' => 'set_usage_limit_per_user', 'product_ids' => 'set_product_ids',
			'excluded_product_ids' => 'set_excluded_product_ids', 'product_categories' => 'set_product_categories',
			'excluded_product_categories' => 'set_excluded_product_categories', 'status' => 'set_status',
		);
		foreach ( $setters as $key => $setter ) {
			if ( array_key_exists( $key, $values ) && method_exists( $coupon, $setter ) ) {
				$coupon->{$setter}( $values[ $key ] );
			}
		}
		if ( array_key_exists( 'date_expires', $values ) && method_exists( $coupon, 'set_date_expires' ) ) {
			$coupon->set_date_expires( $values['date_expires'] );
		}
		if ( ! $coupon->save() ) {
			throw new \RuntimeException( 'Coupon save did not return an ID.' );
		}
	} catch ( \Throwable $exception ) {
		return coupons_error( 'fandoogh_coupon_save_failed', __( 'ذخیرهٔ کوپن انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	return true;
}

function coupon_from_id( $id ) {
	$coupon = compose_coupon_repository()->findById( absint( $id ) );
	return null === $coupon ? false : $coupon;
}

function list_coupons( $request ) {
	global $wpdb;

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! coupons_available() ) {
		return coupons_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce یا CRUD کوپن در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	apply_session_user_context( $session );
	$page = max( 1, min( 100000, absint( $request->get_param( 'page' ) ?: 1 ) ) );
	$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
	// Match WooCommerce's REST coupon lookup: query post IDs, then use CRUD
	// objects for coupon data. WooCommerce has no standard wc_get_coupons().
	$args = array(
		'post_type'           => 'shop_coupon',
		'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'fields'              => 'ids',
		'posts_per_page'      => $per_page,
		'paged'               => $page,
		'orderby'             => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => false,
	);
	$search = coupon_clean_text( $request->get_param( 'search' ), 80 );
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	try {
		// WP_Query can return an empty array on database failure. Clear stale
		// diagnostics first so a cached successful query is not misclassified.
		if ( isset( $wpdb->last_error ) ) {
			$wpdb->last_error = '';
		}
		$query = compose_coupon_repository()->query( $args );
		if ( ! is_array( $query->posts ) || ! isset( $query->found_posts, $query->max_num_pages ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Coupon query failed.' );
		}
		$total = absint( $query->found_posts );
		$pages = absint( $query->max_num_pages );
		$items = array();
		foreach ( $query->posts as $coupon_id ) {
			$coupon = compose_coupon_repository()->findById( absint( $coupon_id ) );
			if ( ! $coupon ) {
				throw new \RuntimeException( 'Coupon could not be read.' );
			}
			if ( ! $coupon->get_id() ) {
				throw new \RuntimeException( 'Coupon could not be read.' );
			}
			$items[] = serialize_coupon( $coupon );
		}
	} catch ( \Throwable $exception ) {
		return coupons_error( 'fandoogh_coupons_query_failed', __( 'خواندن فهرست کوپن‌ها انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	return coupons_response( array( 'data' => $items, 'meta' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $pages ) ) );
}

function get_coupon_detail( $request ) {
	$coupon = coupon_from_id( $request->get_param( 'id' ) );
	if ( ! $coupon || ! $coupon->get_id() ) {
		return coupons_error( 'fandoogh_coupon_not_found', __( 'کوپن پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	return coupons_response( array( 'data' => serialize_coupon( $coupon ) ) );
}

function create_coupon( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! coupons_available() ) {
		return coupons_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce یا CRUD کوپن در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	$body = coupon_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	$values = coupon_values( $body, true );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	if ( compose_coupon_repository()->findIdByCode( $values['code'] ) ) {
		return coupons_error( 'fandoogh_coupon_duplicate', __( 'این کد کوپن قبلاً وجود دارد.', 'fandoogh-manager' ), 409 );
	}
	$coupon = compose_coupon_repository()->create();
	$applied = coupon_apply_values( $coupon, $values );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}
	$session = get_session_context();
	record_audit_event( 'coupon_created', $session['user']->ID, $session['id'], $session['device_label'], 'coupon', $coupon->get_id() );
	return coupons_response( array( 'data' => serialize_coupon( $coupon ) ), 201 );
}

function update_coupon( $request ) {
	$coupon = coupon_from_id( $request->get_param( 'id' ) );
	if ( ! $coupon || ! $coupon->get_id() ) {
		return coupons_error( 'fandoogh_coupon_not_found', __( 'کوپن پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	$body = coupon_request_body( $request );
	if ( is_wp_error( $body ) || empty( $body ) ) {
		return is_wp_error( $body ) ? $body : coupons_error( 'fandoogh_coupon_empty_update', __( 'حداقل یک فیلد برای ویرایش کوپن ارسال کنید.', 'fandoogh-manager' ) );
	}
	$values = coupon_values( $body );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	if ( isset( $values['code'] ) && function_exists( 'wc_get_coupon_id_by_code' ) ) {
		$existing = compose_coupon_repository()->findIdByCode( $values['code'] );
		if ( $existing && $existing !== absint( $coupon->get_id() ) ) {
			return coupons_error( 'fandoogh_coupon_duplicate', __( 'این کد کوپن قبلاً وجود دارد.', 'fandoogh-manager' ), 409 );
		}
	}
	$applied = coupon_apply_values( $coupon, $values );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}
	$session = get_session_context();
	record_audit_event( 'coupon_updated', $session['user']->ID, $session['id'], $session['device_label'], 'coupon', $coupon->get_id() );
	return coupons_response( array( 'data' => serialize_coupon( $coupon ) ) );
}

function delete_coupon( $request ) {
	$coupon = coupon_from_id( $request->get_param( 'id' ) );
	if ( ! $coupon || ! $coupon->get_id() ) {
		return coupons_error( 'fandoogh_coupon_not_found', __( 'کوپن پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	try {
		wp_trash_post( $coupon->get_id() );
	} catch ( \Throwable $exception ) {
		return coupons_error( 'fandoogh_coupon_delete_failed', __( 'حذف کوپن انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	$session = get_session_context();
	record_audit_event( 'coupon_deleted', $session['user']->ID, $session['id'], $session['device_label'], 'coupon', $coupon->get_id() );
	return coupons_response( array( 'data' => array( 'id' => absint( $coupon->get_id() ), 'deleted' => true ) ) );
}
