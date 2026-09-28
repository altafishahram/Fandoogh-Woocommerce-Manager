<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/orders.php';
require_once __DIR__ . '/composition/customers.php';
require_once __DIR__ . '/composition/products.php';
require_once __DIR__ . '/composition/coupons.php';

/**
 * Register authenticated order read and status-update endpoints.
 *
 * This module deliberately uses the WooCommerce order data abstraction. It
 * does not query WordPress tables or expose arbitrary order data.
 *
 * @return void
 */
function register_order_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/orders',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\list_orders',
				'permission_callback' => __NAMESPACE__ . '\\orders_read_permission',
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\create_order',
				'permission_callback' => __NAMESPACE__ . '\\orders_create_permission',
			),
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/orders/(?P<id>\\d+)',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_order_detail',
			'permission_callback' => __NAMESPACE__ . '\\orders_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/orders/(?P<id>\\d+)/status',
		array(
			'methods'              => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\update_order_status',
			'permission_callback' => __NAMESPACE__ . '\\orders_status_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/orders/(?P<id>\\d+)/notes',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\add_order_note',
			'permission_callback' => __NAMESPACE__ . '\\orders_note_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/orders/(?P<id>\\d+)/refund',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\refund_order',
			'permission_callback' => __NAMESPACE__ . '\\orders_refund_permission',
		)
	);
}

/**
 * Headers for every order response, including errors returned by a permission
 * callback. Order data must not be retained by a browser, proxy, or service
 * worker cache.
 *
 * @return array<string, string>
 */
function orders_private_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
	);
}

/**
 * Add private response headers to a successful REST response.
 *
 * @param mixed $payload Response payload.
 * @return \WP_REST_Response
 */
function orders_no_store_response( $payload ) {
	$response = rest_ensure_response( $payload );
	foreach ( orders_private_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}

/**
 * Preserve an error while making its REST conversion carry private headers.
 *
 * @param \WP_Error $error Error to decorate.
 * @return \WP_Error
 */
function orders_private_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return $error;
	}

	$code = $error->get_error_code();
	$data = $error->get_error_data( $code );
	$data = is_array( $data ) ? $data : array();
	$headers = isset( $data['headers'] ) && is_array( $data['headers'] ) ? $data['headers'] : array();
	$data['headers'] = array_replace( $headers, orders_private_headers() );

	return new \WP_Error( $code, $error->get_error_message( $code ), $data );
}

/**
 * Create a clear, non-sensitive order API error.
 *
 * @param string               $code Error code.
 * @param string               $message User-facing message.
 * @param int                  $status HTTP status.
 * @param array<string, mixed> $extra Extra WP_Error data.
 * @return \WP_Error
 */
function orders_error( $code, $message, $status, $extra = array() ) {
	$data = is_array( $extra ) ? $extra : array();
	$data['status'] = absint( $status );
	return orders_private_error( new \WP_Error( $code, $message, $data ) );
}

/**
 * Check the PWA session, the narrow order scope, and the real WordPress
 * capability on every request.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function orders_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! session_has_scope( 'orders.read', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز خواندن سفارش‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز خواندن سفارش‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * Manual order creation is a high-impact mutation. It requires its own
 * session scope in addition to the existing CSRF, capability and major-change
 * boundaries; reading the order list must never grant create access.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function orders_create_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return orders_private_error( $csrf );
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! session_has_scope( 'orders.create', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز ثبت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return orders_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز ثبت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function orders_status_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return orders_private_error( $csrf );
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! session_has_scope( 'orders.update_status', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز تغییر وضعیت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return orders_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز تغییر وضعیت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * Permission boundary for adding an internal/customer order note.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function orders_note_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return orders_private_error( $csrf );
	}
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! session_has_scope( 'orders.add_note', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز افزودن یادداشت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز افزودن یادداشت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

/**
 * Refunds are deliberately manager-only and require an explicit CSRF check.
 * Automatic gateway refunds may still be rejected by the gateway; the PWA
 * surfaces that error instead of pretending that a status change refunded it.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function orders_refund_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return orders_private_error( $csrf );
	}
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! session_has_scope( 'orders.refund', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز بازپرداخت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return orders_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return orders_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز بازپرداخت سفارش را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function orders_status_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return orders_error( 'fandoogh_json_required', __( 'بدنهٔ درخواست وضعیت سفارش باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! isset( $body['status'], $body['expected_status'] ) || count( array_diff( array_keys( $body ), array( 'status', 'expected_status', 'note' ) ) ) > 0 ) {
		return orders_error( 'fandoogh_invalid_input', __( 'وضعیت فعلی و وضعیت جدید سفارش معتبر نیستند.', 'fandoogh-manager' ), 422 );
	}
	if ( ! is_scalar( $body['status'] ) || ! is_scalar( $body['expected_status'] ) || '' === trim( (string) $body['status'] ) || '' === trim( (string) $body['expected_status'] ) ) {
		return orders_error( 'fandoogh_invalid_input', __( 'وضعیت فعلی و وضعیت جدید سفارش باید مقدار متنی معتبر باشند.', 'fandoogh-manager' ), 422 );
	}

	return $body;
}

/**
 * Normalize a manual-order decimal without using floating point arithmetic.
 *
 * @param mixed  $value Candidate decimal.
 * @param string $field Public field name.
 * @return string|\WP_Error
 */
