<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/products.php';

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

function inventory_parent_product_types() {
	$types = array( 'simple', 'variable', 'grouped', 'external' );
	if ( function_exists( 'wc_get_product_types' ) ) {
		$registered_types = wc_get_product_types();
		if ( is_array( $registered_types ) && ! empty( $registered_types ) ) {
			$types = array_keys( $registered_types );
		}
	}

	$types = array_values( array_diff( array_map( 'sanitize_key', $types ), array( '', 'variation' ) ) );
	return ! empty( $types ) ? $types : array( 'simple', 'variable', 'grouped', 'external' );
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
	$updated_at = null;
	if ( method_exists( $product, 'get_date_modified' ) ) {
		$modified = $product->get_date_modified();
		if ( is_object( $modified ) && method_exists( $modified, 'date' ) ) {
			$updated_at = inventory_clean_text( $modified->date( DATE_ATOM ), 40 );
		}
	}
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
		'updated_at'    => $updated_at,
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
	$args = array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'return' => 'objects', 'orderby' => 'name', 'order' => 'ASC', 'status' => readable_product_statuses( $session['user']->ID ), 'type' => inventory_parent_product_types() );
	$search = inventory_clean_text( $request->get_param( 'search' ), 80 );
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	$stock_status = sanitize_key( (string) $request->get_param( 'stock_status' ) );
	if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
		$args['stock_status'] = $stock_status;
	}
	try {
		$result = compose_product_repository()->query( $args );
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
	$pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
	return inventory_response( array( 'data' => $items, 'summary' => $summary, 'meta' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $pages ) ) );
}

function inventory_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return inventory_error( 'fandoogh_inventory_json_required', __( 'بدنهٔ درخواست موجودی باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! empty( array_diff( array_keys( $body ), array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount', 'expected_updated_at' ) ) ) ) {
		return inventory_error( 'fandoogh_inventory_invalid_body', __( 'بدنهٔ درخواست موجودی معتبر نیست.', 'fandoogh-manager' ) );
	}
	return inventory_validate_update_fields( $body );
}

function inventory_non_negative( $value ) {
	if ( null === $value || ( is_string( $value ) && '' === $value ) ) {
		return null;
	}
	if ( is_int( $value ) ) {
		$integer = $value;
	} elseif ( is_string( $value ) && preg_match( '/^[0-9]{1,9}$/D', $value ) ) {
		$integer = (int) $value;
	} else {
		return false;
	}
	if ( $integer < 0 || $integer > 999999999 ) {
		return false;
	}
	return $integer;
}

