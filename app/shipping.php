<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SHIPPING_META_KEY = '_fandoogh_shipment';
const SHIPPING_TRACKING_MAX_LENGTH = 100;
const SHIPPING_CARRIER_MAX_LENGTH  = 80;
const SHIPPING_NOTE_MAX_LENGTH     = 500;
const SHIPPING_ITEMS_LIMIT          = 100;

/**
 * Register the provider-neutral shipment snapshot endpoints.
 *
 * Shipment data is stored only through WC_Order CRUD in a single controlled
 * order meta key. This keeps the module compatible with HPOS and deliberately
 * avoids provider APIs, webhooks, shipping-line mutations, payment changes,
 * and WooCommerce order-status changes.
 *
 * @return void
 */
function register_shipping_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/orders/(?P<id>\\d+)/shipment',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\get_order_shipment',
				'permission_callback' => __NAMESPACE__ . '\\shipment_read_permission',
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\update_order_shipment',
				'permission_callback' => __NAMESPACE__ . '\\shipment_write_permission',
			),
		)
	);
}

/**
 * Return the closed provider-neutral shipment status allowlist.
 *
 * @return array<string, string>
 */
function shipping_allowed_statuses() {
	return array(
		'pending'          => __( 'در انتظار ارسال', 'fandoogh-manager' ),
		'ready'            => __( 'آماده ارسال', 'fandoogh-manager' ),
		'shipped'          => __( 'ارسال شده', 'fandoogh-manager' ),
		'in_transit'       => __( 'در مسیر', 'fandoogh-manager' ),
		'out_for_delivery' => __( 'در حال تحویل', 'fandoogh-manager' ),
		'delivered'        => __( 'تحویل شده', 'fandoogh-manager' ),
		'failed'           => __( 'ارسال ناموفق', 'fandoogh-manager' ),
		'returned'         => __( 'مرجوع شده', 'fandoogh-manager' ),
		'cancelled'        => __( 'لغو شده', 'fandoogh-manager' ),
	);
}

/**
 * @return array<string, mixed>
 */
function shipping_snapshot_defaults() {
	return array(
		'status'                  => 'pending',
		'shipping_method_id'      => '',
		'shipping_method_title'   => '',
		'tracking_code'           => '',
		'carrier_id'              => '',
		'carrier_label'           => '',
		'shipped_at'              => '',
		'estimated_delivery_date' => '',
		'delivered_at'            => '',
		'note'                    => '',
		'items'                   => array(),
		'updated_at'              => '',
		'updated_by'              => 0,
	);
}

/**
 * Ensure all values saved in the controlled shipment meta shape are bounded
 * and drawn only from the public contract. Invalid legacy/manual meta is
 * ignored rather than exposed.
 *
 * @param mixed $raw Stored meta value.
 * @return array<string, mixed>
 */
