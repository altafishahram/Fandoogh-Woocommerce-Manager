<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BULK_PRICE_PARENT_LIMIT = 500;
const BULK_PRICE_TARGET_LIMIT = 3000;
const BULK_PRICE_PREVIEW_TTL  = 600;

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

	$allowed = array( 'category_ids', 'include_children', 'adjustment_type', 'amount', 'execute', 'confirmation_token' );
	if ( array_diff( array_keys( $body ), $allowed ) ) {
		return product_write_error( 'fandoogh_bulk_price_unknown_field', __( 'یکی از فیلدهای افزایش گروهی در قرارداد مجاز نیست.', 'fandoogh-manager' ) );
	}

	$category_ids = isset( $body['category_ids'] ) ? product_write_id_list( $body['category_ids'], 'category_ids', 30 ) : array();
	if ( is_wp_error( $category_ids ) || empty( $category_ids ) ) {
		return product_write_error( 'fandoogh_bulk_price_category_required', __( 'حداقل یک دسته‌بندی معتبر انتخاب کنید.', 'fandoogh-manager' ) );
	}

	$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );
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

	$type = isset( $body['adjustment_type'] ) && is_scalar( $body['adjustment_type'] ) ? sanitize_key( (string) $body['adjustment_type'] ) : '';
	if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_type', __( 'نوع افزایش باید درصدی یا مبلغ ثابت باشد.', 'fandoogh-manager' ) );
	}

	$amount = isset( $body['amount'] ) && is_scalar( $body['amount'] ) && ! is_bool( $body['amount'] ) ? trim( (string) $body['amount'] ) : '';
	if ( ! preg_match( '/^(?:0\.[0-9]{1,4}|[1-9][0-9]{0,11}(?:\.[0-9]{1,4})?)$/D', $amount ) ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_amount', __( 'مقدار افزایش باید عددی مثبت با حداکثر چهار رقم اعشار باشد.', 'fandoogh-manager' ) );
	}
	if ( 'percent' === $type && (float) $amount > 1000 ) {
		return product_write_error( 'fandoogh_bulk_price_percent_too_large', __( 'درصد افزایش نمی‌تواند بیشتر از ۱۰۰۰ درصد باشد.', 'fandoogh-manager' ) );
	}

	$execute = isset( $body['execute'] ) ? product_write_boolean( $body['execute'], 'execute' ) : false;
	if ( is_wp_error( $execute ) ) {
		return $execute;
	}

	return array(
		'category_ids'      => $category_ids,
		'include_children'  => (bool) $include_children,
		'adjustment_type'   => $type,
		'amount'            => $amount,
		'execute'           => (bool) $execute,
		'confirmation_token'=> isset( $body['confirmation_token'] ) && is_scalar( $body['confirmation_token'] ) ? sanitize_text_field( (string) $body['confirmation_token'] ) : '',
	);
}

/**
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @param int                  $expires Unix timestamp.
 * @return string
 */
function bulk_price_confirmation_signature( $values, $session_id, $expires ) {
	$canonical = array(
		'category_ids'     => array_values( array_map( 'absint', $values['category_ids'] ) ),
		'include_children' => ! empty( $values['include_children'] ),
		'adjustment_type'  => (string) $values['adjustment_type'],
		'amount'           => (string) $values['amount'],
	);
	return hash_hmac( 'sha256', wp_json_encode( $canonical ) . '|' . absint( $session_id ) . '|' . absint( $expires ), wp_salt( 'nonce' ) );
}

/**
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @return string
 */
function bulk_price_confirmation_token( $values, $session_id ) {
	$expires   = time() + BULK_PRICE_PREVIEW_TTL;
	$signature = bulk_price_confirmation_signature( $values, $session_id, $expires );
	$token     = $expires . '.' . $signature;
	set_transient( 'fandoogh_bulk_price_' . hash( 'sha256', $token ), absint( $session_id ), BULK_PRICE_PREVIEW_TTL );
	return $token;
}

/**
 * @param string               $token Candidate token.
 * @param array<string, mixed> $values Validated values.
 * @param int                  $session_id Session ID.
 * @return bool
 */
