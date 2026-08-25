<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_orders',
			'permission_callback' => __NAMESPACE__ . '\\orders_read_permission',
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
	if ( ! is_array( $body ) || ! isset( $body['status'] ) || count( array_diff( array_keys( $body ), array( 'status', 'note' ) ) ) > 0 ) {
		return orders_error( 'fandoogh_invalid_input', __( 'وضعیت سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	return $body;
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
		$order = $order_id ? wc_get_order( $order_id ) : false;
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_order_read_failed', __( 'خواندن سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	try {
		$order->update_status( preg_replace( '/^wc-/', '', $status ), $note, true );
		$order = wc_get_order( $order_id );
	} catch ( \Throwable $exception ) {
		try {
			$order = wc_get_order( $order_id );
		} catch ( \Throwable $read_exception ) {
			$order = false;
		}
	}

	if ( ! orders_status_matches( $order, $status ) ) {
		return orders_error( 'fandoogh_order_status_failed', __( 'تغییر وضعیت سفارش انجام نشد.', 'fandoogh-manager' ), 422 );
	}

	record_audit_event( 'order_status_updated', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'status' => $order->get_status() ) );
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
	$order = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	try {
		$note_id = $order->add_order_note( $content, $customer_note );
		$order   = wc_get_order( $order_id );
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
		'idempotency_key' => isset( $body['idempotency_key'] ) && is_scalar( $body['idempotency_key'] ) ? sanitize_key( substr( (string) $body['idempotency_key'], 0, 80 ) ) : '',
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

function refund_order( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return orders_private_error( $session );
	}
	if ( ! orders_available() || ! function_exists( 'wc_create_refund' ) ) {
		return orders_error( 'fandoogh_refund_unavailable', __( 'امکان بازپرداخت در نسخهٔ فعلی WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}
	$order_id = absint( $request->get_param( 'id' ) );
	apply_session_user_context( $session );
	$order = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	$values = order_refund_values( $request, $order );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	$idempotency_transient = '';
	if ( '' !== $values['idempotency_key'] ) {
		$idempotency_transient = 'fandoogh_refund_' . substr( hash( 'sha256', $session['id'] . '|' . $order_id . '|' . $values['idempotency_key'] ), 0, 32 );
		$previous_refund_id = absint( get_transient( $idempotency_transient ) );
		if ( $previous_refund_id > 0 ) {
			$previous_refund = function_exists( 'wc_get_order' ) ? wc_get_order( $previous_refund_id ) : false;
			if ( $previous_refund && method_exists( $previous_refund, 'get_id' ) ) {
				return orders_no_store_response( array( 'data' => serialize_order( $order, true ), 'refund' => serialize_order_refund( $previous_refund ), 'idempotent_replay' => true ) );
			}
		}
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
		$refund = wc_create_refund( $args );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_refund_failed', __( 'بازپرداخت سفارش انجام نشد؛ پاسخ درگاه یا تنظیمات سفارش را بررسی کنید.', 'fandoogh-manager' ), 422 );
	}
	if ( is_wp_error( $refund ) || ! is_object( $refund ) || ! method_exists( $refund, 'get_id' ) ) {
		return is_wp_error( $refund ) ? orders_private_error( $refund ) : orders_error( 'fandoogh_refund_failed', __( 'بازپرداخت سفارش انجام نشد.', 'fandoogh-manager' ), 422 );
	}
	if ( $idempotency_transient ) {
		set_transient( $idempotency_transient, absint( $refund->get_id() ), DAY_IN_SECONDS );
	}
	$fresh_order = wc_get_order( $order_id );
	if ( orders_is_readable_order( $fresh_order ) ) {
		$order = $fresh_order;
	}
	record_audit_event( 'order_refunded', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'order_id' => $order_id, 'refund_id' => $refund->get_id(), 'status' => $values['refund_payment'] ? 'automatic' : 'manual' ) );
	return orders_no_store_response( array( 'data' => serialize_order( $order, true ), 'refund' => serialize_order_refund( $refund ) ) );
}

/**
 * @return bool
 */
function orders_available() {
	return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order' ) && function_exists( 'wc_get_order_statuses' ) && class_exists( '\\WC_Order' );
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
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return $allowed;
	}

	foreach ( (array) wc_get_order_statuses() as $status => $label ) {
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
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $term, 0, 80 );
	}

	return substr( $term, 0, 80 );
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

	if ( ! function_exists( 'wc_order_search' ) ) {
		return orders_error( 'fandoogh_orders_search_unavailable', __( 'جست‌وجوی سفارش در نسخهٔ فعلی WooCommerce در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	try {
		$found = wc_order_search( $term );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_orders_search_failed', __( 'جست‌وجوی سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	$ids = array();
	foreach ( (array) $found as $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id > 0 ) {
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
	return array(
		'id'           => absint( $item_id ),
		'name'         => orders_clean_text( orders_getter_value( $item, 'get_name' ), 255 ),
		'product_id'   => absint( orders_getter_value( $item, 'get_product_id' ) ),
		'variation_id' => absint( orders_getter_value( $item, 'get_variation_id' ) ),
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
		$notes = wc_get_order_notes(
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
	if ( '' !== $search ) {
		$search_ids = orders_search_ids( $search );
		if ( is_wp_error( $search_ids ) ) {
			return $search_ids;
		}
		$search_limited = count( $search_ids ) > 500;
		$args['include'] = array_slice( $search_ids, 0, 500 );
		if ( empty( $args['include'] ) ) {
			$args['include'] = array( 0 );
		}
	}

	try {
		$results = wc_get_orders( $args );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_orders_query_failed', __( 'خواندن فهرست سفارش‌ها انجام نشد.', 'fandoogh-manager' ), 500 );
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
		$order = wc_get_order( $order_id );
	} catch ( \Throwable $exception ) {
		return orders_error( 'fandoogh_order_read_failed', __( 'خواندن سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	if ( ! orders_is_readable_order( $order ) ) {
		return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	return orders_no_store_response( array( 'data' => serialize_order( $order, true ) ) );
}