function orders_create_decimal( $value, $field ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return orders_error( 'fandoogh_order_invalid_amount', sprintf( __( 'مقدار %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$value = trim( (string) $value );
	if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value ) ) {
		return orders_error( 'fandoogh_order_invalid_amount', sprintf( __( 'مقدار %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$normalized = $value;
	$decimal_normalizer = __NAMESPACE__ . '\\bulk_price_decimal_normalize';
	if ( function_exists( $decimal_normalizer ) ) {
		$normalized = call_user_func( $decimal_normalizer, $value );
	}
	if ( false === $normalized || '' === (string) $normalized ) {
		return orders_error( 'fandoogh_order_invalid_amount', sprintf( __( 'مقدار %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, absint( wc_get_price_decimals() ) ) ) : 2;
	$rounder  = __NAMESPACE__ . '\\bulk_price_decimal_round';
	if ( function_exists( $rounder ) ) {
		$normalized = call_user_func( $rounder, $normalized, $decimals );
	}

	return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $normalized, $decimals ) : (string) $normalized;
}

/**
 * Parse a positive integer while rejecting floats, booleans and scientific
 * notation that absint() would otherwise silently coerce.
 *
 * @param mixed  $value Candidate integer.
 * @param string $field Public field name.
 * @param int    $min Minimum value.
 * @param int    $max Maximum value.
 * @return int|\WP_Error
 */
function orders_create_integer( $value, $field, $min = 1, $max = 999999999 ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) || ! preg_match( '/^[1-9][0-9]{0,11}$/D', (string) $value ) ) {
		return orders_error( 'fandoogh_order_invalid_integer', sprintf( __( 'مقدار %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$integer = (int) $value;
	if ( $integer < $min || $integer > $max ) {
		return orders_error( 'fandoogh_order_integer_out_of_range', sprintf( __( 'مقدار %s خارج از محدودهٔ مجاز است.', 'fandoogh-manager' ), $field ), 422 );
	}

	return $integer;
}

/**
 * Parse a strict boolean used by the create-order contract.
 *
 * @param mixed  $value Candidate boolean.
 * @param string $field Public field name.
 * @return bool|\WP_Error
 */
function orders_create_boolean( $value, $field ) {
	if ( true === $value || false === $value || 1 === $value || 0 === $value ) {
		return (bool) $value;
	}
	if ( is_string( $value ) && in_array( strtolower( $value ), array( 'true', 'false', '1', '0' ), true ) ) {
		return in_array( strtolower( $value ), array( 'true', '1' ), true );
	}

	return orders_error( 'fandoogh_order_invalid_boolean', sprintf( __( 'مقدار %s باید درست یا نادرست باشد.', 'fandoogh-manager' ), $field ), 422 );
}

/**
 * Validate the small address projection accepted for a manually-created
 * order. Unknown keys are rejected so arbitrary order meta cannot enter via
 * an address-shaped request.
 *
 * @param mixed  $value Candidate address.
 * @param string $field Address label.
 * @return array<string, string>|\WP_Error
 */
function orders_create_address( $value, $field ) {
	if ( null === $value ) {
		return array();
	}
	if ( ! is_array( $value ) ) {
		return orders_error( 'fandoogh_order_invalid_address', sprintf( __( 'آدرس %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$allowed = array(
		'first_name' => true,
		'last_name'  => true,
		'company'    => true,
		'address_1'  => true,
		'address_2'  => true,
		'city'       => true,
		'state'      => true,
		'postcode'   => true,
		'country'    => true,
		'email'      => true,
		'phone'      => true,
	);
	if ( ! empty( array_diff( array_keys( $value ), array_keys( $allowed ) ) ) ) {
		return orders_error( 'fandoogh_order_invalid_address', sprintf( __( 'یکی از فیلدهای آدرس %s مجاز نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$address = array();
	foreach ( $value as $key => $raw_value ) {
		if ( ! is_string( $key ) || ! is_scalar( $raw_value ) || is_bool( $raw_value ) ) {
			return orders_error( 'fandoogh_order_invalid_address', sprintf( __( 'یکی از فیلدهای آدرس %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
		}

		if ( 'email' === $key ) {
			$email = orders_clean_email( $raw_value );
			if ( '' !== trim( (string) $raw_value ) && ( '' === $email || ! is_email( $email ) ) ) {
				return orders_error( 'fandoogh_order_invalid_email', __( 'ایمیل مشتری معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$address[ $key ] = $email;
			continue;
		}

		if ( 'country' === $key ) {
			$country = strtoupper( sanitize_key( (string) $raw_value ) );
			if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/D', $country ) ) {
				return orders_error( 'fandoogh_order_invalid_country', __( 'کد کشور آدرس باید دو حرفی باشد.', 'fandoogh-manager' ), 422 );
			}
			$address[ $key ] = $country;
			continue;
		}

		$address[ $key ] = orders_clean_text( $raw_value, 255 );
	}

	return $address;
}

/**
 * Parse and validate the manual-order request. The request is intentionally
 * explicit: it accepts WooCommerce CRUD inputs needed for an admin-created
 * order, but no arbitrary meta, fee, tax, or payment-token fields.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string, mixed>|\WP_Error
 */
function orders_create_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return orders_error( 'fandoogh_json_required', __( 'بدنهٔ ثبت سفارش باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return orders_error( 'fandoogh_order_invalid_body', __( 'بدنهٔ ثبت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	$allowed = array(
		'customer_id'       => true,
		'customer_type'     => true,
		'line_items'        => true,
		'billing'           => true,
		'shipping'          => true,
		'coupon_codes'      => true,
		'shipping_lines'    => true,
		'payment_method'    => true,
		'status'            => true,
		'payment_complete'  => true,
		'idempotency_key'   => true,
	);
	if ( ! empty( array_diff( array_keys( $body ), array_keys( $allowed ) ) ) ) {
		return orders_error( 'fandoogh_order_unknown_field', __( 'یکی از فیلدهای ثبت سفارش در قرارداد مجاز نیست.', 'fandoogh-manager' ), 422 );
	}

	if ( array_key_exists( 'idempotency_key', $body ) && ( ! is_scalar( $body['idempotency_key'] ) || is_bool( $body['idempotency_key'] ) ) ) {
		return orders_error( 'fandoogh_order_idempotency_invalid', __( 'شناسهٔ یکتای ثبت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$body_idempotency_key   = isset( $body['idempotency_key'] ) ? trim( (string) $body['idempotency_key'] ) : '';
	$header_idempotency_key = trim( (string) $request->get_header( 'idempotency-key' ) );
	if ( '' !== $body_idempotency_key && '' !== $header_idempotency_key && ! hash_equals( $body_idempotency_key, $header_idempotency_key ) ) {
		return orders_error( 'fandoogh_order_idempotency_conflict', __( 'شناسهٔ یکتای بدنه و header یکسان نیستند.', 'fandoogh-manager' ), 409 );
	}
	$idempotency_key = '' !== $body_idempotency_key ? $body_idempotency_key : $header_idempotency_key;
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,79}$/D', $idempotency_key ) ) {
		return orders_error( 'fandoogh_order_idempotency_required', __( 'شناسهٔ یکتای ثبت سفارش الزامی است.', 'fandoogh-manager' ), 422 );
	}

	if ( ! isset( $body['line_items'] ) || ! is_array( $body['line_items'] ) || count( $body['line_items'] ) < 1 || count( $body['line_items'] ) > 100 ) {
		return orders_error( 'fandoogh_order_invalid_items', __( 'سفارش باید حداقل یک قلم و حداکثر صد قلم داشته باشد.', 'fandoogh-manager' ), 422 );
	}

	$line_items = array();
	foreach ( $body['line_items'] as $raw_item ) {
		if ( ! is_array( $raw_item ) ) {
			return orders_error( 'fandoogh_order_invalid_items', __( 'یکی از اقلام سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$item_allowed = array( 'product_id' => true, 'variation_id' => true, 'quantity' => true, 'unit_price' => true );
		if ( ! empty( array_diff( array_keys( $raw_item ), array_keys( $item_allowed ) ) ) ) {
			return orders_error( 'fandoogh_order_invalid_items', __( 'یکی از فیلدهای قلم سفارش مجاز نیست.', 'fandoogh-manager' ), 422 );
		}

		$product_id   = 0;
		$variation_id = 0;
		if ( isset( $raw_item['product_id'] ) ) {
			$product_id = orders_create_integer( $raw_item['product_id'], __( 'محصول', 'fandoogh-manager' ), 1, 999999999 );
			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}
		}
		if ( isset( $raw_item['variation_id'] ) ) {
			$variation_id = orders_create_integer( $raw_item['variation_id'], __( 'تنوع محصول', 'fandoogh-manager' ), 1, 999999999 );
			if ( is_wp_error( $variation_id ) ) {
				return $variation_id;
			}
		}
		if ( $product_id < 1 && $variation_id < 1 ) {
			return orders_error( 'fandoogh_order_invalid_items', __( 'شناسهٔ محصول یا تنوع محصول الزامی است.', 'fandoogh-manager' ), 422 );
		}

		$quantity = isset( $raw_item['quantity'] ) ? orders_create_integer( $raw_item['quantity'], __( 'تعداد', 'fandoogh-manager' ), 1, 999 ) : 0;
		if ( is_wp_error( $quantity ) ) {
			return $quantity;
		}

		$item = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
			'unit_price'   => null,
		);
		if ( array_key_exists( 'unit_price', $raw_item ) ) {
			$item['unit_price'] = orders_create_decimal( $raw_item['unit_price'], __( 'قیمت واحد', 'fandoogh-manager' ) );
			if ( is_wp_error( $item['unit_price'] ) ) {
				return $item['unit_price'];
			}
		}
		$line_items[] = $item;
	}

	$customer_id = 0;
	if ( isset( $body['customer_id'] ) && '' !== (string) $body['customer_id'] ) {
		$customer_id = orders_create_integer( $body['customer_id'], __( 'مشتری', 'fandoogh-manager' ), 1, 999999999 );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}
	}

	if ( array_key_exists( 'customer_type', $body ) && ( ! is_scalar( $body['customer_type'] ) || '' === trim( (string) $body['customer_type'] ) ) ) {
		return orders_error( 'fandoogh_order_invalid_customer', __( 'نوع مشتری معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$customer_type = isset( $body['customer_type'] ) ? sanitize_key( (string) $body['customer_type'] ) : '';
	if ( '' === $customer_type ) {
		$customer_type = $customer_id > 0 ? 'registered' : 'guest';
	}
	if ( ! in_array( $customer_type, array( 'registered', 'guest', 'new' ), true ) ) {
		return orders_error( 'fandoogh_order_invalid_customer', __( 'نوع مشتری معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	if ( ( 'registered' === $customer_type && $customer_id < 1 ) || ( in_array( $customer_type, array( 'guest', 'new' ), true ) && $customer_id > 0 ) ) {
		return orders_error( 'fandoogh_order_invalid_customer', __( 'شناسهٔ مشتری با نوع مشتری سازگار نیست.', 'fandoogh-manager' ), 422 );
	}

	$billing = orders_create_address( isset( $body['billing'] ) ? $body['billing'] : null, __( 'صورتحساب', 'fandoogh-manager' ) );
	if ( is_wp_error( $billing ) ) {
		return $billing;
	}
	$shipping = orders_create_address( isset( $body['shipping'] ) ? $body['shipping'] : null, __( 'ارسال', 'fandoogh-manager' ) );
	if ( is_wp_error( $shipping ) ) {
		return $shipping;
	}
	if ( 'new' === $customer_type && ( empty( $billing['email'] ) || ! is_email( $billing['email'] ) ) ) {
		return orders_error( 'fandoogh_order_customer_email_required', __( 'برای ساخت مشتری جدید، ایمیل معتبر الزامی است.', 'fandoogh-manager' ), 422 );
	}

	$coupons = array();
	if ( array_key_exists( 'coupon_codes', $body ) ) {
		if ( ! is_array( $body['coupon_codes'] ) || count( $body['coupon_codes'] ) > 20 ) {
			return orders_error( 'fandoogh_order_invalid_coupons', __( 'فهرست کوپن‌های سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		foreach ( $body['coupon_codes'] as $coupon_code ) {
			if ( ! is_scalar( $coupon_code ) || is_bool( $coupon_code ) ) {
				return orders_error( 'fandoogh_order_invalid_coupons', __( 'کد کوپن سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$coupon_code = trim( sanitize_text_field( (string) $coupon_code ) );
			if ( '' === $coupon_code || strlen( $coupon_code ) > 80 || preg_match( '/[\r\n\t\0]/', $coupon_code ) ) {
				return orders_error( 'fandoogh_order_invalid_coupons', __( 'کد کوپن سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$coupons[ strtolower( $coupon_code ) ] = $coupon_code;
		}
		$coupons = array_values( $coupons );
	}

	$shipping_lines = array();
	if ( array_key_exists( 'shipping_lines', $body ) ) {
		if ( ! is_array( $body['shipping_lines'] ) || count( $body['shipping_lines'] ) > 20 ) {
			return orders_error( 'fandoogh_order_invalid_shipping', __( 'فهرست روش‌های ارسال سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		foreach ( $body['shipping_lines'] as $raw_shipping ) {
			if ( ! is_array( $raw_shipping ) ) {
				return orders_error( 'fandoogh_order_invalid_shipping', __( 'یکی از روش‌های ارسال سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$shipping_allowed = array( 'method_id' => true, 'method_title' => true, 'instance_id' => true, 'total' => true, 'total_tax' => true );
			if ( ! empty( array_diff( array_keys( $raw_shipping ), array_keys( $shipping_allowed ) ) ) || ! isset( $raw_shipping['method_id'] ) ) {
				return orders_error( 'fandoogh_order_invalid_shipping', __( 'فیلدهای روش ارسال سفارش معتبر نیستند.', 'fandoogh-manager' ), 422 );
			}
			$method_id = is_scalar( $raw_shipping['method_id'] ) ? strtolower( trim( sanitize_key( (string) $raw_shipping['method_id'] ) ) ) : '';
			if ( '' === $method_id || ! preg_match( '/^[a-z0-9][a-z0-9_:\-]{0,79}$/D', $method_id ) ) {
				return orders_error( 'fandoogh_order_invalid_shipping', __( 'شناسهٔ روش ارسال معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			if ( array_key_exists( 'method_title', $raw_shipping ) && ( ! is_scalar( $raw_shipping['method_title'] ) || is_bool( $raw_shipping['method_title'] ) ) ) {
				return orders_error( 'fandoogh_order_invalid_shipping', __( 'عنوان روش ارسال سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$method_title = isset( $raw_shipping['method_title'] ) ? orders_clean_text( $raw_shipping['method_title'], 255 ) : $method_id;
			$total = array_key_exists( 'total', $raw_shipping ) ? orders_create_decimal( $raw_shipping['total'], __( 'هزینهٔ ارسال', 'fandoogh-manager' ) ) : '0';
			if ( is_wp_error( $total ) ) {
				return $total;
			}
			$total_tax = array_key_exists( 'total_tax', $raw_shipping ) ? orders_create_decimal( $raw_shipping['total_tax'], __( 'مالیات ارسال', 'fandoogh-manager' ) ) : '0';
			if ( is_wp_error( $total_tax ) ) {
				return $total_tax;
			}
			$shipping_lines[] = array(
				'method_id'    => $method_id,
				'method_title' => $method_title,
				'instance_id'  => isset( $raw_shipping['instance_id'] ) ? orders_create_integer( $raw_shipping['instance_id'], __( 'شناسهٔ نمونهٔ ارسال', 'fandoogh-manager' ), 1, 999999999 ) : 0,
				'total'        => $total,
				'total_tax'    => $total_tax,
			);
			if ( is_wp_error( $shipping_lines[ count( $shipping_lines ) - 1 ]['instance_id'] ) ) {
				return $shipping_lines[ count( $shipping_lines ) - 1 ]['instance_id'];
			}
		}
	}

	$payment_method = '';
	if ( array_key_exists( 'payment_method', $body ) ) {
		if ( ! is_scalar( $body['payment_method'] ) || is_bool( $body['payment_method'] ) || ( '' !== trim( (string) $body['payment_method'] ) && ! preg_match( '/^[a-z0-9][a-z0-9_:\-]{0,79}$/Di', trim( (string) $body['payment_method'] ) ) ) ) {
			return orders_error( 'fandoogh_order_invalid_payment', __( 'روش پرداخت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$payment_method = strtolower( trim( sanitize_key( (string) $body['payment_method'] ) ) );
	}

	if ( array_key_exists( 'status', $body ) && ( ! is_scalar( $body['status'] ) || is_bool( $body['status'] ) || '' === trim( (string) $body['status'] ) ) ) {
		return orders_error( 'fandoogh_invalid_status', __( 'وضعیت اولیهٔ سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$status = isset( $body['status'] ) ? orders_normalize_status( $body['status'] ) : 'wc-pending';
	$allowed_statuses = orders_allowed_statuses();
	if ( ! isset( $allowed_statuses[ $status ] ) || in_array( $status, array( 'wc-trash', 'wc-auto-draft', 'wc-refunded' ), true ) ) {
		return orders_error( 'fandoogh_invalid_status', __( 'وضعیت اولیهٔ سفارش در allowlist WooCommerce نیست.', 'fandoogh-manager' ), 422 );
	}

	$payment_complete = false;
	if ( array_key_exists( 'payment_complete', $body ) ) {
		$payment_complete = orders_create_boolean( $body['payment_complete'], __( 'پرداخت کامل', 'fandoogh-manager' ) );
		if ( is_wp_error( $payment_complete ) ) {
			return $payment_complete;
		}
	}
	if ( $payment_complete && 'wc-pending' !== $status ) {
		return orders_error( 'fandoogh_order_payment_status_conflict', __( 'پرداخت کامل فقط برای سفارش در وضعیت در انتظار پرداخت مجاز است.', 'fandoogh-manager' ), 422 );
	}

	return array(
		'customer_id'      => $customer_id,
		'customer_type'    => $customer_type,
		'line_items'       => $line_items,
		'billing'          => $billing,
		'shipping'         => $shipping,
		'coupon_codes'     => $coupons,
		'shipping_lines'   => $shipping_lines,
		'payment_method'   => $payment_method,
		'status'           => $status,
		'payment_complete' => (bool) $payment_complete,
		'idempotency_key'  => $idempotency_key,
	);
}

/**
 * Recursively normalize associative arrays before hashing an idempotency
 * request. PII is never stored; only the resulting SHA-256 fingerprint is.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function orders_create_fingerprint_value( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$keys   = array_keys( $value );
	$is_list = empty( $keys ) || $keys === range( 0, count( $keys ) - 1 );
	foreach ( $value as $key => $child ) {
		$value[ $key ] = orders_create_fingerprint_value( $child );
	}
	if ( ! $is_list ) {
		ksort( $value );
	}
	return $value;
}

/**
 * @param array<string, mixed> $values Validated order values.
 * @return string
 */
function orders_create_fingerprint( $values ) {
	$fingerprint_values = orders_create_fingerprint_value( $values );
	$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $fingerprint_values ) : json_encode( $fingerprint_values );
	return hash( 'sha256', is_string( $json ) ? $json : serialize( $fingerprint_values ) );
}

/**
 * @param array<string, mixed> $session Session context.
 * @param string              $idempotency_key Client key.
 * @return string
 */
function orders_create_idempotency_option_key( $session, $idempotency_key ) {
	$user_id = isset( $session['user']->ID ) ? absint( $session['user']->ID ) : 0;
	return 'fandoogh_order_create_' . substr( hash( 'sha256', $user_id . '|orders.create|' . $idempotency_key ), 0, 32 );
}

/**
 * Claim a manual-order idempotency key using add_option's unique-key
 * semantics. Completed requests can be replayed safely; a mismatched body or
 * an active request is rejected instead of creating a duplicate order.
 *
 * @param array<string, mixed> $session Session context.
 * @param array<string, mixed> $values Validated order values.
 * @return array<string, mixed>|\WP_Error
 */
function orders_create_idempotency_claim( $session, $values ) {
	$key         = orders_create_idempotency_option_key( $session, $values['idempotency_key'] );
	$fingerprint = orders_create_fingerprint( $values );
	$existing    = get_option( $key, false );
	$now         = time();

	if ( is_array( $existing ) ) {
		if ( isset( $existing['fingerprint'] ) && ! hash_equals( (string) $existing['fingerprint'], $fingerprint ) ) {
			return orders_error( 'fandoogh_order_idempotency_conflict', __( 'این شناسهٔ ثبت سفارش قبلاً با اطلاعات متفاوت استفاده شده است.', 'fandoogh-manager' ), 409 );
		}
		if ( 'completed' === ( isset( $existing['state'] ) ? $existing['state'] : '' ) && absint( isset( $existing['order_id'] ) ? $existing['order_id'] : 0 ) > 0 ) {
			try {
				$previous_order = compose_order_repository()->findById( absint( $existing['order_id'] ) );
			} catch ( \Throwable $exception ) {
				$previous_order = false;
			}
			if ( orders_is_readable_order( $previous_order ) ) {
				return array(
					'key'           => $key,
					'fingerprint'   => $fingerprint,
					'replay_order'  => $previous_order,
					'idempotent_replay' => true,
				);
			}
			delete_option( $key );
		} elseif ( 'processing' === ( isset( $existing['state'] ) ? $existing['state'] : '' ) && $now - absint( isset( $existing['created_at'] ) ? $existing['created_at'] : $now ) < 900 ) {
			return orders_error( 'fandoogh_order_creation_in_progress', __( 'ثبت همین سفارش هنوز در حال انجام است؛ چند لحظه بعد دوباره بررسی کنید.', 'fandoogh-manager' ), 409 );
		} else {
			delete_option( $key );
		}
	}

	$claim = array(
		'state'       => 'processing',
		'fingerprint' => $fingerprint,
		'created_at'  => $now,
	);
	if ( ! add_option( $key, $claim, '', 'no' ) ) {
		return orders_error( 'fandoogh_order_creation_in_progress', __( 'ثبت همین سفارش هم‌زمان در حال انجام است؛ دوباره تلاش کنید.', 'fandoogh-manager' ), 409 );
	}

	return array(
		'key'              => $key,
		'fingerprint'      => $fingerprint,
		'replay_order'     => false,
		'idempotent_replay'=> false,
	);
}

/**
 * @param array<string, mixed> $claim Idempotency claim.
 * @param int                 $order_id Created order ID.
 * @return void
 */
function orders_create_idempotency_complete( $claim, $order_id ) {
	if ( ! is_array( $claim ) || empty( $claim['key'] ) ) {
		return;
	}
	update_option(
		$claim['key'],
		array(
			'state'       => 'completed',
			'fingerprint' => (string) $claim['fingerprint'],
			'order_id'    => absint( $order_id ),
			'completed_at'=> time(),
		),
		false
	);
}

/**
 * Release only an in-flight claim owned by this request. A completed claim is
 * never deleted by a later error path.
 *
 * @param array<string, mixed> $claim Idempotency claim.
 * @return void
 */
function orders_create_idempotency_release( $claim ) {
	if ( ! is_array( $claim ) || empty( $claim['key'] ) ) {
		return;
	}
	$current = get_option( $claim['key'], false );
	if ( is_array( $current ) && 'processing' === ( isset( $current['state'] ) ? $current['state'] : '' ) && isset( $current['fingerprint'] ) && hash_equals( (string) $current['fingerprint'], (string) $claim['fingerprint'] ) ) {
		delete_option( $claim['key'] );
	}
}

/**
 * Read a refund idempotency key from the JSON body or the standard header.
 * Refunds are destructive, so each intended operation must have one stable
 * key. Both locations are accepted for ordinary HTTP clients.
 *
 * @param \WP_REST_Request $request REST request.
 * @param mixed            $body Parsed JSON body, when already available.
 * @return string|\WP_Error
 */
function orders_refund_idempotency_key( $request, $body = null ) {
	if ( null === $body ) {
		$body = $request->get_json_params();
	}

	$body_key = '';
	if ( is_array( $body ) && array_key_exists( 'idempotency_key', $body ) ) {
		if ( ! is_scalar( $body['idempotency_key'] ) || is_bool( $body['idempotency_key'] ) ) {
			return orders_error( 'fandoogh_refund_idempotency_invalid', __( 'شناسهٔ idempotency بازپرداخت معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$body_key = trim( (string) $body['idempotency_key'] );
	}

	$header_key = trim( (string) $request->get_header( 'idempotency-key' ) );
	if ( '' !== $body_key && '' !== $header_key && ! hash_equals( $body_key, $header_key ) ) {
		return orders_error( 'fandoogh_refund_idempotency_conflict', __( 'شناسهٔ idempotency در بدنه و سربرگ یکسان نیست.', 'fandoogh-manager' ), 409 );
	}

	$key = '' !== $body_key ? $body_key : $header_key;
	if ( '' === $key ) {
		return orders_error( 'fandoogh_refund_idempotency_required', __( 'برای بازپرداخت، ارسال یک شناسهٔ idempotency الزامی است.', 'fandoogh-manager' ), 422 );
	}
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,79}$/D', $key ) ) {
		return orders_error( 'fandoogh_refund_idempotency_invalid', __( 'شناسهٔ idempotency بازپرداخت باید بین ۸ تا ۸۰ نویسه و فقط شامل حروف، عدد، نقطه، خط تیره یا زیرخط باشد.', 'fandoogh-manager' ), 422 );
	}

	return $key;
}

/**
 * Hash the refund request without retaining its potentially sensitive body.
 *
 * @param \WP_REST_Request $request REST request.
 * @param string           $idempotency_key Validated key.
 * @return string
 */
function orders_refund_request_fingerprint( $request, $idempotency_key ) {
	$body = $request->get_json_params();
	$body = is_array( $body ) ? $body : array();
	unset( $body['idempotency_key'] );
	$body['_idempotency_key'] = (string) $idempotency_key;

	return orders_create_fingerprint( $body );
}

/**
 * @param array<string, mixed> $session Session context.
 * @param int                 $order_id Parent order ID.
 * @param string              $idempotency_key Validated key.
 * @return string
 */
function orders_refund_idempotency_option_key( $session, $order_id, $idempotency_key ) {
	$user_id = isset( $session['user']->ID ) ? absint( $session['user']->ID ) : 0;
	return 'fandoogh_refund_' . substr( hash( 'sha256', $user_id . '|orders.refund|' . absint( $order_id ) . '|' . $idempotency_key ), 0, 32 );
}

/**
 * Inspect a refund claim before validation/mutation. A completed claim is
 * replayed, while a same key with a different body is rejected.
 *
 * @param array<string, mixed> $session Session context.
 * @param int                 $order_id Parent order ID.
 * @param string              $idempotency_key Validated key.
 * @param string              $fingerprint Request fingerprint.
 * @return array<string, mixed>|\WP_Error
 */
function orders_refund_idempotency_lookup( $session, $order_id, $idempotency_key, $fingerprint ) {
	$key      = orders_refund_idempotency_option_key( $session, $order_id, $idempotency_key );
	$existing = get_option( $key, false );
	$base     = array(
		'key'               => $key,
		'fingerprint'       => $fingerprint,
		'replay_refund'     => false,
		'idempotent_replay' => false,
	);

	if ( ! is_array( $existing ) ) {
		return $base;
	}

	$stored_fingerprint = isset( $existing['fingerprint'] ) && is_scalar( $existing['fingerprint'] ) ? (string) $existing['fingerprint'] : '';
	if ( '' === $stored_fingerprint || ! hash_equals( $stored_fingerprint, $fingerprint ) ) {
		return orders_error( 'fandoogh_refund_idempotency_conflict', __( 'این شناسهٔ بازپرداخت قبلاً با اطلاعات متفاوت استفاده شده است.', 'fandoogh-manager' ), 409 );
	}

	$state = isset( $existing['state'] ) ? (string) $existing['state'] : '';
	if ( 'completed' === $state ) {
		$refund_id = absint( isset( $existing['refund_id'] ) ? $existing['refund_id'] : 0 );
		try {
			$previous_refund = compose_order_repository()->findById( $refund_id );
		} catch ( \Throwable $exception ) {
			$previous_refund = false;
		}
		$parent_id = $previous_refund && method_exists( $previous_refund, 'get_parent_id' ) ? absint( $previous_refund->get_parent_id() ) : 0;
		if ( $previous_refund && method_exists( $previous_refund, 'get_id' ) && $refund_id > 0 && $parent_id === absint( $order_id ) ) {
			$base['replay_refund']     = $previous_refund;
			$base['idempotent_replay'] = true;
			return $base;
		}

		return orders_error( 'fandoogh_refund_idempotency_unavailable', __( 'نتیجهٔ بازپرداخت قبلی قابل بازیابی نیست؛ از ایجاد بازپرداخت دوم خودداری شد.', 'fandoogh-manager' ), 409 );
	}

	if ( 'processing' === $state && time() - absint( isset( $existing['created_at'] ) ? $existing['created_at'] : time() ) < 900 ) {
		return orders_error( 'fandoogh_refund_in_progress', __( 'همین بازپرداخت هم‌زمان در حال انجام است؛ چند لحظه بعد دوباره بررسی کنید.', 'fandoogh-manager' ), 409 );
	}

	delete_option( $key );
	return $base;
}

/**
 * Claim a refund idempotency key atomically with add_option().
 *
 * @param array<string, mixed> $session Session context.
 * @param int                 $order_id Parent order ID.
 * @param string              $idempotency_key Validated key.
 * @param string              $fingerprint Request fingerprint.
 * @return array<string, mixed>|\WP_Error
 */
function orders_refund_idempotency_claim( $session, $order_id, $idempotency_key, $fingerprint ) {
	$existing = orders_refund_idempotency_lookup( $session, $order_id, $idempotency_key, $fingerprint );
	if ( is_wp_error( $existing ) || ! empty( $existing['idempotent_replay'] ) ) {
		return $existing;
	}

	$claim = array(
		'state'       => 'processing',
		'fingerprint' => $fingerprint,
		'created_at'  => time(),
	);
	if ( ! add_option( $existing['key'], $claim, '', 'no' ) ) {
		$race = orders_refund_idempotency_lookup( $session, $order_id, $idempotency_key, $fingerprint );
		if ( is_wp_error( $race ) || ! empty( $race['idempotent_replay'] ) ) {
			return $race;
		}
		return orders_error( 'fandoogh_refund_in_progress', __( 'همین بازپرداخت هم‌زمان در حال انجام است؛ دوباره تلاش کنید.', 'fandoogh-manager' ), 409 );
	}

	return array(
		'key'               => $existing['key'],
		'fingerprint'       => $fingerprint,
		'replay_refund'     => false,
		'idempotent_replay' => false,
	);
}

/**
 * @param array<string, mixed> $claim Idempotency claim.
 * @param int                 $refund_id Created refund ID.
 * @return void
 */
function orders_refund_idempotency_complete( $claim, $refund_id ) {
	if ( ! is_array( $claim ) || empty( $claim['key'] ) || absint( $refund_id ) < 1 ) {
		return;
	}

	update_option(
		$claim['key'],
		array(
			'state'        => 'completed',
			'fingerprint'  => (string) $claim['fingerprint'],
			'refund_id'    => absint( $refund_id ),
			'completed_at' => time(),
		),
		false
	);
}

/**
 * Release only an in-flight claim owned by this request.
 *
 * @param array<string, mixed> $claim Idempotency claim.
 * @return void
 */
function orders_refund_idempotency_release( $claim ) {
	if ( ! is_array( $claim ) || empty( $claim['key'] ) ) {
		return;
	}

	$current = get_option( $claim['key'], false );
	if ( is_array( $current ) && 'processing' === ( isset( $current['state'] ) ? $current['state'] : '' ) && isset( $current['fingerprint'] ) && hash_equals( (string) $current['fingerprint'], (string) $claim['fingerprint'] ) ) {
		delete_option( $claim['key'] );
	}
}

/**
 * Resolve and validate a customer selected for a manual order. New customers
 * are created only through WooCommerce's official helper and receive a
 * generated password; no credential is accepted from the PWA.
 *
 * @param array<string, mixed> $values Validated order values.
 * @return array<string, mixed>|\WP_Error
 */
function orders_create_customer( $values ) {
	$type = $values['customer_type'];
	if ( 'guest' === $type ) {
		return array( 'id' => 0, 'created' => false );
	}

	if ( 'registered' === $type ) {
		if ( ! class_exists( '\\WC_Customer' ) ) {
			return orders_error( 'fandoogh_customer_unavailable', __( 'API مشتری WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
		}
		$customer = compose_customer_repository()->findById( absint( $values['customer_id'] ) );
		if ( ! is_object( $customer ) || ! method_exists( $customer, 'get_id' ) || absint( $customer->get_id() ) !== absint( $values['customer_id'] ) ) {
			return orders_error( 'fandoogh_customer_not_found', __( 'مشتری انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ), 404 );
		}
		return array( 'id' => absint( $values['customer_id'] ), 'created' => false );
	}

	if ( ! function_exists( 'wc_create_new_customer' ) || ! function_exists( 'wp_generate_password' ) ) {
		return orders_error( 'fandoogh_customer_creation_unavailable', __( 'ساخت مشتری جدید در WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$billing = $values['billing'];
	$args = array(
		'first_name' => isset( $billing['first_name'] ) ? $billing['first_name'] : '',
		'last_name'  => isset( $billing['last_name'] ) ? $billing['last_name'] : '',
		'phone'      => isset( $billing['phone'] ) ? $billing['phone'] : '',
	);
	try {
		$customer_id = wc_create_new_customer( $billing['email'], '', wp_generate_password( 32, true, true ), $args );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_customer_creation_failed', __( 'ساخت مشتری جدید انجام نشد.', 'fandoogh-manager' ), 422 );
	}
	if ( is_wp_error( $customer_id ) ) {
		return orders_private_error( $customer_id );
	}
	$customer_id = absint( $customer_id );
	if ( $customer_id < 1 ) {
		return orders_error( 'fandoogh_customer_creation_failed', __( 'شناسهٔ مشتری جدید معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	return array( 'id' => $customer_id, 'created' => true );
}

/**
 * @param array<string, mixed> $line Validated line.
 * @return object|\WP_Error
 */
function orders_create_product( $line ) {
	$repository = compose_product_repository();
	if ( ! function_exists( 'wc_get_product' ) ) {
		return orders_error( 'fandoogh_product_unavailable', __( 'API محصول WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$product_id   = absint( $line['product_id'] );
	$variation_id = absint( $line['variation_id'] );
	$lookup_id    = $variation_id > 0 ? $variation_id : $product_id;
	try {
		$product = $repository->findById( $lookup_id );
	} catch ( \Throwable $exception ) {
		$product = false;
	}
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || absint( $product->get_id() ) !== $lookup_id ) {
		return orders_error( 'fandoogh_product_not_found', __( 'یکی از محصولات سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	$status = method_exists( $product, 'get_status' ) ? sanitize_key( (string) $product->get_status() ) : '';
	if ( in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return orders_error( 'fandoogh_product_unavailable', __( 'یکی از محصولات سفارش قابل فروش نیست.', 'fandoogh-manager' ), 422 );
	}

	if ( $variation_id > 0 ) {
		$parent_id = method_exists( $product, 'get_parent_id' ) ? absint( $product->get_parent_id() ) : 0;
		if ( $parent_id < 1 || ( $product_id > 0 && $parent_id !== $product_id ) ) {
			return orders_error( 'fandoogh_invalid_variation', __( 'تنوع محصول با محصول اصلی سازگار نیست.', 'fandoogh-manager' ), 422 );
		}
	} elseif ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
		return orders_error( 'fandoogh_invalid_variation', __( 'برای تنوع محصول، شناسهٔ variation را ارسال کنید.', 'fandoogh-manager' ), 422 );
	}

	if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
		return orders_error( 'fandoogh_variation_required', __( 'برای محصول متغیر باید یک تنوع انتخاب شود.', 'fandoogh-manager' ), 422 );
	}

	return $product;
}

/**
 * @param string $unit_price Unit price.
 * @param int    $quantity Quantity.
 * @return string|\WP_Error
 */
function orders_create_line_total( $unit_price, $quantity ) {
	$multiplier = __NAMESPACE__ . '\\bulk_price_decimal_multiply';
	$rounder    = __NAMESPACE__ . '\\bulk_price_decimal_round';
	$decimals   = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, absint( wc_get_price_decimals() ) ) ) : 2;
	if ( ! function_exists( $multiplier ) ) {
		return orders_error( 'fandoogh_order_amount_unavailable', __( 'محاسبهٔ دقیق مبلغ سفارش در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	$total = call_user_func( $multiplier, (string) $unit_price, (string) $quantity );
	if ( function_exists( $rounder ) ) {
		$total = call_user_func( $rounder, $total, $decimals );
	}
	if ( function_exists( 'wc_format_decimal' ) ) {
		$total = wc_format_decimal( $total, $decimals );
	}
	$compare = __NAMESPACE__ . '\\bulk_price_decimal_is_greater_than';
	if ( function_exists( $compare ) && call_user_func( $compare, (string) $total, '999999999999' ) ) {
		return orders_error( 'fandoogh_order_amount_too_large', __( 'مبلغ یکی از اقلام سفارش بیش از حد مجاز است.', 'fandoogh-manager' ), 422 );
	}
	return (string) $total;
}

/**
 * @param object                 $order WooCommerce order.
 * @param array<string, mixed>   $shipping Shipping line.
 * @return true|\WP_Error
 */
function orders_create_add_shipping_line( $order, $shipping ) {
	if ( ! class_exists( '\\WC_Order_Item_Shipping' ) || ! method_exists( $order, 'add_item' ) ) {
		return orders_error( 'fandoogh_shipping_unavailable', __( 'ثبت روش ارسال در WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	try {
		$item = compose_order_repository()->createShippingItem();
		$item->set_method_title( $shipping['method_title'] );
		$item->set_method_id( $shipping['method_id'] );
		if ( method_exists( $item, 'set_instance_id' ) && absint( $shipping['instance_id'] ) > 0 ) {
			$item->set_instance_id( absint( $shipping['instance_id'] ) );
		}
		$item->set_total( $shipping['total'] );
		if ( method_exists( $item, 'set_total_tax' ) ) {
			$item->set_total_tax( $shipping['total_tax'] );
		}
		$order->add_item( $item );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_shipping_failed', __( 'ثبت روش ارسال سفارش انجام نشد.', 'fandoogh-manager' ), 422 );
	}

	return true;
}

/**
 * Resolve a registered WooCommerce payment gateway identifier. The endpoint
 * never accepts a payment token or an arbitrary gateway label.
 *
 * @param string $method Payment gateway ID.
 * @return array<string, string>|\WP_Error
 */
function orders_create_payment_gateway( $method ) {
	if ( '' === $method ) {
		return array( 'id' => '', 'title' => '' );
	}
	if ( ! class_exists( '\\WC_Payment_Gateways' ) ) {
		return orders_error( 'fandoogh_payment_unavailable', __( 'روش‌های پرداخت WooCommerce در دسترس نیستند.', 'fandoogh-manager' ), 503 );
	}

	try {
		$manager  = \WC_Payment_Gateways::instance();
		$gateways = method_exists( $manager, 'payment_gateways' ) ? (array) $manager->payment_gateways() : array();
	} catch ( \Throwable $exception ) {
		$gateways = array();
	}
	if ( ! isset( $gateways[ $method ] ) || ! is_object( $gateways[ $method ] ) ) {
		return orders_error( 'fandoogh_payment_invalid', __( 'روش پرداخت انتخاب‌شده در WooCommerce ثبت نشده است.', 'fandoogh-manager' ), 422 );
	}

	$gateway = $gateways[ $method ];
	if ( isset( $gateway->enabled ) && 'yes' !== strtolower( (string) $gateway->enabled ) ) {
		return orders_error( 'fandoogh_payment_unavailable', __( 'روش پرداخت انتخاب‌شده فعال نیست.', 'fandoogh-manager' ), 422 );
	}
	$title   = method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : ( isset( $gateway->title ) ? $gateway->title : $method );
	return array(
		'id'    => sanitize_key( $method ),
		'title' => orders_clean_text( $title, 255 ),
	);
}

/**
 * Create an order through WooCommerce's public CRUD APIs. This is deliberately
 * a bounded admin order flow; arbitrary fees, taxes, meta, tokens and SQL are
 * not part of the contract.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function create_order( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! orders_create_available() ) {
		return orders_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API ثبت سفارش در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$values = orders_create_request_body( $request );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	apply_session_user_context( $session );
	$claim = orders_create_idempotency_claim( $session, $values );
	if ( is_wp_error( $claim ) ) {
		return $claim;
	}
	if ( ! empty( $claim['idempotent_replay'] ) && orders_is_readable_order( $claim['replay_order'] ) ) {
		return orders_no_store_response( array( 'data' => serialize_order( $claim['replay_order'], true ), 'idempotent_replay' => true ) );
	}
	$gateway = orders_create_payment_gateway( $values['payment_method'] );
	if ( is_wp_error( $gateway ) ) {
		orders_create_idempotency_release( $claim );
		return $gateway;
	}

	$customer = orders_create_customer( $values );
	if ( is_wp_error( $customer ) ) {
		orders_create_idempotency_release( $claim );
		return $customer;
	}

	$order            = false;
	$order_persisted  = false;
	$payment_attempted = false;
	try {
		$order = compose_order_repository()->create(
			array(
				'customer_id' => absint( $customer['id'] ),
				'created_via' => 'fandoogh-manager',
			)
		);
		if ( is_wp_error( $order ) || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			throw new \RuntimeException( 'order_create_failed' );
		}
		if ( method_exists( $order, 'set_customer_id' ) ) {
			$order->set_customer_id( absint( $customer['id'] ) );
		}
		if ( ! empty( $values['billing'] ) && method_exists( $order, 'set_address' ) ) {
			$order->set_address( $values['billing'], 'billing' );
		}
		if ( ! empty( $values['shipping'] ) && method_exists( $order, 'set_address' ) ) {
			$order->set_address( $values['shipping'], 'shipping' );
		}
		if ( '' !== $gateway['id'] && method_exists( $order, 'set_payment_method' ) ) {
			$order->set_payment_method( $gateway['id'] );
			if ( method_exists( $order, 'set_payment_method_title' ) ) {
				$order->set_payment_method_title( $gateway['title'] );
			}
		}
		if ( method_exists( $order, 'set_status' ) ) {
			$order->set_status( preg_replace( '/^wc-/', '', $values['status'] ) );
		}

		foreach ( $values['line_items'] as $line ) {
			$product = orders_create_product( $line );
			if ( is_wp_error( $product ) ) {
				throw new \RuntimeException( $product->get_error_code() );
			}
			$args = array();
			if ( null !== $line['unit_price'] ) {
				$total = orders_create_line_total( $line['unit_price'], $line['quantity'] );
				if ( is_wp_error( $total ) ) {
					throw new \RuntimeException( $total->get_error_code() );
				}
				$args['subtotal'] = $total;
				$args['total']    = $total;
			}
			$item_id = $order->add_product( $product, $line['quantity'], $args );
			if ( ! $item_id ) {
				throw new \RuntimeException( 'order_item_failed' );
			}
		}

		foreach ( $values['shipping_lines'] as $shipping ) {
			$shipping_result = orders_create_add_shipping_line( $order, $shipping );
			if ( is_wp_error( $shipping_result ) ) {
				throw new \RuntimeException( $shipping_result->get_error_code() );
			}
		}

		foreach ( $values['coupon_codes'] as $coupon_code ) {
			if ( ! method_exists( $order, 'apply_coupon' ) || ! class_exists( '\\WC_Coupon' ) ) {
				throw new \RuntimeException( 'coupon_unavailable' );
			}
			$coupon = compose_order_coupon( $coupon_code );
			if ( ! method_exists( $coupon, 'get_id' ) || absint( $coupon->get_id() ) < 1 ) {
				throw new \RuntimeException( 'coupon_invalid' );
			}
			$applied = $order->apply_coupon( $coupon );
			if ( is_wp_error( $applied ) || false === $applied ) {
				throw new \RuntimeException( 'coupon_failed' );
			}
		}

		if ( method_exists( $order, 'calculate_taxes' ) ) {
			$order->calculate_taxes();
		}
		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals();
		}
		$order->save();
		$order_id       = absint( $order->get_id() );
		$order_persisted = $order_id > 0;
		if ( ! $order_persisted ) {
			throw new \RuntimeException( 'order_save_failed' );
		}

		if ( $values['payment_complete'] ) {
			$payment_attempted = true;
			if ( ! method_exists( $order, 'payment_complete' ) ) {
				throw new \RuntimeException( 'payment_complete_unavailable' );
			}
			$order->payment_complete();
		}
		$order = compose_order_repository()->findById( $order_id );
		if ( ! orders_is_readable_order( $order ) ) {
			throw new \RuntimeException( 'order_reload_failed' );
		}
	} catch ( \Throwable $exception ) {
		if ( $order_persisted && $payment_attempted ) {
			orders_create_idempotency_complete( $claim, isset( $order_id ) ? $order_id : 0 );
			return orders_error( 'fandoogh_order_payment_failed', __( 'سفارش ساخته شد اما تکمیل پرداخت انجام نشد؛ سفارش را از فهرست بررسی کنید.', 'fandoogh-manager' ), 422, array( 'order_id' => isset( $order_id ) ? $order_id : 0 ) );
		}
		if ( $order_persisted && is_object( $order ) && method_exists( $order, 'delete' ) ) {
			try {
				$order->delete( true );
			} catch ( \Throwable $delete_exception ) {
				// Keep the idempotency claim released only after best-effort rollback.
			}
		}
		if ( ! empty( $customer['created'] ) && absint( $customer['id'] ) > 0 && function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( absint( $customer['id'] ) );
		}
		orders_create_idempotency_release( $claim );
		return orders_error( 'fandoogh_order_creation_failed', __( 'ثبت سفارش انجام نشد؛ اطلاعات کالا، کوپن و روش پرداخت را بررسی کنید.', 'fandoogh-manager' ), 422 );
	}

	orders_create_idempotency_complete( $claim, $order_id );
	record_audit_event(
		'order_created',
		$session['user']->ID,
		$session['id'],
		$session['device_label'],
		'order',
		$order_id,
		array(
			'order_id'    => $order_id,
			'customer_id' => $customer['id'],
			'count'       => count( $values['line_items'] ),
			'status'      => $order->get_status(),
			'outcome'     => $values['payment_complete'] ? 'paid' : 'created',
		)
	);

	return orders_no_store_response( array( 'data' => serialize_order( $order, true ), 'idempotent_replay' => false ) );
}

/**
 * Change only the WooCommerce order status. Payment, refund, customer data,
 * and arbitrary order meta are intentionally outside this endpoint.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function update_order_status( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! orders_available() ) {
		return orders_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API سفارش‌ها در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$body = orders_status_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$status = orders_normalize_status( $body['status'] );
	$allowed = orders_allowed_statuses();
	if ( ! isset( $allowed[ $status ] ) ) {
		return orders_error( 'fandoogh_invalid_status', __( 'وضعیت سفارش در allowlist WooCommerce نیست.', 'fandoogh-manager' ), 422 );
	}

	$note = '';
	if ( isset( $body['note'] ) ) {
		if ( ! is_scalar( $body['note'] ) ) {
			return orders_error( 'fandoogh_invalid_note', __( 'یادداشت وضعیت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$note = orders_clean_text( $body['note'], 500 );
	}

	$order_id = absint( $request->get_param( 'id' ) );
	apply_session_user_context( $session );
	try {
		$order = compose_order_repository()->findById( $order_id );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_order_read_failed', __( 'خواندن سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	$expected_status = orders_normalize_status( $body['expected_status'] );
	$current_status  = orders_normalize_status( orders_getter_value( $order, 'get_status' ) );
	if ( '' === $expected_status || ! isset( $allowed[ $expected_status ] ) ) {
		return orders_error( 'fandoogh_invalid_expected_status', __( 'وضعیت فعلی سفارش در allowlist WooCommerce نیست.', 'fandoogh-manager' ), 422 );
	}
	if ( $current_status !== $expected_status ) {
		return orders_error( 'fandoogh_order_conflict', __( 'وضعیت سفارش از زمان بارگذاری تغییر کرده است؛ جزئیات را تازه‌سازی کنید و دوباره تلاش کنید.', 'fandoogh-manager' ), 409 );
	}

	try {
		$order->update_status( preg_replace( '/^wc-/', '', $status ), $note, true );
		$order = compose_order_repository()->findById( $order_id );
	} catch ( \Throwable $exception ) {
		try {
			$order = compose_order_repository()->findById( $order_id );
		} catch ( \Throwable $read_exception ) {
			$order = false;
		}
	}

	if ( ! orders_status_matches( $order, $status ) ) {
		return orders_error( 'fandoogh_order_status_failed', __( 'تغییر وضعیت سفارش انجام نشد.', 'fandoogh-manager' ), 422 );
	}

	record_audit_event( 'order_status_updated', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'from_status' => $current_status, 'to_status' => $order->get_status() ) );
	return orders_no_store_response( array( 'data' => serialize_order( $order, true ) ) );
}

/**
 * Add a bounded WooCommerce order note without accepting arbitrary comment
 * fields. `customer_note` controls visibility in the customer account; the
 * action is always audited.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function add_order_note( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! orders_available() ) {
		return orders_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API سفارش‌ها در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return orders_error( 'fandoogh_json_required', __( 'بدنهٔ یادداشت سفارش باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! empty( array_diff( array_keys( $body ), array( 'content', 'customer_note' ) ) ) ) {
		return orders_error( 'fandoogh_invalid_note', __( 'بدنهٔ یادداشت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$content = isset( $body['content'] ) && is_scalar( $body['content'] ) ? sanitize_textarea_field( (string) $body['content'] ) : '';
	if ( '' === trim( $content ) || strlen( $content ) > 1000 ) {
		return orders_error( 'fandoogh_invalid_note', __( 'متن یادداشت سفارش باید بین یک تا هزار نویسه باشد.', 'fandoogh-manager' ), 422 );
	}
	$customer_note = false;
	if ( isset( $body['customer_note'] ) ) {
		$customer_note = true === $body['customer_note'] || 1 === $body['customer_note'] || '1' === $body['customer_note'] || 'true' === $body['customer_note'];
	}
	$order_id = absint( $request->get_param( 'id' ) );
	apply_session_user_context( $session );
	$order = compose_order_repository()->findById( $order_id );
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	try {
		$note_id = $order->add_order_note( $content, $customer_note );
		$order   = compose_order_repository()->findById( $order_id );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_order_note_failed', __( 'ثبت یادداشت سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	if ( ! $note_id || ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_note_failed', __( 'یادداشت سفارش پس از ذخیره قابل بازیابی نیست.', 'fandoogh-manager' ), 500 );
	}
	record_audit_event( 'order_note_added', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'order_id' => $order_id, 'status' => $customer_note ? 'customer' : 'internal' ) );
	return orders_no_store_response( array( 'data' => serialize_order( $order, true ), 'note_id' => absint( $note_id ) ) );
}

/**
 * Parse and validate a refund request. Amounts remain decimal strings until
 * WooCommerce receives them; line-item quantities are checked against the
 * current order before the refund function is called.
 *
 * @param \WP_REST_Request $request REST request.
 * @param object           $order WooCommerce order.
 * @return array<string, mixed>|\WP_Error
 */
function order_refund_values( $request, $order ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return orders_error( 'fandoogh_json_required', __( 'بدنهٔ بازپرداخت سفارش باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}
	$body = $request->get_json_params();
	$allowed = array( 'amount', 'reason', 'restock_items', 'refund_payment', 'line_items', 'idempotency_key' );
	if ( ! is_array( $body ) || ! empty( array_diff( array_keys( $body ), $allowed ) ) ) {
		return orders_error( 'fandoogh_invalid_refund', __( 'بدنهٔ بازپرداخت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$idempotency_key = orders_refund_idempotency_key( $request, $body );
	if ( is_wp_error( $idempotency_key ) ) {
		return $idempotency_key;
	}
	$amount = '';
	if ( array_key_exists( 'amount', $body ) && '' !== (string) $body['amount'] ) {
		if ( ! is_scalar( $body['amount'] ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$/D', (string) $body['amount'] ) ) {
			return orders_error( 'fandoogh_invalid_refund_amount', __( 'مبلغ بازپرداخت معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$amount = orders_money( $body['amount'] );
	}
	$line_items = array();
	$computed_amount = 0.0;
	if ( isset( $body['line_items'] ) ) {
		if ( ! is_array( $body['line_items'] ) || count( $body['line_items'] ) > 100 ) {
			return orders_error( 'fandoogh_invalid_refund_items', __( 'اقلام بازپرداخت معتبر نیستند.', 'fandoogh-manager' ), 422 );
		}
		$order_items = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : array();
		foreach ( $body['line_items'] as $key => $raw_item ) {
			$item_id = is_array( $raw_item ) && isset( $raw_item['item_id'] ) ? absint( $raw_item['item_id'] ) : absint( $key );
			$quantity = is_array( $raw_item ) && isset( $raw_item['quantity'] ) ? absint( $raw_item['quantity'] ) : 0;
			if ( $item_id < 1 || $quantity < 1 || ! isset( $order_items[ $item_id ] ) || ! is_object( $order_items[ $item_id ] ) ) {
				return orders_error( 'fandoogh_invalid_refund_items', __( 'یکی از اقلام بازپرداخت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$current_quantity = absint( orders_getter_value( $order_items[ $item_id ], 'get_quantity' ) );
			if ( $quantity > $current_quantity ) {
				return orders_error( 'fandoogh_invalid_refund_quantity', __( 'تعداد بازپرداخت از تعداد سفارش بیشتر است.', 'fandoogh-manager' ), 422 );
			}
			$line_total = (float) orders_money( orders_getter_value( $order_items[ $item_id ], 'get_total' ) );
			$line_tax   = (float) orders_money( orders_getter_value( $order_items[ $item_id ], 'get_total_tax' ) );
			$computed_amount += $current_quantity > 0 ? ( ( $line_total + $line_tax ) / $current_quantity ) * $quantity : 0;
			$line_items[ $item_id ] = array( 'qty' => $quantity );
		}
	}
	if ( '' === $amount ) {
		if ( ! empty( $line_items ) ) {
			$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
			$amount = number_format( $computed_amount, $decimals, '.', '' );
		}
	}
	$total = (float) orders_money( orders_getter_value( $order, 'get_total' ) );
	$already_refunded = method_exists( $order, 'get_total_refunded' ) ? (float) orders_money( $order->get_total_refunded() ) : 0.0;
	if ( '' === $amount && empty( $line_items ) ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$amount = number_format( max( 0, $total - $already_refunded ), $decimals, '.', '' );
	}
	if ( (float) $amount <= 0 || (float) $amount > max( 0, $total - $already_refunded ) + 0.000001 ) {
		return orders_error( 'fandoogh_refund_amount_invalid', __( 'مبلغ بازپرداخت از مبلغ قابل بازپرداخت سفارش بیشتر است.', 'fandoogh-manager' ), 422 );
	}
	$reason = isset( $body['reason'] ) && is_scalar( $body['reason'] ) ? sanitize_text_field( (string) $body['reason'] ) : '';
	if ( strlen( $reason ) > 500 ) {
		return orders_error( 'fandoogh_invalid_refund_reason', __( 'دلیل بازپرداخت بیش از حد مجاز است.', 'fandoogh-manager' ), 422 );
	}
	return array(
		'amount'          => $amount,
		'reason'          => $reason,
		'restock_items'   => ! isset( $body['restock_items'] ) || true === $body['restock_items'] || 1 === $body['restock_items'] || '1' === $body['restock_items'] || 'true' === $body['restock_items'],
		'refund_payment'  => ! isset( $body['refund_payment'] ) || true === $body['refund_payment'] || 1 === $body['refund_payment'] || '1' === $body['refund_payment'] || 'true' === $body['refund_payment'],
		'line_items'      => $line_items,
		'idempotency_key' => $idempotency_key,
	);
}

function serialize_order_refund( $refund ) {
	return array(
		'id'      => absint( orders_getter_value( $refund, 'get_id' ) ),
		'amount'  => orders_money( orders_getter_value( $refund, 'get_amount' ) ),
		'reason'  => orders_clean_text( orders_getter_value( $refund, 'get_reason' ), 500 ),
		'date'    => orders_date( orders_getter_value( $refund, 'get_date_created' ) ),
	);
}

/**
 * Build a response for a previously completed refund. Reloading the parent
 * produces current totals without ever creating another WooCommerce refund.
 *
 * @param object $order Current parent order.
 * @param int    $order_id Parent order ID.
 * @param object $refund Previously created refund.
 * @return \WP_REST_Response
 */
function orders_refund_replay_response( $order, $order_id, $refund ) {
	try {
		$fresh_order = compose_order_repository()->findById( absint( $order_id ) );
		if ( orders_is_readable_order( $fresh_order ) ) {
			$order = $fresh_order;
		}
	} catch ( \Throwable $exception ) {
		// The already-loaded parent remains a safe response fallback.
	}

	return orders_no_store_response(
		array(
			'data'              => serialize_order( $order, true ),
			'refund'            => serialize_order_refund( $refund ),
			'idempotent_replay' => true,
		)
	);
}

function refund_order( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! orders_available() || ! compose_order_repository()->isRefundAvailable() ) {
		return orders_error( 'fandoogh_refund_unavailable', __( 'امکان بازپرداخت در نسخهٔ فعلی WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	$order_id = absint( $request->get_param( 'id' ) );
	apply_session_user_context( $session );
	$order = compose_order_repository()->findById( $order_id );
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	$raw_fingerprint = '';
	$idempotency_key = '';
	$content_type    = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false !== strpos( $content_type, 'application/json' ) ) {
		$raw_body       = $request->get_json_params();
		$idempotency_key = orders_refund_idempotency_key( $request, $raw_body );
		if ( is_wp_error( $idempotency_key ) ) {
			return $idempotency_key;
		}
		$raw_fingerprint = orders_refund_request_fingerprint( $request, $idempotency_key );
		$existing_claim  = orders_refund_idempotency_lookup( $session, $order_id, $idempotency_key, $raw_fingerprint );
		if ( is_wp_error( $existing_claim ) ) {
			return $existing_claim;
		}
		if ( ! empty( $existing_claim['idempotent_replay'] ) && ! empty( $existing_claim['replay_refund'] ) ) {
			return orders_refund_replay_response( $order, $order_id, $existing_claim['replay_refund'] );
		}
	}

	$values = order_refund_values( $request, $order );
	if ( is_wp_error( $values ) ) {
		if ( '' !== $raw_fingerprint && '' !== $idempotency_key ) {
			$late_claim = orders_refund_idempotency_lookup( $session, $order_id, $idempotency_key, $raw_fingerprint );
			if ( is_wp_error( $late_claim ) ) {
				return $late_claim;
			}
			if ( ! empty( $late_claim['idempotent_replay'] ) && ! empty( $late_claim['replay_refund'] ) ) {
				return orders_refund_replay_response( $order, $order_id, $late_claim['replay_refund'] );
			}
		}
		return $values;
	}
	if ( '' === $raw_fingerprint ) {
		$raw_fingerprint = orders_refund_request_fingerprint( $request, $values['idempotency_key'] );
	}
	$claim = orders_refund_idempotency_claim( $session, $order_id, $values['idempotency_key'], $raw_fingerprint );
	if ( is_wp_error( $claim ) ) {
		return $claim;
	}
	if ( ! empty( $claim['idempotent_replay'] ) && ! empty( $claim['replay_refund'] ) ) {
		return orders_refund_replay_response( $order, $order_id, $claim['replay_refund'] );
	}
	$args = array(
		'amount'         => $values['amount'],
		'reason'         => $values['reason'],
		'order_id'       => $order_id,
		'refund_payment' => $values['refund_payment'],
		'restock_items'  => $values['restock_items'],
	);
	if ( ! empty( $values['line_items'] ) ) {
		$args['line_items'] = $values['line_items'];
	}
	try {
		$refund = compose_order_repository()->createRefund( $args );
	} catch ( \Throwable $exception ) {
		// A gateway/storage adapter may persist before throwing; retain the
		// short in-flight claim rather than risking a duplicate refund.
		return orders_error( 'fandoogh_refund_failed', __( 'بازپرداخت سفارش انجام نشد؛ پاسخ درگاه یا تنظیمات سفارش را بررسی کنید.', 'fandoogh-manager' ), 422 );
	}
	if ( is_wp_error( $refund ) || ! is_object( $refund ) || ! method_exists( $refund, 'get_id' ) || absint( $refund->get_id() ) < 1 ) {
		orders_refund_idempotency_release( $claim );
		return is_wp_error( $refund ) ? orders_private_error( $refund ) : orders_error( 'fandoogh_refund_failed', __( 'بازپرداخت سفارش انجام نشد.', 'fandoogh-manager' ), 422 );
	}
	orders_refund_idempotency_complete( $claim, absint( $refund->get_id() ) );
	$fresh_order = compose_order_repository()->findById( $order_id );
	if ( orders_is_readable_order( $fresh_order ) ) {
		$order = $fresh_order;
	}
	record_audit_event( 'order_refunded', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'order_id' => $order_id, 'refund_id' => $refund->get_id(), 'status' => $values['refund_payment'] ? 'automatic' : 'manual' ) );
	return orders_no_store_response( array( 'data' => serialize_order( $order, true ), 'refund' => serialize_order_refund( $refund ), 'idempotent_replay' => false ) );
}

/**
 * @return bool
 */
function orders_available() {
	return compose_order_repository()->isAvailable();
}

/**
 * Creation needs the WooCommerce order factory and product CRUD in addition
 * to the read-only order APIs. Shipping and payment integrations remain
 * optional until the request explicitly asks to use one.
 *
 * @return bool
 */
function orders_create_available() {
	return compose_order_repository()->isCreateAvailable() && function_exists( 'wc_get_product' );
}

/**
 * Return the status allowlist from WooCommerce's registered order statuses.
 * The request can only select one of these keys; arbitrary status names are
 * never passed to the WooCommerce query layer.
 *
 * @return array<string, string>
 */
function orders_allowed_statuses() {
	$allowed = array();
	foreach ( compose_order_repository()->statuses() as $status => $label ) {
		$status = sanitize_key( $status );
		if ( 0 !== strpos( $status, 'wc-' ) || strlen( $status ) > 64 ) {
			continue;
		}

		$allowed[ $status ] = sanitize_text_field( (string) $label );
	}

	return $allowed;
}

/**
 * Normalize a public status value to WooCommerce's `wc-` query key.
 *
 * @param mixed $value Candidate status.
 * @return string
 */
function orders_normalize_status( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$status = sanitize_key( (string) $value );
	if ( '' === $status ) {
		return '';
	}

	return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
}

/**
 * Confirm the status after WooCommerce CRUD. Some gateways, custom order
 * status hooks, or delayed storage adapters can return/throw after the
 * status side effect has already been persisted; the final getter is the
 * source of truth and prevents a false mutation failure in that case.
 *
 * @param object $order Candidate order.
 * @param string $expected_status Requested status.
 * @return bool
 */
function orders_status_matches( $order, $expected_status ) {
	if ( ! orders_is_readable_order( $order ) ) {
		return false;
	}

	return orders_normalize_status( orders_getter_value( $order, 'get_status' ) ) === orders_normalize_status( $expected_status );
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function orders_query_integer( $value, $default, $min, $max ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return $default;
	}

	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * Normalize a date-only order filter without accepting arbitrary query text.
 *
 * @param mixed $value Candidate YYYY-MM-DD value.
 * @return string|\WP_Error
 */
function orders_date_filter( $value ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return '';
	}

	$value = trim( sanitize_text_field( (string) $value ) );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return orders_error( 'fandoogh_invalid_date_filter', __( 'تاریخ فیلتر سفارش باید با قالب YYYY-MM-DD ارسال شود.', 'fandoogh-manager' ), 422 );
	}

	$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
	$errors = \DateTimeImmutable::getLastErrors();
	if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
		return orders_error( 'fandoogh_invalid_date_filter', __( 'تاریخ فیلتر سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	return $date->format( 'Y-m-d' );
}

/**
 * Normalize a non-negative decimal order total filter.
 *
 * @param mixed $value Candidate decimal value.
 * @return string|\WP_Error
 */
function orders_total_filter( $value ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return '';
	}

	$value = trim( (string) $value );
	if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,2})?$/', $value ) ) {
		return orders_error( 'fandoogh_invalid_total_filter', __( 'فیلتر مبلغ سفارش باید یک عدد مثبت یا صفر باشد.', 'fandoogh-manager' ), 422 );
	}

	return orders_money( $value );
}

/**
 * Normalize a WooCommerce payment/shipping method identifier.
 *
 * @param mixed  $value Candidate method identifier.
 * @param string $label Human-readable field name.
 * @return string|\WP_Error
 */
function orders_method_filter( $value, $label ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return '';
	}

	$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
	if ( ! preg_match( '/^[a-z0-9][a-z0-9_:\-]{0,79}$/', $value ) ) {
		return orders_error( 'fandoogh_invalid_method_filter', sprintf( __( 'شناسهٔ %s معتبر نیست.', 'fandoogh-manager' ), $label ), 422 );
	}

	return $value;
}

/**
 * Limit and normalize the public search term.
 *
 * @param mixed $value Candidate search text.
 * @return string
 */
function orders_search_term( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$term = trim( sanitize_text_field( (string) $value ) );
	$term = ltrim( $term, '#' );
	$term = orders_normalize_search_text( $term );
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $term, 0, 80 );
	}

	return substr( $term, 0, 80 );
}

/**
 * Normalize the limited order-search fields before comparing them. This
 * keeps Arabic/Persian keyboard variants and order numbers equivalent without
 * exposing another query language to the endpoint.
 *
 * @param mixed $value Candidate search text.
 * @return string
 */
function orders_normalize_search_text( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$text = trim( (string) $value );
	$text = str_replace( array( 'ي', 'ى', 'ك', 'ۀ', 'ة' ), array( 'ی', 'ی', 'ک', 'ه', 'ه' ), $text );
	$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}\x{200C}\x{200D}\x{200E}\x{200F}\x{FEFF}]/u', '', $text );
	$text = preg_replace_callback(
		'/[۰-۹٠-٩]/u',
		function ( $match ) {
			$persian = '۰۱۲۳۴۵۶۷۸۹';
			$arabic  = '٠١٢٣٤٥٦٧٨٩';
			$digit   = strpos( $persian, $match[0] );
			if ( false === $digit ) {
				$digit = strpos( $arabic, $match[0] );
			}
			return false === $digit ? $match[0] : (string) $digit;
		},
		$text
	);
	$text = preg_replace( '/\s+/u', ' ', $text );

	return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $text ), 'UTF-8' ) : strtolower( trim( $text ) );
}

/**
 * Match only the order identity and customer fields promised by the manager
 * search box. WooCommerce's helper can return broad matches on some versions;
 * this allowlisted second pass keeps a number search exact and predictable.
 *
 * @param \WC_Order $order WooCommerce order.
 * @param string    $term Normalized search term.
 * @return bool
 */
function orders_order_matches_search( $order, $term ) {
	if ( ! is_object( $order ) || '' === $term ) {
		return false;
	}

	$fields = array();
	foreach ( array( 'get_id', 'get_order_number', 'get_billing_first_name', 'get_billing_last_name', 'get_billing_email', 'get_billing_phone', 'get_shipping_first_name', 'get_shipping_last_name' ) as $method ) {
		if ( method_exists( $order, $method ) ) {
			try {
				$fields[] = $order->{$method}();
			} catch ( \Throwable $exception ) {
				// Ignore a field that is unavailable in this WooCommerce version.
			}
		}
	}

	$haystack = implode( ' ', array_map( __NAMESPACE__ . '\\orders_normalize_search_text', $fields ) );
	return false !== strpos( $haystack, $term );
}

/**
 * Resolve a bounded search term to order IDs through WooCommerce's official
 * search helper. The actual paginated objects are still loaded with
 * wc_get_orders(), so no SQL or post storage is used by this module.
 *
 * @param string $term Search term.
 * @return array<int, int>|\WP_Error
 */
function orders_search_ids( $term ) {
	if ( '' === $term ) {
		return array();
	}

	$repository = compose_order_repository();
	$found = array();
	if ( function_exists( 'wc_order_search' ) ) {
		try {
			$found = $repository->search( $term );
		} catch ( \Throwable $exception ) {
			$found = array();
		}
		if ( is_wp_error( $found ) ) {
			$found = array();
		}
	}
	if ( empty( $found ) && function_exists( 'wc_get_orders' ) ) {
		try {
			$found = $repository->query(
				array(
					'limit'  => 500,
					'return' => 'ids',
					'search' => $term,
					'status' => array_keys( orders_allowed_statuses() ),
				)
			);
		} catch ( \Throwable $exception ) {
			return orders_error( 'fandoogh_orders_search_failed', __( 'جست‌وجوی سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
		}
		if ( is_wp_error( $found ) ) {
			return orders_error( 'fandoogh_orders_search_failed', __( 'جست‌وجوی سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
		}
	}

	$normalized_term = orders_normalize_search_text( $term );
	if ( empty( $found ) && function_exists( 'wc_get_orders' ) ) {
		// A few WooCommerce data stores do not implement customer-name search
		// consistently. Keep the fallback bounded and use CRUD getters for the
		// final comparison instead of querying post storage directly.
		try {
			$found = $repository->query(
				array(
					'limit'  => 500,
					'return' => 'objects',
					'order'  => 'DESC',
					'orderby' => 'date',
					'status' => array_keys( orders_allowed_statuses() ),
				)
			);
		} catch ( \Throwable $exception ) {
			$found = array();
		}
	}
	$ids             = array();
	foreach ( (array) $found as $candidate ) {
		$order_id = is_object( $candidate ) && method_exists( $candidate, 'get_id' ) ? absint( $candidate->get_id() ) : absint( $candidate );
		$matches = false;
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			try {
				$order = is_object( $candidate ) && method_exists( $candidate, 'get_id' ) ? $candidate : $repository->findById( $order_id );
				$matches = orders_order_matches_search( $order, $normalized_term );
			} catch ( \Throwable $exception ) {
				$matches = false;
			}
		}
		if ( $order_id > 0 && $matches ) {
			$ids[ $order_id ] = $order_id;
		}
	}

	return array_values( $ids );
}

/**
 * @param object $order WooCommerce order.
 * @return bool
 */
function orders_is_readable_order( $order ) {
	if ( ! is_object( $order ) || ! ( $order instanceof \WC_Order ) || ! method_exists( $order, 'get_status' ) ) {
		return false;
	}

	$status = orders_normalize_status( $order->get_status() );
	return '' !== $status && isset( orders_allowed_statuses()[ $status ] );
}

/**
 * @param string $status WooCommerce status without or with prefix.
 * @return string
 */
function orders_status_label( $status ) {
	$status = orders_normalize_status( $status );
	$allowed = orders_allowed_statuses();
	if ( isset( $allowed[ $status ] ) ) {
		return $allowed[ $status ];
	}

	return sanitize_text_field( preg_replace( '/^wc-/', '', $status ) );
}

/**
 * Call a scalar order getter without exposing arbitrary object properties.
 *
 * @param object $order Order object.
 * @param string $method Getter method.
 * @return mixed
 */
function orders_getter_value( $order, $method ) {
	if ( ! is_object( $order ) || ! method_exists( $order, $method ) ) {
		return '';
	}

	try {
		return $order->{$method}();
	} catch ( \Throwable $exception ) {
		return '';
	}
}

/**
 * @param mixed $value Candidate public text.
 * @param int   $max_length Maximum length.
 * @return string
 */
function orders_clean_text( $value, $max_length = 255 ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	if ( strlen( $value ) > $max_length ) {
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max_length );
		} else {
			$value = substr( $value, 0, $max_length );
		}
	}

	return $value;
}

/**
 * @param mixed $value Candidate email.
 * @return string
 */
function orders_clean_email( $value ) {
	return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
}

/**
 * Keep monetary values as WooCommerce decimal strings to avoid floating point
 * changes in a management UI.
 *
 * @param mixed $value Candidate amount.
 * @return string
 */
function orders_money( $value ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return '0';
	}

	$value = (string) $value;
	$formatted = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, false, false ) : $value;
	return is_numeric( $formatted ) ? (string) $formatted : '0';
}

/**
 * Convert a WooCommerce date object to a public ISO/RFC3339 value.
 *
 * @param mixed $date WooCommerce date object.
 * @return string|null
 */
function orders_date( $date ) {
	if ( ! $date ) {
		return null;
	}

	try {
		if ( function_exists( 'wc_rest_prepare_date_response' ) ) {
			$formatted = wc_rest_prepare_date_response( $date, true );
			return '' !== (string) $formatted ? orders_clean_text( $formatted, 40 ) : null;
		}

		if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
			return orders_clean_text( $date->date( DATE_ATOM ), 40 );
		}
	} catch ( \Throwable $exception ) {
		return null;
	}

	return null;
}

/**
 * @param object $order WooCommerce order.
 * @param string $type billing or shipping.
 * @return array<string, string>
 */
function serialize_order_address( $order, $type ) {
	$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
	if ( 'billing' === $type ) {
		$fields[] = 'email';
		$fields[] = 'phone';
	} elseif ( method_exists( $order, 'get_shipping_phone' ) ) {
		$fields[] = 'phone';
	}

	$address = array();
	foreach ( $fields as $field ) {
		$method = 'get_' . $type . '_' . $field;
		$value  = orders_getter_value( $order, $method );
		if ( 'email' === $field ) {
			$address[ $field ] = orders_clean_email( $value );
		} else {
			$address[ $field ] = orders_clean_text( $value, 255 );
		}
	}

	return $address;
}

/**
 * @param object $order WooCommerce order.
 * @return array<string, mixed>
 */
function serialize_order_customer( $order ) {
	$first_name = orders_clean_text( orders_getter_value( $order, 'get_billing_first_name' ), 120 );
	$last_name  = orders_clean_text( orders_getter_value( $order, 'get_billing_last_name' ), 120 );
	$display    = trim( $first_name . ' ' . $last_name );

	if ( '' === $display ) {
		$display = __( 'مهمان', 'fandoogh-manager' );
	}

	$customer_id = absint( orders_getter_value( $order, 'get_customer_id' ) );
	return array(
		'id'      => $customer_id,
		'display' => orders_clean_text( $display, 255 ),
		'email'   => orders_clean_email( orders_getter_value( $order, 'get_billing_email' ) ),
		'phone'   => orders_clean_text( orders_getter_value( $order, 'get_billing_phone' ), 80 ),
		'type'    => $customer_id > 0 ? 'registered' : 'guest',
	);
}

/**
 * Serialize only the operational line-item fields needed by the manager.
 * No item metadata or arbitrary product/order properties are read.
 *
 * @param object $item Order line item.
 * @param int    $item_id Line item ID.
 * @return array<string, mixed>
 */
function serialize_order_line_item( $item, $item_id ) {
	$product    = false;
	$image_id   = 0;
	$image_url  = '';
	if ( method_exists( $item, 'get_product' ) ) {
		try {
			$product = $item->get_product();
		} catch ( \Throwable $exception ) {
			$product = false;
		}
	}
	if ( is_object( $product ) && method_exists( $product, 'get_image_id' ) ) {
		$image_id = absint( $product->get_image_id() );
	}
	if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
		$image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
		$image_url = is_string( $image_url ) ? $image_url : '';
		if ( function_exists( 'public_asset_url' ) ) {
			$image_url = public_asset_url( $image_url );
		}
	}

	return array(
		'id'           => absint( $item_id ),
		'name'         => orders_clean_text( orders_getter_value( $item, 'get_name' ), 255 ),
		'product_id'   => absint( orders_getter_value( $item, 'get_product_id' ) ),
		'variation_id' => absint( orders_getter_value( $item, 'get_variation_id' ) ),
		'image_id'     => $image_id,
		'image'        => $image_url,
		'quantity'     => max( 0, (int) orders_getter_value( $item, 'get_quantity' ) ),
		'subtotal'     => orders_money( orders_getter_value( $item, 'get_subtotal' ) ),
		'subtotal_tax' => orders_money( orders_getter_value( $item, 'get_subtotal_tax' ) ),
		'total'        => orders_money( orders_getter_value( $item, 'get_total' ) ),
		'total_tax'    => orders_money( orders_getter_value( $item, 'get_total_tax' ) ),
	);
}

/**
 * @param object $order WooCommerce order.
 * @return array<string, mixed>
 */
function serialize_order_line_items( $order ) {
	$items = array();
	if ( ! method_exists( $order, 'get_items' ) ) {
		return $items;
	}

	try {
		$line_items = $order->get_items( 'line_item' );
	} catch ( \Throwable $exception ) {
		return $items;
	}

	foreach ( (array) $line_items as $item_id => $item ) {
		if ( ! is_object( $item ) ) {
			continue;
		}
		$items[] = serialize_order_line_item( $item, $item_id );
	}

	return $items;
}

/**
 * @param object $order WooCommerce order.
 * @param array<string, mixed> $line_items Serialized line items.
 * @return array<string, mixed>
 */
function serialize_order_item_summary( $order, $line_items ) {
	$quantity = 0;
	foreach ( $line_items as $line_item ) {
		$quantity += isset( $line_item['quantity'] ) ? absint( $line_item['quantity'] ) : 0;
	}

	return array(
		'count'    => count( $line_items ),
		'quantity' => $quantity,
	);
}

/**
 * @param object $order WooCommerce order.
 * @return array<string, string>
 */
function serialize_order_totals( $order ) {
	$methods = array(
		'subtotal'       => 'get_subtotal',
		'discount_total' => 'get_discount_total',
		'discount_tax'   => 'get_discount_tax',
		'shipping_total' => 'get_shipping_total',
		'shipping_tax'   => 'get_shipping_tax',
		'cart_tax'       => 'get_cart_tax',
		'total_tax'      => 'get_total_tax',
		'total'          => 'get_total',
	);

	$totals = array();
	foreach ( $methods as $key => $method ) {
		$totals[ $key ] = orders_money( orders_getter_value( $order, $method ) );
	}

	return $totals;
}

/**
 * Serialize only the selected shipping methods and their totals. This is
 * operational fulfillment data; gateway tokens, raw meta, and arbitrary item
 * properties remain outside the response contract.
 *
 * @param object $order WooCommerce order.
 * @return array<int, array<string, mixed>>
 */
function serialize_order_shipping_lines( $order ) {
	$items = array();
	if ( ! method_exists( $order, 'get_items' ) ) {
		return $items;
	}

	try {
		$shipping_lines = $order->get_items( 'shipping' );
	} catch ( \Throwable $exception ) {
		return $items;
	}

	foreach ( (array) $shipping_lines as $item_id => $item ) {
		if ( ! is_object( $item ) ) {
			continue;
		}

		$items[] = array(
			'id'          => absint( $item_id ),
			'method_id'   => orders_clean_text( orders_getter_value( $item, 'get_method_id' ), 100 ),
			'method_title'=> orders_clean_text( orders_getter_value( $item, 'get_name' ), 255 ),
			'total'       => orders_money( orders_getter_value( $item, 'get_total' ) ),
			'total_tax'   => orders_money( orders_getter_value( $item, 'get_total_tax' ) ),
		);
	}

	return $items;
}

/**
 * Serialize payment method metadata without exposing gateway settings or
 * payment tokens.
 *
 * @param object $order WooCommerce order.
 * @return array<string, mixed>
 */
function serialize_order_payment( $order ) {
	$status    = sanitize_key( (string) orders_getter_value( $order, 'get_status' ) );
	$date_paid = orders_date( orders_getter_value( $order, 'get_date_paid' ) );
	$is_paid   = '' !== $date_paid;
	if ( method_exists( $order, 'is_paid' ) ) {
		try {
			$is_paid = (bool) $order->is_paid();
		} catch ( \Throwable $exception ) {
			// Keep the date-based fallback when a custom order object cannot check payment.
		}
	}

	if ( 'refunded' === $status ) {
		$payment_status = 'refunded';
	} elseif ( $is_paid ) {
		$payment_status = 'paid';
	} else {
		$payment_status = 'unpaid';
	}

	return array(
		'method'       => orders_clean_text( orders_getter_value( $order, 'get_payment_method' ), 100 ),
		'method_title' => orders_clean_text( orders_getter_value( $order, 'get_payment_method_title' ), 255 ),
		'status'       => $payment_status,
		'date_paid'    => $date_paid,
	);
}

/**
 * Serialize the bounded order-note projection returned by WooCommerce's
 * public helper. Raw comments, author emails, and arbitrary meta stay out of
 * the response contract.
 *
 * @param object $order WooCommerce order.
 * @return array<int, array<string, mixed>>
 */
function serialize_order_notes( $order ) {
	if ( ! function_exists( 'wc_get_order_notes' ) ) {
		return array();
	}

	$order_id = absint( orders_getter_value( $order, 'get_id' ) );
	if ( $order_id < 1 ) {
		return array();
	}

	try {
		$notes = compose_order_repository()->queryNotes(
			array(
				'order_id' => $order_id,
				'limit'    => 50,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);
	} catch ( \Throwable $exception ) {
		return array();
	}

	$serialized = array();
	foreach ( (array) $notes as $note ) {
		if ( ! is_object( $note ) ) {
			continue;
		}

		$id      = isset( $note->id ) ? absint( $note->id ) : ( isset( $note->comment_ID ) ? absint( $note->comment_ID ) : 0 );
		$content = isset( $note->content ) ? $note->content : ( isset( $note->comment_content ) ? $note->comment_content : '' );
		$date    = isset( $note->date_created ) ? $note->date_created : ( isset( $note->comment_date_gmt ) ? $note->comment_date_gmt : '' );
		if ( is_scalar( $date ) ) {
			$date = orders_clean_text( $date, 40 );
		} else {
			$date = orders_date( $date );
		}

		$serialized[] = array(
			'id'            => $id,
			'content'       => orders_clean_text( $content, 1000 ),
			'date'          => $date,
			'added_by'      => orders_clean_text( isset( $note->added_by ) ? $note->added_by : ( isset( $note->comment_author ) ? $note->comment_author : '' ), 120 ),
			'customer_note' => ! empty( $note->customer_note ),
		);
	}

	return $serialized;
}

/**
 * Serialize refund totals and a bounded refund list for the order detail
 * view. Gateway data, transaction IDs and arbitrary refund meta are omitted.
 *
 * @param object $order WooCommerce order.
 * @return array<string, mixed>
 */
function serialize_order_refunds( $order ) {
	$total_refunded = method_exists( $order, 'get_total_refunded' ) ? orders_money( $order->get_total_refunded() ) : '0';
	$remaining      = method_exists( $order, 'get_remaining_refund_amount' ) ? orders_money( $order->get_remaining_refund_amount() ) : orders_money( max( 0, (float) orders_getter_value( $order, 'get_total' ) - (float) $total_refunded ) );
	$items = array();
	if ( method_exists( $order, 'get_refunds' ) ) {
		try {
			foreach ( (array) $order->get_refunds() as $refund ) {
				if ( is_object( $refund ) && method_exists( $refund, 'get_id' ) ) {
					$items[] = serialize_order_refund( $refund );
				}
			}
		} catch ( \Throwable $exception ) {
			$items = array();
		}
	}
	return array(
		'total_refunded' => $total_refunded,
		'remaining'     => $remaining,
		'items'         => array_slice( $items, 0, 20 ),
	);
}

/**
 * Serialize a WooCommerce order through an explicit public-field allowlist.
 *
 * @param object $order WooCommerce order.
 * @param bool   $detail Include line items and lifecycle dates.
 * @return array<string, mixed>
 */
function serialize_order( $order, $detail = false ) {
	$status      = orders_clean_text( orders_getter_value( $order, 'get_status' ), 64 );
	$created     = orders_date( orders_getter_value( $order, 'get_date_created' ) );
	$line_items  = serialize_order_line_items( $order );
	$currency    = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', orders_clean_text( orders_getter_value( $order, 'get_currency' ), 12 ) ) );
	$totals      = serialize_order_totals( $order );
	$order_id    = absint( orders_getter_value( $order, 'get_id' ) );
	$order_number = orders_clean_text( orders_getter_value( $order, 'get_order_number' ), 80 );
	if ( '' === $order_number ) {
		$order_number = (string) $order_id;
	}

	$data = array(
		'id'          => $order_id,
		'number'      => $order_number,
		'status'      => sanitize_key( $status ),
		'status_label' => orders_status_label( $status ),
		'date'        => $created,
		'total'       => orders_money( orders_getter_value( $order, 'get_total' ) ),
		'currency'    => $currency,
		'customer'    => serialize_order_customer( $order ),
		'payment'     => serialize_order_payment( $order ),
		'billing'     => serialize_order_address( $order, 'billing' ),
		'shipping'    => serialize_order_address( $order, 'shipping' ),
		'items'       => serialize_order_item_summary( $order, $line_items ),
		'totals'      => $totals,
	);

	if ( $detail ) {
		$data['line_items']     = $line_items;
		$data['shipping_lines'] = serialize_order_shipping_lines( $order );
		$data['date_modified']  = orders_date( orders_getter_value( $order, 'get_date_modified' ) );
		$data['date_paid']      = orders_date( orders_getter_value( $order, 'get_date_paid' ) );
		$data['date_completed'] = orders_date( orders_getter_value( $order, 'get_date_completed' ) );
		$data['customer_note']  = orders_clean_text( orders_getter_value( $order, 'get_customer_note' ), 1000 );
		$data['notes']          = serialize_order_notes( $order );
		$data['refunds']        = serialize_order_refunds( $order );
	}

	return $data;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function list_orders( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! orders_available() ) {
		return orders_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API سفارش‌ها در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	apply_session_user_context( $session );
	$page     = orders_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = orders_query_integer( $request->get_param( 'per_page' ), 20, 1, 50 );
	$allowed  = orders_allowed_statuses();
	if ( empty( $allowed ) ) {
		return orders_error( 'fandoogh_order_statuses_unavailable', __( 'وضعیت‌های سفارش WooCommerce در دسترس نیستند.', 'fandoogh-manager' ), 503 );
	}

	$status_param = $request->get_param( 'status' );
	$status       = '' === (string) $status_param ? 'any' : sanitize_key( (string) $status_param );
	if ( 'any' === $status ) {
		$query_status = array_keys( $allowed );
	} else {
		$status = orders_normalize_status( $status );
		if ( ! isset( $allowed[ $status ] ) ) {
			return orders_error( 'fandoogh_invalid_status', __( 'فیلتر وضعیت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$query_status = array( $status );
	}

	$search = orders_search_term( $request->get_param( 'search' ) );
	if ( '' !== $search && strlen( $search ) < 2 ) {
		return orders_error( 'fandoogh_invalid_search', __( 'عبارت جست‌وجوی سفارش باید حداقل دو نویسه باشد.', 'fandoogh-manager' ), 422 );
	}

	$date_from = orders_date_filter( $request->get_param( 'date_from' ) );
	if ( is_wp_error( $date_from ) ) {
		return $date_from;
	}
	$date_to = orders_date_filter( $request->get_param( 'date_to' ) );
	if ( is_wp_error( $date_to ) ) {
		return $date_to;
	}
	if ( '' !== $date_from && '' !== $date_to && $date_from > $date_to ) {
		return orders_error( 'fandoogh_invalid_date_range', __( 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.', 'fandoogh-manager' ), 422 );
	}

	$min_total = orders_total_filter( $request->get_param( 'min_total' ) );
	if ( is_wp_error( $min_total ) ) {
		return $min_total;
	}
	$max_total = orders_total_filter( $request->get_param( 'max_total' ) );
	if ( is_wp_error( $max_total ) ) {
		return $max_total;
	}
	if ( '' !== $min_total && '' !== $max_total && (float) $min_total > (float) $max_total ) {
		return orders_error( 'fandoogh_invalid_total_range', __( 'حداقل مبلغ نمی‌تواند از حداکثر مبلغ بیشتر باشد.', 'fandoogh-manager' ), 422 );
	}

	$customer_param = $request->get_param( 'customer_id' );
	if ( ! is_scalar( $customer_param ) && null !== $customer_param ) {
		return orders_error( 'fandoogh_invalid_customer_id', __( 'شناسهٔ مشتری معتبر نیست.', 'fandoogh-manager' ), 422 );
	}
	$customer_id = null === $customer_param || '' === (string) $customer_param ? 0 : absint( $customer_param );
	if ( '' !== (string) $customer_param && $customer_id < 1 ) {
		return orders_error( 'fandoogh_invalid_customer_id', __( 'شناسهٔ مشتری معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	$payment_method = orders_method_filter( $request->get_param( 'payment_method' ), __( 'روش پرداخت', 'fandoogh-manager' ) );
	if ( is_wp_error( $payment_method ) ) {
		return $payment_method;
	}
	$shipping_method = orders_method_filter( $request->get_param( 'shipping_method' ), __( 'روش ارسال', 'fandoogh-manager' ) );
	if ( is_wp_error( $shipping_method ) ) {
		return $shipping_method;
	}

	$args = array(
		'limit'    => $per_page,
		'page'     => $page,
		'paginate' => true,
		'return'   => 'objects',
		'type'     => 'shop_order',
		'status'   => $query_status,
		'orderby'  => 'date',
		'order'    => 'DESC',
	);
	if ( '' !== $date_from && '' !== $date_to ) {
		$args['date_created'] = $date_from . '...' . $date_to . ' 23:59:59';
	} elseif ( '' !== $date_from ) {
		$args['date_created'] = '>=' . $date_from . ' 00:00:00';
	} elseif ( '' !== $date_to ) {
		$args['date_created'] = '<=' . $date_to . ' 23:59:59';
	}
	if ( $customer_id > 0 ) {
		$args['customer_id'] = $customer_id;
	}
	if ( '' !== $min_total ) {
		$args['min_total'] = $min_total;
	}
	if ( '' !== $max_total ) {
		$args['max_total'] = $max_total;
	}
	if ( '' !== $payment_method ) {
		$args['payment_method'] = $payment_method;
	}
	if ( '' !== $shipping_method ) {
		$args['shipping_method'] = $shipping_method;
	}

	$search_limited = false;
	$skip_query     = false;
	if ( '' !== $search ) {
		$search_ids = orders_search_ids( $search );
		if ( is_wp_error( $search_ids ) ) {
			return $search_ids;
		}
		$search_limited = count( $search_ids ) > 500;
		$args['include'] = array_slice( $search_ids, 0, 500 );
		if ( empty( $args['include'] ) ) {
			// Some WooCommerce data stores treat include=[0] as no include
			// constraint and return the full collection. Skip the query instead.
			$skip_query = true;
		}
	}

	if ( $skip_query ) {
		$results = (object) array(
			'orders'        => array(),
			'total'         => 0,
			'max_num_pages' => 0,
		);
	} else {
		try {
			$results = compose_order_repository()->query( $args );
		} catch ( \Throwable $exception ) {
			return orders_error( 'fandoogh_orders_query_failed', __( 'خواندن فهرست سفارش‌ها انجام نشد.', 'fandoogh-manager' ), 500 );
		}
	}

	if ( ! is_object( $results ) || ! isset( $results->orders, $results->total, $results->max_num_pages ) ) {
		return orders_error( 'fandoogh_orders_query_invalid', __( 'پاسخ API سفارش‌های WooCommerce معتبر نیست.', 'fandoogh-manager' ), 500 );
	}

	$items = array();
	foreach ( (array) $results->orders as $order ) {
		if ( orders_is_readable_order( $order ) ) {
			$items[] = serialize_order( $order, false );
		}
	}

	return orders_no_store_response(
		array(
			'data' => $items,
			'meta' => array(
				'page'          => $page,
				'per_page'      => $per_page,
				'total'         => absint( $results->total ),
				'total_pages'   => absint( $results->max_num_pages ),
				'search_limited' => $search_limited,
				'statuses'      => $allowed,
				'filters'       => array(
					'search'          => $search,
					'status'          => $status,
					'date_from'       => $date_from,
					'date_to'         => $date_to,
					'customer_id'     => $customer_id > 0 ? $customer_id : '',
					'min_total'       => $min_total,
					'max_total'       => $max_total,
					'payment_method'  => $payment_method,
					'shipping_method' => $shipping_method,
				),
			),
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function get_order_detail( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}

	if ( ! orders_available() ) {
		return orders_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API سفارش‌ها در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$order_id = absint( $request['id'] );
	if ( $order_id < 1 ) {
		return orders_error( 'fandoogh_invalid_order_id', __( 'شناسهٔ سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	apply_session_user_context( $session );
	try {
		$order = compose_order_repository()->findById( $order_id );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_order_read_failed', __( 'خواندن سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	return orders_no_store_response( array( 'data' => serialize_order( $order, true ) ) );
}