function shipping_normalize_snapshot( $raw ) {
	$snapshot = shipping_snapshot_defaults();
	if ( ! is_array( $raw ) ) {
		return $snapshot;
	}

	$statuses = shipping_allowed_statuses();
	if ( isset( $raw['status'] ) && is_string( $raw['status'] ) && isset( $statuses[ $raw['status'] ] ) ) {
		$snapshot['status'] = $raw['status'];
	}

	foreach ( array( 'shipping_method_id', 'shipping_method_title', 'tracking_code', 'carrier_label', 'note' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
			if ( 'shipping_method_title' === $key ) {
				$max_length = 255;
			} elseif ( 'shipping_method_id' === $key ) {
				$max_length = 100;
			} elseif ( 'tracking_code' === $key ) {
				$max_length = SHIPPING_TRACKING_MAX_LENGTH;
			} elseif ( 'carrier_label' === $key ) {
				$max_length = SHIPPING_CARRIER_MAX_LENGTH;
			} else {
				$max_length = SHIPPING_NOTE_MAX_LENGTH;
			}
			$snapshot[ $key ] = shipping_bounded_stored_text( $raw[ $key ], $max_length );
		}
	}

	if ( isset( $raw['carrier_id'] ) && is_scalar( $raw['carrier_id'] ) && function_exists( __NAMESPACE__ . '\\order_tracking_sanitize_provider_id' ) ) {
		$carrier_id = order_tracking_sanitize_provider_id( $raw['carrier_id'] );
		if ( '' !== $carrier_id && function_exists( __NAMESPACE__ . '\\order_tracking_get_provider' ) && order_tracking_get_provider( $carrier_id ) ) {
			$snapshot['carrier_id'] = $carrier_id;
		}
	}

	foreach ( array( 'shipped_at', 'estimated_delivery_date', 'delivered_at', 'updated_at' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
			$normalized = 'estimated_delivery_date' === $key
				? shipping_normalize_stored_date( $raw[ $key ] )
				: shipping_normalize_stored_datetime( $raw[ $key ] );
			if ( '' !== $normalized ) {
				$snapshot[ $key ] = $normalized;
			}
		}
	}

	if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
		$items = array();
		foreach ( array_slice( $raw['items'], 0, SHIPPING_ITEMS_LIMIT ) as $raw_item ) {
			if ( ! is_array( $raw_item ) || ! isset( $raw_item['item_id'], $raw_item['quantity'] ) || ! is_scalar( $raw_item['item_id'] ) || ! is_scalar( $raw_item['quantity'] ) ) {
				continue;
			}
			$item_id  = absint( $raw_item['item_id'] );
			$quantity = absint( $raw_item['quantity'] );
			if ( $item_id > 0 && $quantity > 0 ) {
				$items[ $item_id ] = array( 'item_id' => $item_id, 'quantity' => min( 2147483647, $quantity ) );
			}
		}
		$snapshot['items'] = array_values( $items );
	}

	if ( isset( $raw['updated_by'] ) && is_scalar( $raw['updated_by'] ) ) {
		$snapshot['updated_by'] = absint( $raw['updated_by'] );
	}

	return $snapshot;
}

/**
 * @param mixed $value Stored text.
 * @param int   $max_length Maximum character length.
 * @return string
 */
function shipping_bounded_stored_text( $value, $max_length ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	if ( function_exists( 'wp_check_invalid_utf8' ) ) {
		$value = wp_check_invalid_utf8( $value );
	}
	$value = str_replace( "\0", '', $value );
	$value = str_replace( array( '<', '>' ), '', $value );
	$value = shipping_substr( $value, 0, $max_length );
	return $value;
}

/**
 * Normalize a previously saved RFC3339 UTC timestamp without accepting an
 * arbitrary date string from storage.
 *
 * @param string $value Stored timestamp.
 * @return string
 */
function shipping_normalize_stored_datetime( $value ) {
	if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})T(\\d{2}):(\\d{2}):(\\d{2})(?:\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2})$/D', $value, $matches ) ) {
		return '';
	}

	if ( ! checkdate( absint( $matches[2] ), absint( $matches[3] ), absint( $matches[1] ) ) || absint( $matches[4] ) > 23 || absint( $matches[5] ) > 59 || absint( $matches[6] ) > 59 ) {
		return '';
	}

	if ( 'Z' !== $matches[7] && ( absint( substr( $matches[7], 1, 2 ) ) > 23 || absint( substr( $matches[7], 4, 2 ) ) > 59 ) ) {
		return '';
	}

	try {
		$date = new \DateTimeImmutable( $value );
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\\TH:i:s\\Z' );
	} catch ( \Throwable $exception ) {
		return '';
	}
}

/**
 * Normalize a previously saved YYYY-MM-DD date.
 *
 * @param string $value Stored date.
 * @return string
 */
function shipping_normalize_stored_date( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})$/D', $value, $matches ) ) {
		return '';
	}

	return checkdate( absint( $matches[2] ), absint( $matches[3] ), absint( $matches[1] ) ) ? $value : '';
}

/**
 * Return the current server time in a stable UTC representation.
 *
 * @return string
 */
function shipping_server_now() {
	return gmdate( 'Y-m-d\\TH:i:s\\Z' );
}

