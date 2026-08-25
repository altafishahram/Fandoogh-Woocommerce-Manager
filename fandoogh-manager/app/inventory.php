<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory overview and bounded stock adjustments. The module uses the
 * WooCommerce product CRUD/query layer and deliberately leaves reservations,
 * payment state and order editing to WooCommerce itself.
 */
function register_inventory_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/inventory',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_inventory',
			'permission_callback' => __NAMESPACE__ . '\\inventory_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/inventory/(?P<id>\\d+)',
		array(
			'methods'             => 'PUT, PATCH',
			'callback'            => __NAMESPACE__ . '\\update_inventory_item',
			'permission_callback' => __NAMESPACE__ . '\\inventory_write_permission',
		)
	);
}

function inventory_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
	);
}

function inventory_error( $code, $message, $status = 422 ) {
	return new \WP_Error( $code, $message, array( 'status' => absint( $status ), 'headers' => inventory_headers() ) );
}

function inventory_response( $payload ) {
	$response = rest_ensure_response( $payload );
	foreach ( inventory_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}

function inventory_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'inventory.read', $session['scopes'] ) ) {
		return inventory_error( 'fandoogh_inventory_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ موجودی را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return inventory_error( 'fandoogh_inventory_forbidden', __( 'کاربر WordPress مجوز مدیریت موجودی را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function inventory_write_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'inventory.write', $session['scopes'] ) ) {
		return inventory_error( 'fandoogh_inventory_write_scope', __( 'نشست فعلی مجوز تغییر موجودی را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return inventory_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی و موجودی را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return inventory_error( 'fandoogh_inventory_forbidden', __( 'کاربر WordPress مجوز تغییر موجودی را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function inventory_clean_text( $value, $max = 160 ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
}

function inventory_status_label( $status ) {
	$labels = array(
		'instock'    => __( 'موجود', 'fandoogh-manager' ),
		'outofstock' => __( 'ناموجود', 'fandoogh-manager' ),
		'onbackorder'=> __( 'پیش‌فروش', 'fandoogh-manager' ),
	);
	return isset( $labels[ $status ] ) ? $labels[ $status ] : inventory_clean_text( $status, 40 );
}

function serialize_inventory_item( $product ) {
	$quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
	$threshold = method_exists( $product, 'get_low_stock_amount' ) ? $product->get_low_stock_amount() : '';
	$low = false;
	if ( null !== $quantity && '' !== (string) $threshold && is_numeric( $threshold ) ) {
		$low = (int) $quantity <= (int) $threshold;
	}
	return array(
		'id'            => absint( $product->get_id() ),
		'name'          => inventory_clean_text( $product->get_name(), 180 ),
		'sku'           => inventory_clean_text( $product->get_sku(), 100 ),
		'type'          => sanitize_key( $product->get_type() ),
		'stock_status'  => sanitize_key( $product->get_stock_status() ),
		'stock_label'   => inventory_status_label( $product->get_stock_status() ),
		'stock_quantity'=> null === $quantity ? null : (int) $quantity,
		'manage_stock'  => (bool) $product->get_manage_stock(),
		'backorders'    => method_exists( $product, 'get_backorders' ) ? sanitize_key( $product->get_backorders() ) : 'no',
		'low_stock_amount' => '' === (string) $threshold ? null : (int) $threshold,
		'low_stock'     => $low,
	);
}

function list_inventory( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		return inventory_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce برای مدیریت موجودی فعال نیست.', 'fandoogh-manager' ), 503 );
	}
	apply_session_user_context( $session );
	$page = max( 1, min( 100000, absint( $request->get_param( 'page' ) ?: 1 ) ) );
	$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
	$args = array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'return' => 'objects', 'orderby' => 'name', 'order' => 'ASC', 'status' => readable_product_statuses( $session['user']->ID ) );
	$search = inventory_clean_text( $request->get_param( 'search' ), 80 );
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	$stock_status = sanitize_key( (string) $request->get_param( 'stock_status' ) );
	if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
		$args['stock_status'] = $stock_status;
	}
	try {
		$result = wc_get_products( $args );
	} catch ( \Throwable $exception ) {
		return inventory_error( 'fandoogh_inventory_query_failed', __( 'خواندن موجودی انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	$products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : (array) $result;
	$total = is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : count( $products );
	$items = array();
	$summary = array( 'total' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'on_backorder' => 0 );
	foreach ( $products as $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
			continue;
		}
		$item = serialize_inventory_item( $product );
		$items[] = $item;
		$summary['total']++;
		$summary['low_stock'] += $item['low_stock'] ? 1 : 0;
		$summary['out_of_stock'] += 'outofstock' === $item['stock_status'] ? 1 : 0;
		$summary['on_backorder'] += 'onbackorder' === $item['stock_status'] ? 1 : 0;
	}
	$pages = is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : max( 1, (int) ceil( $total / $per_page ) );
	return inventory_response( array( 'data' => $items, 'summary' => $summary, 'meta' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $pages ) ) );
}

function inventory_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return inventory_error( 'fandoogh_inventory_json_required', __( 'بدنهٔ درخواست موجودی باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! empty( array_diff( array_keys( $body ), array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ) ) ) ) {
		return inventory_error( 'fandoogh_inventory_invalid_body', __( 'بدنهٔ درخواست موجودی معتبر نیست.', 'fandoogh-manager' ) );
	}
	return $body;
}

function inventory_non_negative( $value ) {
	if ( null === $value || '' === (string) $value ) {
		return null;
	}
	if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]{1,9}$/', (string) $value ) ) {
		return false;
	}
	return absint( $value );
}