function inventory_validate_update_fields( $body ) {
	$values = array();
	if ( array_key_exists( 'manage_stock', $body ) ) {
		if ( ! is_bool( $body['manage_stock'] ) ) {
			return inventory_error( 'fandoogh_inventory_invalid_manage_stock', __( 'مقدار فیلد manage_stock باید true یا false باشد.', 'fandoogh-manager' ) );
		}
		$values['manage_stock'] = $body['manage_stock'];
	}
	if ( array_key_exists( 'stock_quantity', $body ) ) {
		$quantity = inventory_non_negative( $body['stock_quantity'] );
		if ( false === $quantity ) {
			return inventory_error( 'fandoogh_inventory_invalid_quantity', __( 'مقدار موجودی معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['stock_quantity'] = $quantity;
	}
	if ( array_key_exists( 'stock_status', $body ) ) {
		$status = $body['stock_status'];
		if ( ! is_string( $status ) || ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			return inventory_error( 'fandoogh_inventory_invalid_status', __( 'وضعیت موجودی معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['stock_status'] = $status;
	}
	if ( array_key_exists( 'backorders', $body ) ) {
		$backorders = $body['backorders'];
		if ( ! is_string( $backorders ) || ! in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) {
			return inventory_error( 'fandoogh_inventory_invalid_backorders', __( 'تنظیم پیش‌فروش معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['backorders'] = $backorders;
	}
	if ( array_key_exists( 'low_stock_amount', $body ) ) {
		$threshold = inventory_non_negative( $body['low_stock_amount'] );
		if ( false === $threshold ) {
			return inventory_error( 'fandoogh_inventory_invalid_threshold', __( 'آستانهٔ موجودی معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['low_stock_amount'] = $threshold;
	}
	if ( array_key_exists( 'expected_updated_at', $body ) ) {
		if ( ! is_string( $body['expected_updated_at'] ) || '' === trim( $body['expected_updated_at'] ) || strlen( trim( $body['expected_updated_at'] ) ) > 40 ) {
			return inventory_error( 'fandoogh_inventory_invalid_version', __( 'نسخهٔ اطلاعات موجودی معتبر نیست.', 'fandoogh-manager' ) );
		}
		$values['expected_updated_at'] = trim( $body['expected_updated_at'] );
	}
	if ( array_key_exists( 'stock_quantity', $values ) && null !== $values['stock_quantity'] && array_key_exists( 'manage_stock', $values ) && ! $values['manage_stock'] ) {
		return inventory_error( 'fandoogh_inventory_stock_management_required', __( 'برای تعیین موجودی، manage_stock باید true باشد.', 'fandoogh-manager' ) );
	}
	return $values;
}

/**
 * Normalize the product modification time for optimistic concurrency. The
 * client only echoes this server-generated value; it is never an
 * authorization signal.
 *
 * @param mixed $value Candidate timestamp.
 * @return string|false
 */
function inventory_normalize_updated_at( $value ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return false;
	}

	$value = trim( (string) $value );
	if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(Z|[+\-](?:0\d|1\d|2[0-3]):[0-5]\d)$/D', $value, $matches ) ) {
		return false;
	}

	$offset = 'Z' === $matches[2] ? '+00:00' : $matches[2];
	try {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:sP', $matches[1] . $offset );
	} catch ( \Throwable $exception ) {
		return false;
	}

	$errors = \DateTimeImmutable::getLastErrors();
	if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\\TH:i:s' ) !== $matches[1] ) {
		return false;
	}

	return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
}

/**
 * @param object $product WooCommerce product.
 * @return string
 */
function inventory_product_updated_at( $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_date_modified' ) ) {
		return '';
	}

	$modified = $product->get_date_modified();
	if ( ! is_object( $modified ) || ! method_exists( $modified, 'date' ) ) {
		return '';
	}

	return (string) $modified->date( DATE_ATOM );
}

function update_inventory_item( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$product_id = absint( $request->get_param( 'id' ) );
	$product = compose_product_repository()->findById( $product_id );
	if ( ! $product || ! method_exists( $product, 'get_id' ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
		return inventory_error( 'fandoogh_inventory_not_found', __( 'محصول موجودی پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	$body = inventory_request_body( $request );
	if ( is_wp_error( $body ) || empty( $body ) ) {
		return is_wp_error( $body ) ? $body : inventory_error( 'fandoogh_inventory_empty_update', __( 'حداقل یک فیلد موجودی ارسال کنید.', 'fandoogh-manager' ) );
	}
	$mutation_fields = array_diff( array_keys( $body ), array( 'expected_updated_at' ) );
	if ( empty( $mutation_fields ) ) {
		return inventory_error( 'fandoogh_inventory_empty_update', __( 'حداقل یک فیلد موجودی برای تغییر ارسال کنید.', 'fandoogh-manager' ) );
	}
	if ( array_key_exists( 'expected_updated_at', $body ) ) {
		$expected_updated_at = inventory_normalize_updated_at( $body['expected_updated_at'] );
		$current_updated_at  = inventory_product_updated_at( $product );
		if ( false === $expected_updated_at || ( '' !== $current_updated_at && $expected_updated_at !== $current_updated_at ) ) {
			return inventory_error( 'fandoogh_inventory_conflict', __( 'موجودی این محصول در جای دیگری تغییر کرده است؛ اطلاعات جدید را دریافت و دوباره بررسی کنید.', 'fandoogh-manager' ), 409 );
		}
	}
	try {
		if ( array_key_exists( 'manage_stock', $body ) ) {
			$product->set_manage_stock( $body['manage_stock'] );
		}
		if ( array_key_exists( 'stock_quantity', $body ) ) {
			$product->set_stock_quantity( $body['stock_quantity'] );
			if ( ! array_key_exists( 'manage_stock', $body ) ) {
				$product->set_manage_stock( true );
			}
		}
		if ( array_key_exists( 'stock_status', $body ) ) {
			$product->set_stock_status( $body['stock_status'] );
		}
		if ( array_key_exists( 'backorders', $body ) ) {
			$product->set_backorders( $body['backorders'] );
		}
		if ( array_key_exists( 'low_stock_amount', $body ) && method_exists( $product, 'set_low_stock_amount' ) ) {
			$product->set_low_stock_amount( $body['low_stock_amount'] );
		}
		$product->save();
		$product = compose_product_repository()->findById( $product_id );
	} catch ( \Throwable $exception ) {
		return inventory_error( 'fandoogh_inventory_save_failed', __( 'ذخیرهٔ موجودی انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	record_audit_event( 'inventory_updated', $session['user']->ID, $session['id'], $session['device_label'], 'product', $product_id, array( 'product_id' => $product_id, 'status' => $product->get_stock_status() ) );
	return inventory_response( array( 'data' => serialize_inventory_item( $product ) ) );
}
