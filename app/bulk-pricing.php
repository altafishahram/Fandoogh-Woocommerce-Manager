<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/products.php';

const BULK_PRICE_PARENT_LIMIT = 500;
const BULK_PRICE_TARGET_LIMIT = 3000;
const BULK_PRICE_PREVIEW_TTL  = 600;
const BULK_PRICE_SCHEDULE_HOOK = 'fandoogh_manager_bulk_price_scheduled';

/**
 * Register the two-step bulk-price workflow. Preview and execution share one
 * route, but execution requires the short-lived token returned by preview.
 *
 * @return void
 */
function register_bulk_pricing_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/products/bulk-price',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\bulk_price_products',
			'permission_callback' => __NAMESPACE__ . '\\products_write_permission',
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array<string, mixed>|\WP_Error
 */
function bulk_price_request_values( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return product_write_error( 'fandoogh_bulk_price_json_required', __( 'بدنهٔ درخواست افزایش گروهی باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_body', __( 'بدنهٔ درخواست افزایش گروهی معتبر نیست.', 'fandoogh-manager' ) );
	}

	$allowed = array( 'selection_method', 'category_ids', 'product_ids', 'include_children', 'apply_to_variations', 'adjustment_type', 'amount', 'price_overrides', 'schedule_mode', 'scheduled_at', 'execute', 'confirmation_token' );
	if ( array_diff( array_keys( $body ), $allowed ) ) {
		return product_write_error( 'fandoogh_bulk_price_unknown_field', __( 'یکی از فیلدهای افزایش گروهی در قرارداد مجاز نیست.', 'fandoogh-manager' ) );
	}

	$selection_method = isset( $body['selection_method'] ) && is_scalar( $body['selection_method'] ) ? sanitize_key( (string) $body['selection_method'] ) : 'category';
	if ( ! in_array( $selection_method, array( 'category', 'product' ), true ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_selection', __( 'روش انتخاب محصولات معتبر نیست.', 'fandoogh-manager' ) );
	}

	$category_ids = isset( $body['category_ids'] ) ? product_write_id_list( $body['category_ids'], 'category_ids', 30 ) : array();
	if ( is_wp_error( $category_ids ) ) {
		return $category_ids;
	}
	$product_ids = isset( $body['product_ids'] ) ? product_write_id_list( $body['product_ids'], 'product_ids', 100 ) : array();
	if ( is_wp_error( $product_ids ) ) {
		return $product_ids;
	}
	if ( 'category' === $selection_method && empty( $category_ids ) ) {
		return product_write_error( 'fandoogh_bulk_price_category_required', __( 'حداقل یک دسته‌بندی معتبر انتخاب کنید.', 'fandoogh-manager' ) );
	}
	if ( 'product' === $selection_method && empty( $product_ids ) ) {
		return product_write_error( 'fandoogh_bulk_price_product_required', __( 'حداقل یک محصول معتبر انتخاب کنید.', 'fandoogh-manager' ) );
	}

	$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );
	$product_ids  = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	foreach ( $category_ids as $category_id ) {
		$term = get_term( $category_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return product_write_error( 'fandoogh_bulk_price_invalid_category', __( 'یکی از دسته‌بندی‌های انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ) );
		}
	}

	$include_children = isset( $body['include_children'] ) ? product_write_boolean( $body['include_children'], 'include_children' ) : true;
	if ( is_wp_error( $include_children ) ) {
		return $include_children;
	}
	$apply_to_variations = isset( $body['apply_to_variations'] ) ? product_write_boolean( $body['apply_to_variations'], 'apply_to_variations' ) : true;
	if ( is_wp_error( $apply_to_variations ) ) {
		return $apply_to_variations;
	}

	$type = isset( $body['adjustment_type'] ) && is_scalar( $body['adjustment_type'] ) ? sanitize_key( (string) $body['adjustment_type'] ) : '';
	if ( ! in_array( $type, array( 'percent', 'fixed', 'manual' ), true ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_type', __( 'نوع تغییر باید درصدی، مبلغ ثابت یا قیمت‌گذاری دستی باشد.', 'fandoogh-manager' ) );
	}

	$amount = 'manual' === $type ? '0' : ( isset( $body['amount'] ) && is_scalar( $body['amount'] ) && ! is_bool( $body['amount'] ) ? trim( (string) $body['amount'] ) : '' );
	if ( 'manual' !== $type && ( ! preg_match( '/^-?(?:0\.[0-9]{1,4}|[1-9][0-9]{0,11}(?:\.[0-9]{1,4})?)$/D', $amount ) || 0.0 === (float) $amount ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_amount', __( 'مقدار تغییر باید عددی غیرصفر با حداکثر چهار رقم اعشار باشد.', 'fandoogh-manager' ) );
	}
	$absolute_amount = ltrim( $amount, '-' );
	if ( 'percent' === $type && bulk_price_decimal_is_greater_than( $absolute_amount, '1000' ) ) {
		return product_write_error( 'fandoogh_bulk_price_percent_too_large', __( 'درصد تغییر نمی‌تواند بیشتر از ۱۰۰۰ درصد باشد.', 'fandoogh-manager' ) );
	}

	$price_overrides = array();
	$raw_overrides = isset( $body['price_overrides'] ) ? $body['price_overrides'] : array();
	if ( ! is_array( $raw_overrides ) || count( $raw_overrides ) > 100 ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_overrides', __( 'فهرست قیمت‌های دستی معتبر نیست.', 'fandoogh-manager' ) );
	}
	foreach ( $raw_overrides as $raw_id => $raw_price ) {
		$product_id = absint( $raw_id );
		$price = is_scalar( $raw_price ) && ! is_bool( $raw_price ) ? trim( (string) $raw_price ) : '';
		if ( ! $product_id || ! in_array( $product_id, $product_ids, true ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/D', $price ) ) {
			return product_write_error( 'fandoogh_bulk_price_invalid_overrides', __( 'یکی از قیمت‌های دستی معتبر نیست.', 'fandoogh-manager' ) );
		}
		$price_overrides[ $product_id ] = $price;
	}
	ksort( $price_overrides, SORT_NUMERIC );
	if ( 'manual' === $type && ( 'product' !== $selection_method || empty( $price_overrides ) ) ) {
		return product_write_error( 'fandoogh_bulk_price_manual_price_required', __( 'برای قیمت‌گذاری دستی، حداقل قیمت جدید یک محصول را وارد کنید.', 'fandoogh-manager' ) );
	}

	$schedule_mode = isset( $body['schedule_mode'] ) && is_scalar( $body['schedule_mode'] ) ? sanitize_key( (string) $body['schedule_mode'] ) : 'now';
	if ( ! in_array( $schedule_mode, array( 'now', 'later' ), true ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_schedule', __( 'روش زمان‌بندی معتبر نیست.', 'fandoogh-manager' ) );
	}
	$scheduled_at = '';
	$scheduled_timestamp = 0;
	if ( 'later' === $schedule_mode ) {
		$scheduled_at = isset( $body['scheduled_at'] ) && is_scalar( $body['scheduled_at'] ) ? trim( (string) $body['scheduled_at'] ) : '';
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $scheduled_at, $timezone );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return product_write_error( 'fandoogh_bulk_price_invalid_schedule', __( 'تاریخ و ساعت اجرای تغییر معتبر نیست.', 'fandoogh-manager' ) );
		}
		$scheduled_timestamp = $date->getTimestamp();
		if ( $scheduled_timestamp <= time() || $scheduled_timestamp > time() + YEAR_IN_SECONDS ) {
			return product_write_error( 'fandoogh_bulk_price_invalid_schedule', __( 'زمان اجرا باید در آینده و حداکثر تا یک سال بعد باشد.', 'fandoogh-manager' ) );
		}
		$scheduled_at = $date->format( 'Y-m-d\TH:i' );
	}

	$execute = isset( $body['execute'] ) ? product_write_boolean( $body['execute'], 'execute' ) : false;
	if ( is_wp_error( $execute ) ) {
		return $execute;
	}

	return array(
		'selection_method'  => $selection_method,
		'category_ids'      => $category_ids,
		'product_ids'       => $product_ids,
		'include_children'  => (bool) $include_children,
		'apply_to_variations'=> (bool) $apply_to_variations,
		'adjustment_type'   => $type,
		'amount'            => $amount,
		'price_overrides'   => $price_overrides,
		'schedule_mode'     => $schedule_mode,
		'scheduled_at'      => $scheduled_at,
		'scheduled_timestamp'=> $scheduled_timestamp,
		'execute'           => (bool) $execute,
		'confirmation_token'=> isset( $body['confirmation_token'] ) && is_scalar( $body['confirmation_token'] ) ? sanitize_text_field( (string) $body['confirmation_token'] ) : '',
	);
}

/**
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @param int                  $expires Unix timestamp.
 * @param string               $plan_hash Preview product/price fingerprint.
 * @return string
 */
function bulk_price_confirmation_signature( $values, $session_id, $expires, $plan_hash = '' ) {
	$canonical = array(
		'selection_method' => isset( $values['selection_method'] ) ? (string) $values['selection_method'] : 'category',
		'category_ids'     => array_values( array_map( 'absint', $values['category_ids'] ) ),
		'product_ids'      => array_values( array_map( 'absint', isset( $values['product_ids'] ) ? $values['product_ids'] : array() ) ),
		'include_children' => ! empty( $values['include_children'] ),
		'apply_to_variations' => ! isset( $values['apply_to_variations'] ) || ! empty( $values['apply_to_variations'] ),
		'adjustment_type'  => (string) $values['adjustment_type'],
		'amount'           => (string) $values['amount'],
		'price_overrides'  => isset( $values['price_overrides'] ) ? (array) $values['price_overrides'] : array(),
		'schedule_mode'    => isset( $values['schedule_mode'] ) ? (string) $values['schedule_mode'] : 'now',
		'scheduled_at'     => isset( $values['scheduled_at'] ) ? (string) $values['scheduled_at'] : '',
		'plan_hash'        => (string) $plan_hash,
	);
	return hash_hmac( 'sha256', wp_json_encode( $canonical ) . '|' . absint( $session_id ) . '|' . absint( $expires ), wp_salt( 'nonce' ) );
}

/**
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @param string               $plan_hash Preview product/price fingerprint.
 * @return string
 */
function bulk_price_confirmation_token( $values, $session_id, $plan_hash = '' ) {
	$expires   = time() + BULK_PRICE_PREVIEW_TTL;
	$signature = bulk_price_confirmation_signature( $values, $session_id, $expires, $plan_hash );
	$token     = $expires . '.' . $signature;
	set_transient(
		'fandoogh_bulk_price_' . hash( 'sha256', $token ),
		array(
			'session_id' => absint( $session_id ),
			'plan_hash'  => (string) $plan_hash,
		),
		BULK_PRICE_PREVIEW_TTL
	);
	return $token;
}

/**
 * @param string               $token Candidate token.
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @param string               $plan_hash Current product/price fingerprint.
 * @return bool
 */
function bulk_price_confirmation_is_valid( $token, $values, $session_id, $plan_hash = '' ) {
	if ( ! preg_match( '/^(\d{10})\.([a-f0-9]{64})$/D', (string) $token, $matches ) ) {
		return false;
	}
	$expires = absint( $matches[1] );
	if ( $expires < time() || $expires > time() + BULK_PRICE_PREVIEW_TTL + 30 ) {
		return false;
	}
	if ( ! hash_equals( bulk_price_confirmation_signature( $values, $session_id, $expires, $plan_hash ), $matches[2] ) ) {
		return false;
	}
	$token_key = 'fandoogh_bulk_price_' . hash( 'sha256', (string) $token );
	$stored    = get_transient( $token_key );
	if ( ! is_array( $stored ) || absint( $stored['session_id'] ) !== absint( $session_id ) || ! hash_equals( (string) $stored['plan_hash'], (string) $plan_hash ) ) {
		return false;
	}

	// Claim the token with add_option() before deleting the transient. The
	// transient get/delete pair is not atomic, while add_option() gives
	// concurrent executions a single winner. The claim is intentionally kept
	// if transient deletion fails so a retry can never re-run the mutation.
	$claim_key = 'fandoogh_bulk_price_claim_' . hash( 'sha256', (string) $token );
	if ( ! add_option( $claim_key, array( 'created_at' => time(), 'session_id' => absint( $session_id ) ), '', 'no' ) ) {
		return false;
	}
	delete_transient( $token_key );
	return true;
}

/**
 * @param array<int, int> $selected_ids Selected category IDs.
 * @param bool            $include_children Include descendants.
 * @return array<int, int>|\WP_Error
 */
function bulk_price_resolve_categories( $selected_ids, $include_children ) {
	$resolved = array_values( array_unique( array_map( 'absint', $selected_ids ) ) );
	if ( $include_children ) {
		foreach ( $selected_ids as $category_id ) {
			$children = get_term_children( absint( $category_id ), 'product_cat' );
			if ( is_wp_error( $children ) ) {
				return product_write_error( 'fandoogh_bulk_price_category_tree_failed', __( 'خواندن زیردسته‌ها انجام نشد.', 'fandoogh-manager' ), 500 );
			}
			$resolved = array_merge( $resolved, array_map( 'absint', $children ) );
		}
	}
	$resolved = array_values( array_unique( array_filter( $resolved ) ) );
	if ( count( $resolved ) > 500 ) {
		return product_write_error( 'fandoogh_bulk_price_too_many_categories', __( 'تعداد دسته‌ها و زیردسته‌های انتخاب‌شده بیشتر از حد یک عملیات است.', 'fandoogh-manager' ), 413 );
	}
	return $resolved;
}

/**
 * @param array<int, int> $category_ids Resolved category IDs.
 * @param int             $user_id WordPress user ID.
 * @return array<int, int>|\WP_Error
 */
function bulk_price_parent_ids( $category_ids, $user_id ) {
	$slugs = array();
	foreach ( $category_ids as $category_id ) {
		$term = get_term( absint( $category_id ), 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$slugs[] = sanitize_title( $term->slug );
		}
	}
	if ( empty( $slugs ) ) {
		return array();
	}

	$ids = compose_product_repository()->query(
		array(
			'limit'    => BULK_PRICE_PARENT_LIMIT + 1,
			'return'   => 'ids',
			'status'   => readable_product_statuses( $user_id ),
			'type'     => array( 'simple', 'variable' ),
			'category' => array_values( array_unique( $slugs ) ),
			'orderby'  => 'ID',
			'order'    => 'ASC',
		)
	);
	$ids = is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
	if ( count( $ids ) > BULK_PRICE_PARENT_LIMIT ) {
		return product_write_error( 'fandoogh_bulk_price_parent_limit', sprintf( __( 'بیش از %d محصول منطبق است؛ دسته‌های محدودتری انتخاب کنید.', 'fandoogh-manager' ), BULK_PRICE_PARENT_LIMIT ), 413 );
	}
	return $ids;
}

/**
 * Validate an explicit product selection without broadening it through a query.
 *
 * @param array<int, int> $product_ids Selected product IDs.
 * @param int             $user_id WordPress user ID.
 * @return array<int, int>|\WP_Error
 */
function bulk_price_selected_product_ids( $product_ids, $user_id ) {
	$repository = compose_product_repository();
	$selected = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	foreach ( $selected as $product_id ) {
		$product = $repository->findById( $product_id );
		if ( ! $product || 'trash' === $product->get_status() ) {
			return product_write_error( 'fandoogh_bulk_price_invalid_product', __( 'یکی از محصولات انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ) );
		}
		if ( ! product_status_is_readable( $user_id, $product->get_status() ) || ! product_user_can_edit_existing( $user_id, $product_id ) ) {
			return product_write_error( 'fandoogh_bulk_price_product_forbidden', __( 'کاربر اجازهٔ ویرایش یکی از محصولات انتخاب‌شده را ندارد.', 'fandoogh-manager' ), 403 );
		}
	}
	return $selected;
}

/**
 * Normalize a non-negative decimal without converting it to a float.
 *
 * @param string $value Decimal value.
 * @return string|false
 */
function bulk_price_decimal_normalize( $value ) {
	$value = trim( (string) $value );
	if ( ! preg_match( '/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,12}))?$/D', $value, $matches ) ) {
		return false;
	}

	$integer  = ltrim( $matches[1], '0' );
	$integer  = '' === $integer ? '0' : $integer;
	$fraction = isset( $matches[2] ) ? rtrim( $matches[2], '0' ) : '';
	return '' === $fraction ? $integer : $integer . '.' . $fraction;
}

/**
 * @param string $left  Non-negative integer string.
 * @param string $right Non-negative integer string.
 * @return string
 */
function bulk_price_decimal_integer_add( $left, $right ) {
	$left  = strrev( ltrim( (string) $left, '0' ) ?: '0' );
	$right = strrev( ltrim( (string) $right, '0' ) ?: '0' );
	$length = max( strlen( $left ), strlen( $right ) );
	$carry  = 0;
	$result = '';
	for ( $index = 0; $index < $length; $index++ ) {
		$sum     = ( isset( $left[ $index ] ) ? (int) $left[ $index ] : 0 ) + ( isset( $right[ $index ] ) ? (int) $right[ $index ] : 0 ) + $carry;
		$result .= (string) ( $sum % 10 );
		$carry   = intdiv( $sum, 10 );
	}
	if ( $carry ) {
		$result .= (string) $carry;
	}
	return strrev( $result );
}

/**
 * @param string $left  Non-negative integer string.
 * @param string $right Non-negative integer string.
 * @return string
 */
function bulk_price_decimal_integer_multiply( $left, $right ) {
	$left  = ltrim( (string) $left, '0' ) ?: '0';
	$right = ltrim( (string) $right, '0' ) ?: '0';
	if ( '0' === $left || '0' === $right ) {
		return '0';
	}

	$digits = array_fill( 0, strlen( $left ) + strlen( $right ), 0 );
	for ( $left_index = strlen( $left ) - 1; $left_index >= 0; $left_index-- ) {
		for ( $right_index = strlen( $right ) - 1; $right_index >= 0; $right_index-- ) {
			$digits[ $left_index + $right_index + 1 ] += (int) $left[ $left_index ] * (int) $right[ $right_index ];
		}
	}
	for ( $index = count( $digits ) - 1; $index > 0; $index-- ) {
		$digits[ $index - 1 ] += intdiv( $digits[ $index ], 10 );
		$digits[ $index ]     %= 10;
	}
	return ltrim( implode( '', $digits ), '0' ) ?: '0';
}

/**
 * @param string $value Decimal value.
 * @return array{0: string, 1: string}
 */
function bulk_price_decimal_parts( $value ) {
	$parts = explode( '.', (string) $value, 2 );
	return array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
}

/**
 * @param string $integer  Non-negative integer string.
 * @param string $fraction Fraction without a decimal point.
 * @return string
 */
function bulk_price_decimal_from_parts( $integer, $fraction ) {
	$integer  = ltrim( (string) $integer, '0' ) ?: '0';
	$fraction = rtrim( (string) $fraction, '0' );
	return '' === $fraction ? $integer : $integer . '.' . $fraction;
}

/**
 * @param string $left  Decimal value.
 * @param string $right Decimal value.
 * @return string
 */
function bulk_price_decimal_add( $left, $right ) {
	list( $left_integer, $left_fraction )   = bulk_price_decimal_parts( $left );
	list( $right_integer, $right_fraction ) = bulk_price_decimal_parts( $right );
	$scale = max( strlen( $left_fraction ), strlen( $right_fraction ) );
	$left_scaled  = $left_integer . str_pad( $left_fraction, $scale, '0' );
	$right_scaled = $right_integer . str_pad( $right_fraction, $scale, '0' );
	$sum = bulk_price_decimal_integer_add( $left_scaled, $right_scaled );
	if ( 0 === $scale ) {
		return $sum;
	}
	return bulk_price_decimal_from_parts( substr( $sum, 0, -$scale ) ?: '0', str_pad( substr( $sum, -$scale ), $scale, '0', STR_PAD_LEFT ) );
}

/**
 * @param string $left  Decimal value.
 * @param string $right Decimal value.
 * @return string
 */
function bulk_price_decimal_multiply( $left, $right ) {
	list( $left_integer, $left_fraction )   = bulk_price_decimal_parts( $left );
	list( $right_integer, $right_fraction ) = bulk_price_decimal_parts( $right );
	$product = bulk_price_decimal_integer_multiply( $left_integer . $left_fraction, $right_integer . $right_fraction );
	$scale   = strlen( $left_fraction ) + strlen( $right_fraction );
	if ( 0 === $scale ) {
		return $product;
	}
	return bulk_price_decimal_from_parts( substr( $product, 0, -$scale ) ?: '0', str_pad( substr( $product, -$scale ), $scale, '0', STR_PAD_LEFT ) );
}

/**
 * @param string $value Decimal value.
 * @param int    $places Decimal places to shift.
 * @return string
 */
function bulk_price_decimal_shift_right( $value, $places ) {
	list( $integer, $fraction ) = bulk_price_decimal_parts( $value );
	$digits = $integer . $fraction;
	$scale = strlen( $fraction ) + $places;
	if ( strlen( $digits ) <= $scale ) {
		return bulk_price_decimal_from_parts( '0', str_repeat( '0', $scale - strlen( $digits ) ) . $digits );
	}
	return bulk_price_decimal_from_parts( substr( $digits, 0, -$scale ), substr( $digits, -$scale ) );
}

/**
 * Round half up without using floating point arithmetic.
 *
 * @param string $value    Decimal value.
 * @param int    $decimals Target decimal places.
 * @return string
 */
function bulk_price_decimal_round( $value, $decimals ) {
	list( $integer, $fraction ) = bulk_price_decimal_parts( $value );
	$decimals = max( 0, absint( $decimals ) );
	if ( strlen( $fraction ) > $decimals ) {
		$round_digit = (int) $fraction[ $decimals ];
		$fraction    = substr( $fraction, 0, $decimals );
		if ( $round_digit >= 5 ) {
			$scaled = bulk_price_decimal_integer_add( $integer . $fraction, '1' );
			if ( 0 === $decimals ) {
				return $scaled;
			}
			$integer  = substr( $scaled, 0, -$decimals ) ?: '0';
			$fraction = substr( $scaled, -$decimals );
		}
	}
	return bulk_price_decimal_from_parts( $integer, str_pad( $fraction, $decimals, '0' ) );
}

/**
 * @param string $value Decimal value.
 * @param string $limit Decimal limit.
 * @return bool
 */
function bulk_price_decimal_is_greater_than( $value, $limit ) {
	$value = bulk_price_decimal_normalize( $value );
	$limit = bulk_price_decimal_normalize( $limit );
	if ( false === $value || false === $limit ) {
		return true;
	}
	list( $value_integer, $value_fraction ) = bulk_price_decimal_parts( $value );
	list( $limit_integer, $limit_fraction ) = bulk_price_decimal_parts( $limit );
	if ( strlen( $value_integer ) !== strlen( $limit_integer ) ) {
		return strlen( $value_integer ) > strlen( $limit_integer );
	}
	if ( $value_integer !== $limit_integer ) {
		return strcmp( $value_integer, $limit_integer ) > 0;
	}
	return rtrim( $value_fraction, '0' ) !== '' && strcmp( str_pad( $value_fraction, max( strlen( $value_fraction ), strlen( $limit_fraction ) ), '0' ), str_pad( $limit_fraction, max( strlen( $value_fraction ), strlen( $limit_fraction ) ), '0' ) ) > 0;
}

/**
 * Compare two normalized non-negative decimal values.
 *
 * @param string $left  Decimal value.
 * @param string $right Decimal value.
 * @return int -1, 0 or 1.
 */
function bulk_price_decimal_compare( $left, $right ) {
	$left  = bulk_price_decimal_normalize( $left );
	$right = bulk_price_decimal_normalize( $right );
	if ( false === $left || false === $right ) {
		return 0;
	}
	list( $left_integer, $left_fraction )   = bulk_price_decimal_parts( $left );
	list( $right_integer, $right_fraction ) = bulk_price_decimal_parts( $right );
	if ( strlen( $left_integer ) !== strlen( $right_integer ) ) {
		return strlen( $left_integer ) > strlen( $right_integer ) ? 1 : -1;
	}
	$integer_compare = strcmp( $left_integer, $right_integer );
	if ( 0 !== $integer_compare ) {
		return $integer_compare > 0 ? 1 : -1;
	}
	$scale = max( strlen( $left_fraction ), strlen( $right_fraction ) );
	$fraction_compare = strcmp( str_pad( $left_fraction, $scale, '0' ), str_pad( $right_fraction, $scale, '0' ) );
	return 0 === $fraction_compare ? 0 : ( $fraction_compare > 0 ? 1 : -1 );
}

/**
 * Subtract a smaller non-negative decimal from a larger one.
 *
 * @param string $left  Decimal value.
 * @param string $right Decimal value.
 * @return string
 */
function bulk_price_decimal_subtract( $left, $right ) {
	list( $left_integer, $left_fraction )   = bulk_price_decimal_parts( bulk_price_decimal_normalize( $left ) );
	list( $right_integer, $right_fraction ) = bulk_price_decimal_parts( bulk_price_decimal_normalize( $right ) );
	$scale = max( strlen( $left_fraction ), strlen( $right_fraction ) );
	$left_digits  = ltrim( $left_integer . str_pad( $left_fraction, $scale, '0' ), '0' ) ?: '0';
	$right_digits = str_pad( ltrim( $right_integer . str_pad( $right_fraction, $scale, '0' ), '0' ) ?: '0', strlen( $left_digits ), '0', STR_PAD_LEFT );
	$left_digits  = str_pad( $left_digits, strlen( $right_digits ), '0', STR_PAD_LEFT );
	$borrow = 0;
	$result = '';
	for ( $index = strlen( $left_digits ) - 1; $index >= 0; $index-- ) {
		$digit = (int) $left_digits[ $index ] - $borrow - (int) $right_digits[ $index ];
		if ( $digit < 0 ) {
			$digit += 10;
			$borrow = 1;
		} else {
			$borrow = 0;
		}
		$result = (string) $digit . $result;
	}
	$result = ltrim( $result, '0' ) ?: '0';
	if ( 0 === $scale ) {
		return $result;
	}
	$result = str_pad( $result, $scale + 1, '0', STR_PAD_LEFT );
	return bulk_price_decimal_from_parts( substr( $result, 0, -$scale ), substr( $result, -$scale ) );
}

/**
 * @param string $current Current price.
 * @param string $type Adjustment type.
 * @param string $amount Adjustment amount.
 * @return string|\WP_Error
 */
function bulk_price_calculate( $current, $type, $amount ) {
	$current_number = bulk_price_decimal_normalize( $current );
	$decrease       = 0 === strpos( (string) $amount, '-' );
	$amount_number  = bulk_price_decimal_normalize( ltrim( (string) $amount, '-' ) );
	if ( false === $current_number || false === $amount_number ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_current', __( 'یکی از قیمت‌های فعلی معتبر نیست.', 'fandoogh-manager' ) );
	}
	if ( bulk_price_decimal_is_greater_than( $current_number, '999999999999' ) ) {
		return product_write_error( 'fandoogh_bulk_price_result_too_large', __( 'نتیجهٔ یکی از قیمت‌ها خارج از محدودهٔ مجاز است.', 'fandoogh-manager' ) );
	}
	$delta = 'percent' === $type
		? bulk_price_decimal_shift_right( bulk_price_decimal_multiply( $current_number, $amount_number ), 2 )
		: $amount_number;
	$new_number = $decrease
		? ( bulk_price_decimal_compare( $current_number, $delta ) <= 0 ? '0' : bulk_price_decimal_subtract( $current_number, $delta ) )
		: bulk_price_decimal_add( $current_number, $delta );
	$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0;
	$new_number = bulk_price_decimal_round( $new_number, $decimals );
	if ( bulk_price_decimal_is_greater_than( $new_number, '999999999999' ) ) {
		return product_write_error( 'fandoogh_bulk_price_result_too_large', __( 'نتیجهٔ یکی از قیمت‌ها خارج از محدودهٔ مجاز است.', 'fandoogh-manager' ) );
	}
	return wc_format_decimal( $new_number, $decimals );
}

/**
 * @param array<int, int>      $parent_ids Parent product IDs.
 * @param int                  $user_id WordPress user ID.
 * @param array<string, mixed> $values Validated values.
 * @return array<string, mixed>|\WP_Error
 */
function bulk_price_build_plan( $parent_ids, $user_id, $values ) {
	$repository = compose_product_repository();
	$changes  = array();
	$sample   = array();
	$snapshot = array();
	$skipped  = 0;

	foreach ( $parent_ids as $parent_id ) {
		if ( ! product_user_can_edit_existing( $user_id, $parent_id ) ) {
			return product_write_error( 'fandoogh_bulk_price_product_forbidden', __( 'کاربر اجازهٔ ویرایش یکی از محصولات منطبق را ندارد.', 'fandoogh-manager' ), 403 );
		}
		$parent = $repository->findById( $parent_id );
		if ( ! $parent ) {
			continue;
		}
		$targets = $parent->is_type( 'variable' ) && ! empty( $values['apply_to_variations'] ) ? array_map( 'absint', (array) $parent->get_children() ) : array( $parent_id );
		foreach ( $targets as $target_id ) {
			$target_id = absint( $target_id );
			if ( 0 === $target_id ) {
				continue;
			}
			if ( count( $snapshot ) >= BULK_PRICE_TARGET_LIMIT ) {
				return product_write_error( 'fandoogh_bulk_price_target_limit', sprintf( __( 'بیش از %d قیمت منطبق است؛ دسته‌های محدودتری انتخاب کنید.', 'fandoogh-manager' ), BULK_PRICE_TARGET_LIMIT ), 413 );
			}
			$product = $target_id === $parent_id ? $parent : $repository->findById( $target_id );
			if ( ! $product || 'trash' === $product->get_status() ) {
				continue;
			}
			if ( ! product_user_can_edit_existing( $user_id, $target_id ) ) {
				return product_write_error( 'fandoogh_bulk_price_product_forbidden', __( 'کاربر اجازهٔ ویرایش یکی از قیمت‌های منطبق را ندارد.', 'fandoogh-manager' ), 403 );
			}
			$regular = (string) $product->get_regular_price();
			$sale    = (string) $product->get_sale_price();
			$snapshot[] = array(
				'id'            => $target_id,
				'parent_id'     => absint( $parent_id ),
				'status'        => (string) $product->get_status(),
				'regular_price' => '' === $regular ? '' : bulk_price_decimal_normalize( $regular ),
				'sale_price'    => '' === $sale ? '' : bulk_price_decimal_normalize( $sale ),
			);
			if ( '' === $regular ) {
				++$skipped;
				continue;
			}
			$override = isset( $values['price_overrides'][ $target_id ] ) ? (string) $values['price_overrides'][ $target_id ] : '';
			if ( '' === $override && 'product' === $values['selection_method'] && isset( $values['price_overrides'][ $parent_id ] ) ) {
				$override = (string) $values['price_overrides'][ $parent_id ];
			}
			if ( 'manual' === $values['adjustment_type'] && '' === $override ) {
				++$skipped;
				continue;
			}
			$new_regular = '' !== $override ? wc_format_decimal( $override, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0 ) : bulk_price_calculate( $regular, $values['adjustment_type'], $values['amount'] );
			if ( is_wp_error( $new_regular ) ) {
				return $new_regular;
			}
			$new_sale = '';
			if ( '' !== $sale ) {
				$new_sale = '' !== $override ? $new_regular : bulk_price_calculate( $sale, $values['adjustment_type'], $values['amount'] );
				if ( is_wp_error( $new_sale ) ) {
					return $new_sale;
				}
			}
			$changes[] = array(
				'product'       => $product,
				'parent_id'     => $parent_id,
				'old_regular'   => $regular,
				'old_sale'      => $sale,
				'regular_price' => $new_regular,
				'sale_price'    => $new_sale,
			);
			if ( count( $sample ) < 8 ) {
				$sample[] = array(
					'id'               => absint( $product->get_id() ),
					'name'             => sanitize_text_field( $product->get_name() ),
					'old_regular_price'=> $regular,
					'new_regular_price'=> $new_regular,
					'old_sale_price'   => $sale,
					'new_sale_price'   => $new_sale,
				);
			}
		}
	}

	return array( 'changes' => $changes, 'sample' => $sample, 'skipped' => $skipped, 'snapshot' => $snapshot );
}

/**
 * @param array<string, mixed> $plan Product/price snapshot.
 * @return string
 */
function bulk_price_plan_fingerprint( $plan ) {
	return hash( 'sha256', (string) wp_json_encode( array_values( $plan['snapshot'] ) ) );
}

/**
 * @param string $current  Current product price.
 * @param string $expected Preview product price.
 * @return bool
 */
function bulk_price_snapshot_matches( $current, $expected ) {
	if ( '' === (string) $current || '' === (string) $expected ) {
		return '' === (string) $current && '' === (string) $expected;
	}
	$current  = bulk_price_decimal_normalize( $current );
	$expected = bulk_price_decimal_normalize( $expected );
	return false !== $current && false !== $expected && $current === $expected;
}

/**
 * Apply a previously built plan after rechecking its price snapshot.
 *
 * @param array<string, mixed> $plan Bulk-price plan.
 * @return array<string, mixed>
 */
function bulk_price_execute_plan( $plan ) {
	$changed = 0;
	$failed  = array();
	$stale   = array();
	$parents_to_sync = array();
	$repository = compose_product_repository();
	foreach ( $plan['changes'] as $change ) {
		$product_id = absint( $change['product']->get_id() );
		try {
			$product = $repository->findById( $product_id );
			if ( ! $product || 'trash' === $product->get_status() || ! bulk_price_snapshot_matches( (string) $product->get_regular_price(), $change['old_regular'] ) || ! bulk_price_snapshot_matches( (string) $product->get_sale_price(), $change['old_sale'] ) ) {
				$stale[] = $product_id;
				continue;
			}
			$product->set_regular_price( $change['regular_price'] );
			if ( '' !== (string) $change['old_sale'] ) {
				$product->set_sale_price( $change['sale_price'] );
			}
			$product->save();
			++$changed;
			if ( absint( $change['parent_id'] ) !== absint( $product->get_id() ) ) {
				$parents_to_sync[] = absint( $change['parent_id'] );
			}
		} catch ( \Throwable $exception ) {
			$failed[] = $product_id;
		}
	}
	$sync_failed = array();
	foreach ( array_values( array_unique( $parents_to_sync ) ) as $parent_id ) {
		try {
			if ( class_exists( '\\WC_Product_Variable' ) ) {
				\WC_Product_Variable::sync( $parent_id );
			}
			wc_delete_product_transients( $parent_id );
		} catch ( \Throwable $exception ) {
			$sync_failed[] = absint( $parent_id );
		}
	}
	return array(
		'changed' => $changed,
		'failed' => $failed,
		'stale' => $stale,
		'sync_failed' => $sync_failed,
	);
}

/**
 * Persist and register a one-time WordPress Cron job.
 *
 * @param array<string, mixed> $values Validated request values.
 * @param array<string, mixed> $session Authenticated session.
 * @return string|\WP_Error Job ID.
 */
function bulk_price_schedule_job( $values, $session ) {
	$job_id = wp_generate_uuid4();
	$option_key = 'fandoogh_bulk_price_job_' . str_replace( '-', '', $job_id );
	$job = array(
		'values'       => $values,
		'user_id'      => absint( $session['user']->ID ),
		'session_id'   => absint( $session['id'] ),
		'device_label' => sanitize_text_field( (string) $session['device_label'] ),
		'created_at'   => time(),
	);
	unset( $job['values']['confirmation_token'], $job['values']['execute'], $job['values']['scheduled_timestamp'] );
	if ( ! add_option( $option_key, $job, '', 'no' ) ) {
		return product_write_error( 'fandoogh_bulk_price_schedule_failed', __( 'ثبت زمان‌بندی تغییر قیمت انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	if ( ! wp_schedule_single_event( absint( $values['scheduled_timestamp'] ), BULK_PRICE_SCHEDULE_HOOK, array( $job_id ) ) ) {
		delete_option( $option_key );
		return product_write_error( 'fandoogh_bulk_price_schedule_failed', __( 'ثبت زمان‌بندی تغییر قیمت انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	return $job_id;
}

/**
 * Execute a scheduled bulk-price job once.
 *
 * @param string $job_id Stored job identifier.
 * @return void
 */
function run_scheduled_bulk_price_job( $job_id ) {
	$option_key = 'fandoogh_bulk_price_job_' . str_replace( '-', '', sanitize_text_field( (string) $job_id ) );
	$job = get_option( $option_key );
	if ( ! is_array( $job ) || empty( $job['values'] ) || empty( $job['user_id'] ) ) {
		return;
	}
	delete_option( $option_key );
	$user_id = absint( $job['user_id'] );
	if ( function_exists( 'wp_set_current_user' ) ) {
		wp_set_current_user( $user_id );
	}
	if ( ! products_write_available() || ! product_user_has_write_capability( $user_id ) ) {
		return;
	}
	$values = $job['values'];
	if ( 'product' === $values['selection_method'] ) {
		$parent_ids = bulk_price_selected_product_ids( $values['product_ids'], $user_id );
	} else {
		$category_ids = bulk_price_resolve_categories( $values['category_ids'], $values['include_children'] );
		$parent_ids = is_wp_error( $category_ids ) ? $category_ids : bulk_price_parent_ids( $category_ids, $user_id );
	}
	if ( is_wp_error( $parent_ids ) ) {
		return;
	}
	$plan = bulk_price_build_plan( $parent_ids, $user_id, $values );
	if ( is_wp_error( $plan ) ) {
		return;
	}
	$result = bulk_price_execute_plan( $plan );
	record_audit_event( 'product_bulk_price_updated', $user_id, absint( $job['session_id'] ), (string) $job['device_label'], 'product', isset( $parent_ids[0] ) ? absint( $parent_ids[0] ) : 0, array( 'count' => $result['changed'], 'attempted' => count( $plan['changes'] ), 'failed' => count( $result['failed'] ), 'stale' => count( $result['stale'] ), 'sync_failed' => count( $result['sync_failed'] ), 'status' => $values['adjustment_type'], 'amount' => $values['amount'], 'scheduled' => true ) );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function bulk_price_products( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! products_write_available() ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا CRUD محصول در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	$values  = bulk_price_request_values( $request );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	$category_ids = array();
	if ( 'product' === $values['selection_method'] ) {
		$parent_ids = bulk_price_selected_product_ids( $values['product_ids'], $user_id );
	} else {
		$category_ids = bulk_price_resolve_categories( $values['category_ids'], $values['include_children'] );
		if ( is_wp_error( $category_ids ) ) {
			return $category_ids;
		}
		$parent_ids = bulk_price_parent_ids( $category_ids, $user_id );
	}
	if ( is_wp_error( $parent_ids ) ) {
		return $parent_ids;
	}
	$plan = bulk_price_build_plan( $parent_ids, $user_id, $values );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$plan_hash = bulk_price_plan_fingerprint( $plan );
	if ( $values['execute'] && ! bulk_price_confirmation_is_valid( $values['confirmation_token'], $values, $session['id'], $plan_hash ) ) {
		return product_write_error( 'fandoogh_bulk_price_confirmation_required', __( 'پیش‌نمایش منقضی یا با تنظیمات فعلی ناسازگار است؛ دوباره پیش‌نمایش بگیرید.', 'fandoogh-manager' ), 409 );
	}

	$base = array(
		'matched_products' => count( $parent_ids ),
		'price_records'    => count( $plan['changes'] ),
		'skipped'          => absint( $plan['skipped'] ),
		'categories'       => count( $category_ids ),
		'selection_method' => $values['selection_method'],
		'sample'           => $plan['sample'],
	);
	if ( ! $values['execute'] ) {
		$base['mode']               = 'preview';
		$base['confirmation_token'] = bulk_price_confirmation_token( $values, $session['id'], $plan_hash );
		$base['expires_in']         = BULK_PRICE_PREVIEW_TTL;
		return product_no_store_response( rest_ensure_response( array( 'data' => $base ) ) );
	}
	if ( 'later' === $values['schedule_mode'] ) {
		$job_id = bulk_price_schedule_job( $values, $session );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		$base['mode'] = 'scheduled';
		$base['changed'] = 0;
		$base['failed'] = 0;
		$base['scheduled_at'] = $values['scheduled_at'];
		$base['job_id'] = $job_id;
		record_audit_event( 'product_bulk_price_scheduled', $session['user']->ID, $session['id'], $session['device_label'], 'product', isset( $parent_ids[0] ) ? absint( $parent_ids[0] ) : 0, array( 'attempted' => count( $plan['changes'] ), 'status' => $values['adjustment_type'], 'amount' => $values['amount'], 'scheduled_at' => $values['scheduled_at'] ) );
		return product_no_store_response( rest_ensure_response( array( 'data' => $base ) ) );
	}

	$result = bulk_price_execute_plan( $plan );
	$changed = $result['changed'];
	$failed = $result['failed'];
	$stale = $result['stale'];
	$sync_failed = $result['sync_failed'];

	$base['mode']       = 'executed';
	$base['changed']    = $changed;
	$base['failed']     = count( $failed );
	$base['failed_ids'] = array_slice( $failed, 0, 20 );
	$base['stale']      = count( $stale );
	$base['stale_ids']  = array_slice( $stale, 0, 20 );
	$base['sync_failed'] = count( $sync_failed );
	$base['sync_failed_parent_ids'] = array_slice( $sync_failed, 0, 20 );
	$resource_type = 'category' === $values['selection_method'] ? 'product_category' : 'product';
	$resource_id = 'category' === $values['selection_method'] ? ( isset( $values['category_ids'][0] ) ? $values['category_ids'][0] : 0 ) : ( isset( $values['product_ids'][0] ) ? $values['product_ids'][0] : 0 );
	record_audit_event( 'product_bulk_price_updated', $session['user']->ID, $session['id'], $session['device_label'], $resource_type, $resource_id, array( 'count' => $changed, 'attempted' => count( $plan['changes'] ), 'failed' => count( $failed ), 'stale' => count( $stale ), 'sync_failed' => count( $sync_failed ), 'status' => $values['adjustment_type'], 'amount' => $values['amount'] ) );
	$response = product_no_store_response( rest_ensure_response( array( 'data' => $base ) ) );
	if ( ! empty( $failed ) || ! empty( $stale ) || ! empty( $sync_failed ) ) {
		$response->set_status( 207 );
	}
	return $response;
}

add_action( BULK_PRICE_SCHEDULE_HOOK, __NAMESPACE__ . '\\run_scheduled_bulk_price_job', 10, 1 );
