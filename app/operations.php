<?php
/** Daily work and exact barcode lookup through WooCommerce CRUD only. */
namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

function register_operations_routes() {
	register_rest_route( REST_NAMESPACE, '/operations/today', array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\operations_today', 'permission_callback' => __NAMESPACE__ . '\\operations_read_permission' ) );
	register_rest_route( REST_NAMESPACE, '/operations/barcode', array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\operations_barcode', 'permission_callback' => __NAMESPACE__ . '\\products_read_permission' ) );
}

function operations_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return inventory_error( 'fandoogh_operations_forbidden', __( 'مجوز مشاهدهٔ عملیات فروشگاه را ندارید.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_scope( 'orders.read', $session['scopes'] ) && ! session_has_scope( 'inventory.read', $session['scopes'] ) ) {
		return inventory_error( 'fandoogh_operations_scope', __( 'مجوز سفارش یا موجودی لازم است.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

/** Preserve leading zeros. A barcode is an identifier, never a number. */
function operations_normalize_barcode( $raw ) {
	if ( ! is_string( $raw ) || strlen( $raw ) > 300 ) { return ''; }
	$value = trim( strtr( $raw, array( '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9' ) ) );
	return preg_match( '/^[A-Za-z0-9._\-]{1,100}$/D', $value ) ? $value : '';
}

function operations_barcode( $request ) {
	$code = operations_normalize_barcode( $request->get_param( 'code' ) );
	if ( '' === $code ) { return inventory_error( 'fandoogh_barcode_invalid', __( 'بارکد یا SKU معتبر وارد کنید.', 'fandoogh-manager' ) ); }
	$repository = compose_product_repository();
	if ( ! $repository->isAvailable() ) { return inventory_error( 'fandoogh_woocommerce_inactive', __( 'ووکامرس در دسترس نیست.', 'fandoogh-manager' ), 503 ); }
	try {
		$id = $repository->findIdBySku( $code );
		if ( ! $id && function_exists( 'wc_get_product_id_by_global_unique_id' ) ) { $id = \wc_get_product_id_by_global_unique_id( $code ); }
		$product = $repository->findById( (int) $id );
		$parent_id = $product && $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
		$parent = $parent_id ? $repository->findById( (int) $parent_id ) : $product;
		if ( ! $product || ! $parent || ! in_array( $product->get_status(), readable_product_statuses( get_current_user_id() ), true ) || ! in_array( $parent->get_status(), readable_product_statuses( get_current_user_id() ), true ) ) {
			return inventory_error( 'fandoogh_barcode_not_found', __( 'محصولی با این بارکد پیدا نشد. بارکد را در SKU یا شناسهٔ جهانی محصول ثبت کنید.', 'fandoogh-manager' ), 404 );
		}
		return inventory_response( array( 'data' => array( 'id' => $product->get_id(), 'parent_id' => $parent_id, 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'search' => $parent->get_sku() ?: $parent->get_name() ) ) );
	} catch ( \Throwable $error ) {
		return inventory_error( 'fandoogh_barcode_failed', __( 'خواندن محصول انجام نشد؛ دوباره تلاش کنید.', 'fandoogh-manager' ), 503 );
	}
}

function operations_low_stock( $product ) {
	if ( $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock() ) { return false; }
	if ( 'outofstock' === $product->get_stock_status() ) { return true; }
	if ( ! $product->get_manage_stock() || null === $product->get_stock_quantity() ) { return false; }
	$threshold = function_exists( 'wc_get_low_stock_amount' ) ? \wc_get_low_stock_amount( $product ) : $product->get_low_stock_amount();
	if ( '' === (string) $threshold || null === $threshold ) { $threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 ); }
	return (float) $product->get_stock_quantity() <= (float) $threshold;
}

function operations_order_task( $order, $kind ) {
	return array( 'id' => $order->get_id(), 'kind' => $kind, 'section' => 'orders', 'title' => sprintf( __( 'سفارش #%s', 'fandoogh-manager' ), $order->get_order_number() ), 'status' => $order->get_status() );
}

/** Bounded pages with explicit continuation, never pretend a partial scan is complete. */
function operations_today( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	$orders = compose_order_repository();
	$products = compose_product_repository();
	$groups = array();
	try {
		if ( session_has_scope( 'orders.read', $session['scopes'] ) ) {
			if ( ! $orders->isAvailable() ) { throw new \RuntimeException( 'woocommerce-unavailable' ); }
			$pending = $orders->query( array( 'status' => array( 'pending', 'on-hold' ), 'limit' => 8, 'paginate' => true, 'orderby' => 'date', 'order' => 'ASC' ) );
			if ( ! is_object( $pending ) || ! isset( $pending->orders, $pending->total ) ) { throw new \RuntimeException( 'invalid-query' ); }
			$groups[] = array( 'kind' => 'payment', 'title' => __( 'نیازمند بررسی پرداخت', 'fandoogh-manager' ), 'count' => (int) $pending->total, 'items' => array_map( static function( $order ) { return operations_order_task( $order, 'payment' ); }, $pending->orders ) );
			$page = max( 1, min( 10000, absint( $request->get_param( 'order_page' ) ?: 1 ) ) );
			$processing = $orders->query( array( 'status' => 'processing', 'limit' => 100, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'ASC' ) );
			if ( ! is_object( $processing ) || ! isset( $processing->orders, $processing->max_num_pages ) ) { throw new \RuntimeException( 'invalid-query' ); }
			$items = array();
			foreach ( $processing->orders as $order ) {
				if ( method_exists( $order, 'needs_shipping_address' ) && ! $order->needs_shipping_address() ) { continue; }
				$shipment = shipping_read_snapshot( $order );
				if ( ! empty( $shipment['shipped_at'] ) || ! in_array( $shipment['status'], array( 'pending', 'ready', 'failed' ), true ) ) { continue; }
				$created = $order->get_date_paid() ?: $order->get_date_created();
				$late = $created && $created->getTimestamp() < time() - 2 * DAY_IN_SECONDS;
				$item = operations_order_task( $order, 'shipping' );
				$item['late'] = (bool) $late;
				$items[] = $item;
			}
			$groups[] = array( 'kind' => 'shipping', 'title' => __( 'آمادهٔ ارسال', 'fandoogh-manager' ), 'count' => count( $items ), 'items' => $items, 'page' => $page, 'pages' => (int) $processing->max_num_pages, 'paged' => true );
		}
		if ( session_has_scope( 'inventory.read', $session['scopes'] ) ) {
			if ( ! $products->isAvailable() ) { throw new \RuntimeException( 'woocommerce-unavailable' ); }
			$page = max( 1, min( 10000, absint( $request->get_param( 'inventory_page' ) ?: 1 ) ) );
			// Separate variation queries also work on older WooCommerce data stores.
			$args = array( 'status' => readable_product_statuses( $session['user']->ID ), 'limit' => 50, 'page' => $page, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC' );
			$result = $products->query( array_merge( $args, array( 'type' => inventory_parent_product_types() ) ) );
			$variations = $products->query( array_merge( $args, array( 'type' => 'variation' ) ) );
			if ( ! is_object( $result ) || ! isset( $result->products, $result->max_num_pages ) || ! is_object( $variations ) || ! isset( $variations->products, $variations->max_num_pages ) ) { throw new \RuntimeException( 'invalid-query' ); }
			$items = array();
			foreach ( array_merge( $result->products, $variations->products ) as $product ) {
				if ( $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock() ) { continue; }
				if ( ! operations_low_stock( $product ) ) { continue; }
				$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
				if ( $parent_id ) {
					$parent = $products->findById( (int) $parent_id );
					if ( ! $parent || ! in_array( $parent->get_status(), $args['status'], true ) ) { continue; }
				}
				$items[] = array( 'id' => $product->get_id(), 'parent_id' => $parent_id, 'kind' => 'stock', 'section' => 'products', 'title' => $product->get_name(), 'quantity' => $product->get_stock_quantity(), 'status' => $product->get_stock_status() );
			}
			$groups[] = array( 'kind' => 'stock', 'title' => __( 'نیازمند تأمین موجودی', 'fandoogh-manager' ), 'count' => count( $items ), 'items' => $items, 'page' => $page, 'pages' => max( (int) $result->max_num_pages, (int) $variations->max_num_pages ), 'paged' => true );
		}
		return inventory_response( array( 'data' => array( 'groups' => $groups, 'generated_at' => gmdate( DATE_ATOM ), 'delay_hours' => 48 ) ) );
	} catch ( \Throwable $error ) {
		return inventory_error( 'fandoogh_today_failed', __( 'کارهای امروز دریافت نشد؛ دوباره تازه‌سازی کنید.', 'fandoogh-manager' ), 503 );
	}
}