/**
 * @return array<string, string>
 */
function shipping_private_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
		'Content-Type'           => 'application/json; charset=UTF-8',
	);
}

/**
 * Preserve an error while ensuring REST serialization carries private JSON
 * response headers.
 *
 * @param \WP_Error $error Error to decorate.
 * @return \WP_Error
 */
function shipping_private_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return $error;
	}

	$code    = $error->get_error_code();
	$data    = $error->get_error_data( $code );
	$data    = is_array( $data ) ? $data : array();
	$headers = isset( $data['headers'] ) && is_array( $data['headers'] ) ? $data['headers'] : array();
	$data['headers'] = array_replace( $headers, shipping_private_headers() );

	return new \WP_Error( $code, $error->get_error_message( $code ), $data );
}

/**
 * @param string               $code Error code.
 * @param string               $message User-facing message.
 * @param int                  $status HTTP status.
 * @param array<string, mixed> $extra Safe extra data.
 * @return \WP_Error
 */
function shipping_error( $code, $message, $status, $extra = array() ) {
	$data = is_array( $extra ) ? $extra : array();
	$data['status'] = absint( $status );
	return shipping_private_error( new \WP_Error( $code, $message, $data ) );
}

/**
 * @param mixed $payload Response payload.
 * @return \WP_REST_Response
 */