function update_inventory_item( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$product_id = absint( $request->get_param( 'id' ) );
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product || ! method_exists( $product, 'get_id' ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
		return inventory_error( 'fandoogh_inventory_not_found', __( 'محصول موجودی پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	$body = inventory_request_body( $request );
	if ( is_wp_error( $body ) || empty( $body ) ) {
		return is_wp_error( $body ) ? $body : inventory_error( 'fandoogh_inventory_empty_update', __( 'حداقل یک فیلد موجودی ارسال کنید.' ) );
	}
	try {
		if ( array_key_exists( 'manage_stock', $body ) ) {
			$product->set_manage_stock( (bool) $body['manage_stock'] );
		}
		if ( array_key_exists( 'stock_quantity', $body ) ) {
			$quantity = inventory_non_negative( $body['stock_quantity'] );
			if ( false === $quantity ) {
				return inventory_error( 'fandoogh_inventory_invalid_quantity', __( 'مقدار موجودی معتبر نیست.' ) );
			}
			$product->set_stock_quantity( $quantity );
			if ( ! array_key_exists( 'manage_stock', $body ) ) {
				$product->set_manage_stock( true );
			}
		}
		if ( array_key_exists( 'stock_status', $body ) ) {
			$status = sanitize_key( (string) $body['stock_status'] );
			if ( ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				return inventory_error( 'fandoogh_inventory_invalid_status', __( 'وضعیت موجودی معتبر نیست.' ) );
			}
			$product->set_stock_status( $status );
		}
		if ( array_key_exists( 'backorders', $body ) ) {
			$backorders = sanitize_key( (string) $body['backorders'] );
			if ( ! in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) {
				return inventory_error( 'fandoogh_inventory_invalid_backorders', __( 'تنظیم پیش‌فروش معتبر نیست.' ) );
			}
			$product->set_backorders( $backorders );
		}
		if ( array_key_exists( 'low_stock_amount', $body ) && method_exists( $product, 'set_low_stock_amount' ) ) {
			$threshold = inventory_non_negative( $body['low_stock_amount'] );
			if ( false === $threshold ) {
				return inventory_error( 'fandoogh_inventory_invalid_threshold', __( 'آستانهٔ موجودی معتبر نیست.' ) );
			}
			$product->set_low_stock_amount( $threshold );
		}
		$product->save();
		$product = wc_get_product( $product_id );
	} catch ( \Throwable $exception ) {
		return inventory_error( 'fandoogh_inventory_save_failed', __( 'ذخیرهٔ موجودی انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	record_audit_event( 'inventory_updated', $session['user']->ID, $session['id'], $session['device_label'], 'product', $product_id, array( 'product_id' => $product_id, 'status' => $product->get_stock_status() ) );
	return inventory_response( array( 'data' => serialize_inventory_item( $product ) ) );
}