function bulk_price_confirmation_is_valid( $token, $values, $session_id ) {
	if ( ! preg_match( '/^(\d{10})\.([a-f0-9]{64})$/D', (string) $token, $matches ) ) {
		return false;
	}
	$expires = absint( $matches[1] );
	if ( $expires < time() || $expires > time() + BULK_PRICE_PREVIEW_TTL + 30 ) {
		return false;
	}
	if ( ! hash_equals( bulk_price_confirmation_signature( $values, $session_id, $expires ), $matches[2] ) ) {
		return false;
	}

	// delete_transient() is the one-time consume step. A replay finds no token
	// and is rejected before any product query or mutation starts.
	return (bool) delete_transient( 'fandoogh_bulk_price_' . hash( 'sha256', (string) $token ) );
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

	$ids = wc_get_products(
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
 * @param string $current Current price.
 * @param string $type Adjustment type.
 * @param string $amount Adjustment amount.
 * @return string|\WP_Error
 */
function bulk_price_calculate( $current, $type, $amount ) {
	if ( '' === trim( (string) $current ) || ! is_numeric( $current ) || (float) $current < 0 ) {
		return product_write_error( 'fandoogh_bulk_price_invalid_current', __( 'یکی از قیمت‌های فعلی معتبر نیست.', 'fandoogh-manager' ) );
	}
	$current_number = (float) $current;
	$amount_number  = (float) $amount;
	$new_number     = 'percent' === $type ? $current_number * ( 1 + ( $amount_number / 100 ) ) : $current_number + $amount_number;
	if ( ! is_finite( $new_number ) || $new_number > 999999999999 ) {
		return product_write_error( 'fandoogh_bulk_price_result_too_large', __( 'نتیجهٔ یکی از قیمت‌ها خارج از محدودهٔ مجاز است.', 'fandoogh-manager' ) );
	}
	$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0;
	return wc_format_decimal( $new_number, $decimals );
}

/**
 * @param array<int, int>      $parent_ids Parent product IDs.
 * @param int                  $user_id WordPress user ID.
 * @param array<string, mixed> $values Validated values.
 * @return array<string, mixed>|\WP_Error
 */
function bulk_price_build_plan( $parent_ids, $user_id, $values ) {
	$changes = array();
	$sample  = array();
	$skipped = 0;

	foreach ( $parent_ids as $parent_id ) {
		if ( ! product_user_can_edit_existing( $user_id, $parent_id ) ) {
			return product_write_error( 'fandoogh_bulk_price_product_forbidden', __( 'کاربر اجازهٔ ویرایش یکی از محصولات منطبق را ندارد.', 'fandoogh-manager' ), 403 );
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent ) {
			continue;
		}
		$targets = $parent->is_type( 'variable' ) ? array_map( 'absint', (array) $parent->get_children() ) : array( $parent_id );
		foreach ( $targets as $target_id ) {
			if ( count( $changes ) >= BULK_PRICE_TARGET_LIMIT ) {
				return product_write_error( 'fandoogh_bulk_price_target_limit', sprintf( __( 'بیش از %d قیمت منطبق است؛ دسته‌های محدودتری انتخاب کنید.', 'fandoogh-manager' ), BULK_PRICE_TARGET_LIMIT ), 413 );
			}
			$product = $target_id === $parent_id ? $parent : wc_get_product( $target_id );
			if ( ! $product || 'trash' === $product->get_status() ) {
				continue;
			}
			$regular = (string) $product->get_regular_price();
			if ( '' === $regular ) {
				++$skipped;
				continue;
			}
			$new_regular = bulk_price_calculate( $regular, $values['adjustment_type'], $values['amount'] );
			if ( is_wp_error( $new_regular ) ) {
				return $new_regular;
			}
			$sale     = (string) $product->get_sale_price();
			$new_sale = '';
			if ( '' !== $sale ) {
				$new_sale = bulk_price_calculate( $sale, $values['adjustment_type'], $values['amount'] );
				if ( is_wp_error( $new_sale ) ) {
					return $new_sale;
				}
			}
			$changes[] = array(
				'product'       => $product,
				'parent_id'     => $parent_id,
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

	return array( 'changes' => $changes, 'sample' => $sample, 'skipped' => $skipped );
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
	if ( $values['execute'] && ! bulk_price_confirmation_is_valid( $values['confirmation_token'], $values, $session['id'] ) ) {
		return product_write_error( 'fandoogh_bulk_price_confirmation_required', __( 'پیش‌نمایش منقضی یا با تنظیمات فعلی ناسازگار است؛ دوباره پیش‌نمایش بگیرید.', 'fandoogh-manager' ), 409 );
	}

	$category_ids = bulk_price_resolve_categories( $values['category_ids'], $values['include_children'] );
	if ( is_wp_error( $category_ids ) ) {
		return $category_ids;
	}
	$parent_ids = bulk_price_parent_ids( $category_ids, $user_id );
	if ( is_wp_error( $parent_ids ) ) {
		return $parent_ids;
	}
	$plan = bulk_price_build_plan( $parent_ids, $user_id, $values );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}

	$base = array(
		'matched_products' => count( $parent_ids ),
		'price_records'    => count( $plan['changes'] ),
		'skipped'          => absint( $plan['skipped'] ),
		'categories'       => count( $category_ids ),
		'sample'           => $plan['sample'],
	);
	if ( ! $values['execute'] ) {
		$base['mode']               = 'preview';
		$base['confirmation_token'] = bulk_price_confirmation_token( $values, $session['id'] );
		$base['expires_in']         = BULK_PRICE_PREVIEW_TTL;
		return product_no_store_response( rest_ensure_response( array( 'data' => $base ) ) );
	}

	$changed = 0;
	$failed  = array();
	$parents_to_sync = array();
	foreach ( $plan['changes'] as $change ) {
		$product = $change['product'];
		try {
			$product->set_regular_price( $change['regular_price'] );
			if ( '' !== (string) $product->get_sale_price() ) {
				$product->set_sale_price( $change['sale_price'] );
			}
			$product->save();
			++$changed;
			if ( absint( $change['parent_id'] ) !== absint( $product->get_id() ) ) {
				$parents_to_sync[] = absint( $change['parent_id'] );
			}
		} catch ( \Throwable $exception ) {
			$failed[] = absint( $product->get_id() );
		}
	}
	foreach ( array_values( array_unique( $parents_to_sync ) ) as $parent_id ) {
		if ( class_exists( '\\WC_Product_Variable' ) ) {
			\WC_Product_Variable::sync( $parent_id );
		}
		wc_delete_product_transients( $parent_id );
	}

	$base['mode']       = 'executed';
	$base['changed']    = $changed;
	$base['failed']     = count( $failed );
	$base['failed_ids'] = array_slice( $failed, 0, 20 );
	record_audit_event( 'product_bulk_price_updated', $session['user']->ID, $session['id'], $session['device_label'], 'product_category', $values['category_ids'][0], array( 'count' => $changed, 'category_id' => $values['category_ids'][0], 'status' => $values['adjustment_type'] ) );
	$response = product_no_store_response( rest_ensure_response( array( 'data' => $base ) ) );
	if ( ! empty( $failed ) ) {
		$response->set_status( 207 );
	}
	return $response;
}