function shipping_no_store_response( $payload ) {
	$response = rest_ensure_response( $payload );
	foreach ( shipping_private_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function shipment_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return shipping_private_error( $session );
	}

	if ( ! session_has_scope( 'orders.read', $session['scopes'] ) ) {
		return shipping_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز خواندن رهگیری ارسال را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	if ( ! shipping_user_can_manage_orders( $session['user']->ID ) ) {
		return shipping_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز خواندن رهگیری ارسال را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function shipment_write_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return shipping_private_error( $csrf );
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return shipping_private_error( $session );
	}

	if ( ! session_has_scope( 'orders.update_shipment', $session['scopes'] ) ) {
		return shipping_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز به‌روزرسانی رهگیری ارسال را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return shipping_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی ارسال را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	if ( ! shipping_user_can_manage_orders( $session['user']->ID ) ) {
		return shipping_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز به‌روزرسانی رهگیری ارسال را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * Reuse the same capability boundary as the existing order endpoints.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function shipping_user_can_manage_orders( $user_id ) {
	$user_id = absint( $user_id );
	return user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return int|\WP_Error
 */
function shipping_request_order_id( $request ) {
	$order_id = absint( $request->get_param( 'id' ) );
	return $order_id > 0
		? $order_id
		: shipping_error( 'fandoogh_shipping_invalid_order_id', __( 'شناسه سفارش معتبر نیست.', 'fandoogh-manager' ), 422 );
}

/**
 * Load only a real WooCommerce order through its public CRUD function.
 *
 * @param int $order_id WooCommerce order ID.
 * @return \WC_Order|\WP_Error
 */
function shipping_load_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) || ! class_exists( '\\WC_Order' ) ) {
		return shipping_error( 'fandoogh_shipping_woocommerce_unavailable', __( 'WooCommerce فعال نیست یا API سفارش‌ها در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	try {
		$order = wc_get_order( absint( $order_id ) );
	} catch ( \Throwable $exception ) {
		return shipping_error( 'fandoogh_shipping_order_read_failed', __( 'خواندن سفارش انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	if ( ! is_object( $order ) || ! ( $order instanceof \WC_Order ) || ! method_exists( $order, 'get_id' ) || absint( $order->get_id() ) !== absint( $order_id ) ) {
		return shipping_error( 'fandoogh_shipping_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	if ( function_exists( 'orders_is_readable_order' ) && ! orders_is_readable_order( $order ) ) {
		return shipping_error( 'fandoogh_shipping_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	return $order;
}

/**
 * Read the controlled shipment snapshot from WC_Order CRUD. No arbitrary meta
 * key or raw order storage is returned.
 *
 * @param \WC_Order $order WooCommerce order.
 * @return array<string, mixed>
 */
function shipping_read_snapshot( $order ) {
	$raw = array();
	try {
		$raw = $order->get_meta( SHIPPING_META_KEY, true );
	} catch ( \Throwable $exception ) {
		$raw = array();
	}

	return shipping_normalize_snapshot( $raw );
}

/**
 * Parse a strict JSON body. The endpoint is intentionally POST-only for
 * mutations; it does not accept form data, arbitrary fields, tracking URLs,
 * or client-controlled response-only fields.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string, mixed>|\WP_Error
 */
function shipping_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return shipping_error( 'fandoogh_shipping_json_required', __( 'بدنه درخواست ارسال باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_input', __( 'بدنه درخواست ارسال معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	$allowed_fields = array(
		'status',
		'shipping_method_id',
		'shipping_method_title',
		'tracking_code',
		'carrier_id',
		'carrier_label',
		'shipped_at',
		'estimated_delivery_date',
		'delivered_at',
		'note',
		'items',
	);
	foreach ( array_keys( $body ) as $field ) {
		if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
			return shipping_error( 'fandoogh_shipping_unknown_field', __( 'فیلد درخواست ارسال مجاز نیست.', 'fandoogh-manager' ), 422 );
		}
	}

	if ( ! array_key_exists( 'status', $body ) ) {
		return shipping_error( 'fandoogh_shipping_status_required', __( 'وضعیت ارسال الزامی است.', 'fandoogh-manager' ), 422 );
	}

	$status = shipping_validate_status( $body['status'] );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$parsed = array( 'status' => $status );
	if ( array_key_exists( 'carrier_id', $body ) ) {
		$carrier_id = shipping_validate_carrier_id( $body['carrier_id'] );
		if ( is_wp_error( $carrier_id ) ) {
			return $carrier_id;
		}
		$parsed['carrier_id'] = $carrier_id;
	}
	foreach ( array( 'shipping_method_id', 'shipping_method_title', 'tracking_code', 'carrier_label', 'note' ) as $field ) {
		if ( ! array_key_exists( $field, $body ) ) {
			continue;
		}

		if ( 'shipping_method_title' === $field ) {
			$max_length = 255;
		} elseif ( 'shipping_method_id' === $field ) {
			$max_length = 100;
		} elseif ( 'tracking_code' === $field ) {
			$max_length = SHIPPING_TRACKING_MAX_LENGTH;
		} elseif ( 'carrier_label' === $field ) {
			$max_length = SHIPPING_CARRIER_MAX_LENGTH;
		} else {
			$max_length = SHIPPING_NOTE_MAX_LENGTH;
		}
		$value      = shipping_validate_text( $body[ $field ], $max_length, $field );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$parsed[ $field ] = $value;
	}

	foreach ( array( 'shipped_at', 'delivered_at' ) as $field ) {
		if ( ! array_key_exists( $field, $body ) ) {
			continue;
		}

		$value = shipping_validate_datetime( $body[ $field ], $field );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$parsed[ $field ] = $value;
	}

	if ( array_key_exists( 'estimated_delivery_date', $body ) ) {
		$value = shipping_validate_date( $body['estimated_delivery_date'], 'estimated_delivery_date' );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$parsed['estimated_delivery_date'] = $value;
	}

	if ( array_key_exists( 'items', $body ) ) {
		if ( ! is_array( $body['items'] ) || count( $body['items'] ) > SHIPPING_ITEMS_LIMIT ) {
			return shipping_error( 'fandoogh_shipping_invalid_items', __( 'اقلام مرسوله معتبر نیستند.', 'fandoogh-manager' ), 422 );
		}
		$parsed['items'] = array();
		foreach ( $body['items'] as $raw_item ) {
			if ( ! is_array( $raw_item ) || ! isset( $raw_item['item_id'], $raw_item['quantity'] ) || ! is_scalar( $raw_item['item_id'] ) || ! is_scalar( $raw_item['quantity'] ) || absint( $raw_item['item_id'] ) < 1 || absint( $raw_item['quantity'] ) < 1 ) {
				return shipping_error( 'fandoogh_shipping_invalid_items', __( 'شناسه یا تعداد یکی از اقلام مرسوله معتبر نیست.', 'fandoogh-manager' ), 422 );
			}
			$parsed['items'][] = array( 'item_id' => absint( $raw_item['item_id'] ), 'quantity' => absint( $raw_item['quantity'] ) );
		}
	}

	return $parsed;
}

/**
 * @param mixed $value Candidate status.
 * @return string|\WP_Error
 */
function shipping_validate_status( $value ) {
	$statuses = shipping_allowed_statuses();
	if ( ! is_string( $value ) || ! isset( $statuses[ $value ] ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_status', __( 'وضعیت ارسال در allowlist نیست.', 'fandoogh-manager' ), 422 );
	}

	return $value;
}

/**
 * Validate an optional configured carrier ID. An empty value remains valid so
 * stores can record a custom carrier label without creating a provider record.
 *
 * @param mixed $value Candidate carrier ID.
 * @return string|\WP_Error
 */
function shipping_validate_carrier_id( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	if ( '' === $value ) {
		return '';
	}

	$carrier_id = function_exists( __NAMESPACE__ . '\\order_tracking_sanitize_provider_id' ) ? order_tracking_sanitize_provider_id( $value ) : '';
	if ( '' === $carrier_id || ! function_exists( __NAMESPACE__ . '\\order_tracking_get_provider' ) || ! order_tracking_get_provider( $carrier_id ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_carrier', __( 'شرکت حمل انتخاب‌شده معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	return $carrier_id;
}

/**
 * Reject HTML, NUL, disallowed control characters, invalid UTF-8, and
 * overlong text instead of silently sanitizing a mutation into another value.
 *
 * @param mixed  $value Candidate text.
 * @param int    $max_length Maximum character length.
 * @param string $field Public field name.
 * @return string|\WP_Error
 */
function shipping_validate_text( $value, $max_length, $field ) {
	if ( null === $value ) {
		return '';
	}

	if ( ! is_string( $value ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_input', __( 'مقدار یکی از فیلدهای ارسال معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	if ( false !== strpos( $value, "\0" ) || false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
		return shipping_error( 'fandoogh_shipping_html_or_nul', __( 'HTML و NUL در اطلاعات ارسال مجاز نیست.', 'fandoogh-manager' ), 422 );
	}

	if ( preg_match( '/[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_text', __( 'کاراکتر کنترل نامعتبر در اطلاعات ارسال وجود دارد.', 'fandoogh-manager' ), 422 );
	}

	if ( function_exists( 'wp_check_invalid_utf8' ) && wp_check_invalid_utf8( $value ) !== $value ) {
		return shipping_error( 'fandoogh_shipping_invalid_text', __( 'متن اطلاعات ارسال معتبر نیست.', 'fandoogh-manager' ), 422 );
	}

	$value = trim( $value );
	if ( shipping_strlen( $value ) > $max_length ) {
		return shipping_error( 'fandoogh_shipping_text_too_long', sprintf( __( 'طول فیلد %s بیش از حد مجاز است.', 'fandoogh-manager' ), $field ), 422 );
	}

	return $value;
}

/**
 * @param mixed  $value Candidate RFC3339 timestamp or empty string.
 * @param string $field Public field name.
 * @return string|\WP_Error Normalized UTC timestamp or empty string.
 */
function shipping_validate_datetime( $value, $field ) {
	if ( null === $value ) {
		return '';
	}

	if ( ! is_string( $value ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})T(\\d{2}):(\\d{2}):(\\d{2})(?:\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2})$/D', $value, $matches ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	if ( ! checkdate( absint( $matches[2] ), absint( $matches[3] ), absint( $matches[1] ) ) || absint( $matches[4] ) > 23 || absint( $matches[5] ) > 59 || absint( $matches[6] ) > 59 || ( 'Z' !== $matches[7] && ( absint( substr( $matches[7], 1, 2 ) ) > 23 || absint( substr( $matches[7], 4, 2 ) ) > 59 ) ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	try {
		$date = new \DateTimeImmutable( $value );
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\\TH:i:s\\Z' );
	} catch ( \Throwable $exception ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}
}

/**
 * @param mixed  $value Candidate YYYY-MM-DD date or empty string.
 * @param string $field Public field name.
 * @return string|\WP_Error Normalized date or empty string.
 */
function shipping_validate_date( $value, $field ) {
	if ( null === $value ) {
		return '';
	}

	if ( ! is_string( $value ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})$/D', $value, $matches ) || ! checkdate( absint( $matches[2] ), absint( $matches[3] ), absint( $matches[1] ) ) ) {
		return shipping_error( 'fandoogh_shipping_invalid_date', sprintf( __( 'تاریخ %s معتبر نیست.', 'fandoogh-manager' ), $field ), 422 );
	}

	return $value;
}

/**
 * @param string $value Candidate string.
 * @param int    $start Start offset.
 * @param int    $length Maximum length.
 * @return string
 */
function shipping_substr( $value, $start, $length ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $value, $start, $length ) : substr( $value, $start, $length );
}

/**
 * @param string $value Candidate string.
 * @return int
 */
function shipping_strlen( $value ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
}

/**
 * Serialize only the public shipment contract.
 *
 * @param int                  $order_id WooCommerce order ID.
 * @param array<string, mixed> $snapshot Controlled snapshot.
 * @return array<string, mixed>
 */
function shipping_serialize_snapshot( $order_id, $snapshot ) {
	$snapshot = shipping_normalize_snapshot( $snapshot );
	$tracking = (string) $snapshot['tracking_code'];
	$provider = function_exists( __NAMESPACE__ . '\\order_tracking_get_provider' ) ? order_tracking_get_provider( $snapshot['carrier_id'] ) : null;
	$tracking_url = ( '' !== $tracking && function_exists( __NAMESPACE__ . '\\order_tracking_build_url' ) ) ? order_tracking_build_url( $provider, $tracking ) : '';

	return array(
		'status'                  => $snapshot['status'],
		'shipping_method_id'      => (string) $snapshot['shipping_method_id'],
		'shipping_method_title'   => (string) $snapshot['shipping_method_title'],
		'tracking_code'           => $tracking,
		'carrier_id'              => (string) $snapshot['carrier_id'],
		'carrier_label'           => (string) $snapshot['carrier_label'],
		'tracking_url'            => '' !== $tracking_url ? $tracking_url : null,
		'shipped_at'              => '' !== $snapshot['shipped_at'] ? $snapshot['shipped_at'] : null,
		'estimated_delivery_date' => '' !== $snapshot['estimated_delivery_date'] ? $snapshot['estimated_delivery_date'] : null,
		'delivered_at'            => '' !== $snapshot['delivered_at'] ? $snapshot['delivered_at'] : null,
		'note'                    => (string) $snapshot['note'],
		'items'                   => array_values( $snapshot['items'] ),
		'updated_at'              => '' !== $snapshot['updated_at'] ? $snapshot['updated_at'] : null,
		'order_id'                => absint( $order_id ),
		'has_tracking'            => '' !== $tracking,
	);
}

/**
 * Merge a validated request into the controlled snapshot and save it through
 * WC_Order CRUD. This never changes the WooCommerce order status, payment,
 * shipping lines, or any other order data.
 *
 * @param \WC_Order           $order WooCommerce order.
 * @param array<string, mixed> $body Validated request body.
 * @param int                 $user_id WordPress user ID.
 * @return array<string, mixed>|\WP_Error
 */
function shipping_save_snapshot( $order, $body, $user_id ) {
	$snapshot = shipping_read_snapshot( $order );

	foreach ( array( 'status', 'shipping_method_id', 'shipping_method_title', 'tracking_code', 'carrier_id', 'carrier_label', 'shipped_at', 'estimated_delivery_date', 'delivered_at', 'note' ) as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$snapshot[ $field ] = $body[ $field ];
		}
	}
	if ( '' !== $snapshot['carrier_id'] && function_exists( __NAMESPACE__ . '\\order_tracking_get_provider' ) ) {
		$provider = order_tracking_get_provider( $snapshot['carrier_id'] );
		if ( $provider ) {
			$snapshot['carrier_label'] = $provider['label'];
		}
	}
	if ( array_key_exists( 'items', $body ) ) {
		$order_items = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : array();
		$valid_items = array();
		foreach ( $body['items'] as $item ) {
			$item_id = absint( $item['item_id'] );
			$quantity = absint( $item['quantity'] );
			if ( ! isset( $order_items[ $item_id ] ) || ! is_object( $order_items[ $item_id ] ) || $quantity < 1 ) {
				return shipping_error( 'fandoogh_shipping_invalid_items', __( 'یکی از اقلام مرسوله در سفارش پیدا نشد.', 'fandoogh-manager' ), 422 );
			}
			$order_quantity = absint( orders_getter_value( $order_items[ $item_id ], 'get_quantity' ) );
			if ( $quantity > $order_quantity ) {
				return shipping_error( 'fandoogh_shipping_invalid_quantity', __( 'تعداد مرسوله از تعداد سفارش بیشتر است.', 'fandoogh-manager' ), 422 );
			}
			$valid_items[ $item_id ] = array( 'item_id' => $item_id, 'quantity' => $quantity );
		}
		$snapshot['items'] = array_values( $valid_items );
	}

	$shipment_statuses = array( 'shipped', 'in_transit', 'out_for_delivery', 'delivered' );
	if ( in_array( $snapshot['status'], $shipment_statuses, true ) && '' === $snapshot['shipped_at'] ) {
		$snapshot['shipped_at'] = shipping_server_now();
	}
	if ( 'delivered' === $snapshot['status'] && '' === $snapshot['delivered_at'] ) {
		$snapshot['delivered_at'] = shipping_server_now();
	}
	$snapshot['updated_at'] = shipping_server_now();
	$snapshot['updated_by'] = absint( $user_id );

	try {
		$order->update_meta_data( SHIPPING_META_KEY, $snapshot );
		$saved = $order->save();
	} catch ( \Throwable $exception ) {
		return shipping_error( 'fandoogh_shipping_save_failed', __( 'ذخیره رهگیری ارسال انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	if ( false === $saved ) {
		return shipping_error( 'fandoogh_shipping_save_failed', __( 'ذخیره رهگیری ارسال انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	return shipping_normalize_snapshot( $snapshot );
}

/**
 * Read an order shipment snapshot.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function get_order_shipment( $request ) {
	$order_id = shipping_request_order_id( $request );
	if ( is_wp_error( $order_id ) ) {
		return $order_id;
	}

	$order = shipping_load_order( $order_id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return shipping_no_store_response( array( 'data' => shipping_serialize_snapshot( $order_id, shipping_read_snapshot( $order ) ) ) );
}

/**
 * Create or update an order shipment snapshot.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function update_order_shipment( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return shipping_private_error( $session );
	}

	$order_id = shipping_request_order_id( $request );
	if ( is_wp_error( $order_id ) ) {
		return $order_id;
	}

	$body = shipping_request_body( $request );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$order = shipping_load_order( $order_id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$snapshot = shipping_save_snapshot( $order, $body, $session['user']->ID );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}

	record_audit_event(
		'shipment_updated',
		$session['user']->ID,
		$session['id'],
		$session['device_label'],
		'order',
		$order_id,
		array(
			'outcome'  => 'saved',
			'order_id' => $order_id,
			'status'   => $snapshot['status'],
		)
	);
	if ( array_key_exists( 'items', $body ) ) {
		record_audit_event(
			'fulfillment_updated',
			$session['user']->ID,
			$session['id'],
			$session['device_label'],
			'order',
			$order_id,
			array( 'outcome' => 'items_saved', 'order_id' => $order_id, 'count' => count( $snapshot['items'] ) )
		);
	}

	return shipping_no_store_response( array( 'data' => shipping_serialize_snapshot( $order_id, $snapshot ) ) );
}
