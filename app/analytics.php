<?php
/**
 * Optional, site-local WooCommerce analytics for the Fandoogh Manager PWA.
 *
 * This module intentionally reads through WooCommerce CRUD/query APIs. It
 * does not proxy wp-admin, call a provider, or expose raw order metadata.
 */

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/orders.php';
require_once __DIR__ . '/composition/products.php';
require_once __DIR__ . '/pos.php';

const ANALYTICS_PAGE_SIZE = 100;
const ANALYTICS_MAX_PAGES = 25;

/**
 * @return void
 */
function register_analytics_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/analytics/summary',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_analytics_summary',
			'permission_callback' => __NAMESPACE__ . '\\analytics_read_permission',
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function analytics_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$settings = get_settings();
	if ( empty( $settings['analytics_enabled'] ) ) {
		return new \WP_Error( 'fandoogh_analytics_disabled', __( 'تحلیل فروش در تنظیمات افزونه خاموش است.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( ! session_has_scope( 'analytics.read', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_analytics_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ تحلیل فروش را ندارد. پس از تغییر دسترسی، یک اتصال امن جدید ایجاد کنید.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_analytics_forbidden', __( 'کاربر WordPress مجوز مشاهدهٔ تحلیل فروش را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param mixed $value Candidate text.
 * @param int   $max_length Maximum length.
 * @return string
 */
function analytics_clean_text( $value, $max_length = 160 ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	if ( strlen( $value ) > $max_length ) {
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	return $value;
}

/**
 * @param mixed $value Candidate amount.
 * @return string
 */
function analytics_money( $value ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return '0';
	}

	$formatted = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, false, false ) : (string) $value;
	return is_numeric( $formatted ) ? (string) $formatted : '0';
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function analytics_resolve_range( $request ) {
	$range = sanitize_key( (string) $request->get_param( 'range' ) );
	$allowed = array( 'today', '7d', '30d', 'month', 'year', 'custom' );
	if ( '' === $range ) {
		$range = '30d';
	}
	if ( ! in_array( $range, $allowed, true ) ) {
		return new \WP_Error( 'fandoogh_analytics_range', __( 'بازهٔ تحلیل معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	$now      = new \DateTimeImmutable( 'now', $timezone );
	$today    = $now->setTime( 0, 0, 0 );
	$start    = $today;
	$end      = $today->setTime( 23, 59, 59 );
	$labels   = array(
		'today'  => 'امروز',
		'7d'     => '۷ روز اخیر',
		'30d'    => '۳۰ روز اخیر',
		'month'  => 'ماه جاری',
		'year'   => 'سال جاری',
		'custom' => 'بازهٔ انتخابی',
	);

	switch ( $range ) {
		case '7d':
			$start = $today->modify( '-6 days' );
			break;
		case '30d':
			$start = $today->modify( '-29 days' );
			break;
		case 'month':
			$start = $today->modify( 'first day of this month' );
			break;
		case 'year':
			$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
			break;
		case 'custom':
			$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
			$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );
			$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $timezone );
			$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $timezone );
			if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $start > $end ) {
				return new \WP_Error( 'fandoogh_analytics_range', __( 'تاریخ شروع و پایان بازهٔ تحلیل معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
			}
			$end = $end->setTime( 23, 59, 59 );
			break;
	}

	return array(
		'key'       => $range,
		'label'     => $labels[ $range ],
		'start'     => $start->format( 'Y-m-d H:i:s' ),
		'end'       => $end->format( 'Y-m-d H:i:s' ),
		'start_iso' => $start->format( DATE_ATOM ),
		'end_iso'   => $end->format( DATE_ATOM ),
	);
}

/**
 * @param array<string, string> $range Resolved range.
 * @return array{orders: array<int, object>, truncated: bool}|\WP_Error
 */
function analytics_fetch_orders( $range, $channel = 'all' ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce برای تحلیل فروش فعال نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	$orders    = array();
	$truncated = false;
	$repository = compose_order_repository();

	try {
		for ( $page = 1; $page <= ANALYTICS_MAX_PAGES; $page++ ) {
			$result = $repository->query(
				pos_channel_query_args( array(
					'limit'       => ANALYTICS_PAGE_SIZE,
					'paged'       => $page,
					'paginate'     => true,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'date_created' => $range['start'] . '...' . $range['end'],
					'return'       => 'objects',
				), $channel )
			);

			if ( is_wp_error( $result ) || ! is_object( $result ) || ! isset( $result->orders ) || ! is_array( $result->orders ) ) {
				return new \WP_Error( 'fandoogh_analytics_failed', __( 'خواندن داده‌های تحلیل فروش انجام نشد.', 'fandoogh-manager' ), array( 'status' => 503 ) );
			}

			$page_orders = $result->orders;
			$orders      = array_merge( $orders, $page_orders );
			$max_pages   = is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 1;

			if ( $page >= $max_pages || count( $page_orders ) < ANALYTICS_PAGE_SIZE ) {
				break;
			}

			if ( $page === ANALYTICS_MAX_PAGES ) {
				$truncated = true;
			}
		}
	} catch ( \Throwable $exception ) {
		return new \WP_Error( 'fandoogh_analytics_failed', __( 'خواندن داده‌های تحلیل فروش انجام نشد.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	return array(
		'orders'    => $orders,
		'truncated' => $truncated,
	);
}

/**
 * @return array<string, mixed>
 */
function analytics_status_labels() {
	$labels = array();
	if ( function_exists( 'wc_get_order_statuses' ) ) {
		foreach ( compose_order_repository()->statuses() as $status => $label ) {
			$key            = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
			$labels[ $key ] = analytics_clean_text( $label, 80 );
		}
	}

	return $labels;
}

/**
 * @return int
 */
function analytics_product_count() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return 0;
	}

	try {
		$result = compose_product_repository()->query(
			array(
				'limit'   => 1,
				'paginate' => true,
				'return'  => 'ids',
			)
		);
		return is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : count( (array) $result );
	} catch ( \Throwable $exception ) {
		return 0;
	}
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function get_analytics_summary( $request ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce برای تحلیل فروش فعال نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	$range = analytics_resolve_range( $request );
	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$channel = sanitize_key( (string) $request->get_param('channel') ) ?: 'all';
	if ( ! in_array($channel,array('all','pos','online'),true) ) { return new \WP_Error('fandoogh_analytics_channel',__('منبع فروش معتبر نیست.','fandoogh-manager'),array('status'=>422)); }
	$collection = analytics_fetch_orders( $range, $channel );
	if ( is_wp_error( $collection ) ) {
		return $collection;
	}

	$status_labels      = analytics_status_labels();
	$status_counts      = array();
	$successful_statuses = array( 'processing', 'completed', 'on-hold' );
	$successful_orders  = 0;
	$gross_sales        = 0.0;
	$unique_customers   = array();
	$guest_orders       = 0;
	$product_totals     = array();
	$channels = array('pos'=>array('orders'=>0,'gross'=>0.0,'refunded'=>0.0,'net'=>0.0),'online'=>array('orders'=>0,'gross'=>0.0,'refunded'=>0.0,'net'=>0.0));

	foreach ( $collection['orders'] as $order ) {
		if ( ! is_object( $order ) ) {
			continue;
		}

		$status = sanitize_key( (string) ( method_exists( $order, 'get_status' ) ? $order->get_status() : '' ) );
		if ( '' === $status ) {
			continue;
		}

		$status_counts[ $status ] = isset( $status_counts[ $status ] ) ? $status_counts[ $status ] + 1 : 1;
		$source = order_sales_channel($order);
		$channels[$source]['orders']++;
		// Fully refunded orders retain original gross and their refund, for zero net.
		if ( in_array($status,array('processing','completed','on-hold','refunded'),true) ) {
			$amount = (float) analytics_money(method_exists($order,'get_total') ? $order->get_total() : 0);
			$refund = (float) analytics_money(method_exists($order,'get_total_refunded') ? $order->get_total_refunded() : 0);
			$channels[$source]['gross'] += $amount;
			$channels[$source]['refunded'] += $refund;
			$channels[$source]['net'] += $amount - $refund;
		}

		$customer_id = absint( method_exists( $order, 'get_customer_id' ) ? $order->get_customer_id() : 0 );
		if ( $customer_id > 0 ) {
			$unique_customers[ $customer_id ] = true;
		} else {
			$guest_orders++;
		}

		if ( in_array( $status, $successful_statuses, true ) ) {
			$successful_orders++;
			$gross_sales += (float) analytics_money( method_exists( $order, 'get_total' ) ? $order->get_total() : 0 );
		}

		if ( ! method_exists( $order, 'get_items' ) ) {
			continue;
		}

		try {
			$items = $order->get_items( 'line_item' );
		} catch ( \Throwable $exception ) {
			$items = array();
		}

		foreach ( (array) $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$product_id = absint( method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : 0 );
			$name       = analytics_clean_text( method_exists( $item, 'get_name' ) ? $item->get_name() : '', 160 );
			$key        = $product_id > 0 ? (string) $product_id : md5( $name );
			$quantity   = (int) ( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 0 );
			$total      = (float) analytics_money( method_exists( $item, 'get_total' ) ? $item->get_total() : 0 );

			if ( ! isset( $product_totals[ $key ] ) ) {
				$product_totals[ $key ] = array(
					'id'       => $product_id,
					'name'     => $name,
					'quantity' => 0,
					'total'    => 0.0,
				);
			}

			$product_totals[ $key ]['quantity'] += $quantity;
			$product_totals[ $key ]['total']    += $total;
		}
	}

	$product_totals = array_values( $product_totals );
	usort(
		$product_totals,
		static function ( $left, $right ) {
			return ( $right['quantity'] <=> $left['quantity'] );
		}
	);

	$top_products = array();
	foreach ( array_slice( $product_totals, 0, 5 ) as $product ) {
		$top_products[] = array(
			'id'       => absint( $product['id'] ),
			'name'     => analytics_clean_text( $product['name'], 160 ),
			'quantity' => max( 0, (int) $product['quantity'] ),
			'total'    => analytics_money( $product['total'] ),
		);
	}

	$currency = get_public_currency();
	$successful_gross = $gross_sales;
	$gross_sales = $channels['pos']['gross'] + $channels['online']['gross'];
	foreach($channels as &$channel_values) { foreach(array('gross','refunded','net') as $key) { $channel_values[$key] = analytics_money($channel_values[$key]); } } unset($channel_values);
	$data     = array(
		'channel' => $channel,
		'channels' => $channels,
		'range'     => array(
			'key'       => $range['key'],
			'label'     => $range['label'],
			'start'     => $range['start_iso'],
			'end'       => $range['end_iso'],
		),
		'currency'  => $currency,
		'sales'     => array(
			'gross'         => analytics_money( $gross_sales ),
			'average_order' => $successful_orders > 0 ? analytics_money( $successful_gross / $successful_orders ) : '0',
			'net' => analytics_money((float)$channels['pos']['net'] + (float)$channels['online']['net']),
			'refunded' => analytics_money((float)$channels['pos']['refunded'] + (float)$channels['online']['refunded']),
		),
		'orders'    => array(
			'total'      => count( $collection['orders'] ),
			'successful' => $successful_orders,
			'statuses'   => array(),
		),
		'products'  => array(
			'total' => analytics_product_count(),
			'top'   => $top_products,
		),
		'customers' => array(
			'unique'      => count( $unique_customers ),
			'guest_orders' => $guest_orders,
		),
		'meta'      => array(
			'truncated' => ! empty( $collection['truncated'] ),
		),
	);

	foreach ( $status_counts as $status => $count ) {
		$data['orders']['statuses'][] = array(
			'key'   => $status,
			'label' => isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status,
			'count' => absint( $count ),
		);
	}

	$response = rest_ensure_response( array( 'data' => $data ) );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	return $response;
}
