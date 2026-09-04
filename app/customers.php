<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register read-only customer endpoints.
 *
 * This module intentionally uses WooCommerce's customer data store and
 * WC_Customer getters. It does not expose WordPress user meta, passwords,
 * payment tokens, or customer mutations.
 *
 * @return void
 */
function register_customer_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/customers',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_customers',
			'permission_callback' => __NAMESPACE__ . '\\customers_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/customers/(?P<id>\\d+)/orders',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_customer_orders',
			'permission_callback' => __NAMESPACE__ . '\\customer_orders_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/customers/(?P<id>\\d+)',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_customer_detail',
			'permission_callback' => __NAMESPACE__ . '\\customers_read_permission',
		)
	);
}

/**
 * Headers for customer responses, including permission errors.
 *
 * @return array<string, string>
 */
function customers_private_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
	);
}

/**
 * Add private response headers to a successful customer response.
 *
 * @param mixed $payload Response payload.
 * @return \WP_REST_Response
 */
function customers_no_store_response( $payload ) {
	$response = rest_ensure_response( $payload );
	foreach ( customers_private_headers() as $name => $value ) {
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
function customers_private_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return $error;
	}

	$code    = $error->get_error_code();
	$data    = $error->get_error_data( $code );
	$data    = is_array( $data ) ? $data : array();
	$headers = isset( $data['headers'] ) && is_array( $data['headers'] ) ? $data['headers'] : array();
	$data['headers'] = array_replace( $headers, customers_private_headers() );

	return new \WP_Error( $code, $error->get_error_message( $code ), $data );
}

/**
 * Create a clear, non-sensitive customer API error.
 *
 * @param string               $code Error code.
 * @param string               $message User-facing message.
 * @param int                  $status HTTP status.
 * @param array<string, mixed> $extra Extra WP_Error data.
 * @return \WP_Error
 */
function customers_error( $code, $message, $status, $extra = array() ) {
	$data         = is_array( $extra ) ? $extra : array();
	$data['status'] = absint( $status );
	return customers_private_error( new \WP_Error( $code, $message, $data ) );
}

/**
 * Check the PWA session, the narrow customer scope, and the real WordPress
 * capability on every customer request.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function customers_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return customers_private_error( $session );
	}

	if ( ! session_has_scope( 'customers.read', $session['scopes'] ) ) {
		return customers_error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز خواندن مشتریان را ندارد.', 'fandoogh-manager' ), 403 );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return customers_error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز خواندن مشتریان را ندارد.', 'fandoogh-manager' ), 403 );
	}

	return true;
}

/**
 * Customer order history needs both customer-profile and order-read scopes.
 * Keeping the second capability check here prevents the nested endpoint from
 * becoming an accidental order-data bypass for a profile-only session.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function customer_orders_read_permission( $request ) {
	$customer_permission = customers_read_permission( $request );
	if ( is_wp_error( $customer_permission ) ) {
		return $customer_permission;
	}

	return orders_read_permission( $request );
}

/**
 * @return bool
 */
function customers_available() {
	return class_exists( '\\WC_Customer' ) && class_exists( '\\WC_Customer_Data_Store' );
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function customers_query_integer( $value, $default, $min, $max ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return $default;
	}

	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * Limit and normalize the public customer search term.
 *
 * @param mixed $value Candidate search text.
 * @return string
 */
function customers_search_term( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$term = trim( sanitize_text_field( (string) $value ) );
	$term = str_replace( array( 'ي', 'ى', 'ك', 'ۀ', 'ة' ), array( 'ی', 'ی', 'ک', 'ه', 'ه' ), $term );
	$term = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}\x{200C}\x{200D}\x{200E}\x{200F}\x{FEFF}]/u', '', $term );
	$term = preg_replace( '/\s+/u', ' ', $term );
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $term, 0, 80 );
	}

	return substr( $term, 0, 80 );
}

/**
 * Resolve customer search through WooCommerce first and WordPress's public
 * user query API as a fallback for display/first/last names. Only IDs are
 * collected here; the existing customer serializer remains the single place
 * that controls which fields leave the endpoint.
 *
 * @param string $term Bounded search term.
 * @return array<int, int>
 */
function customers_search_ids( $term ) {
	$ids = array();
	if ( '' === $term ) {
		return $ids;
	}

	try {
		$store = new \WC_Customer_Data_Store();
		if ( method_exists( $store, 'search_customers' ) ) {
			$found = $store->search_customers( $term, 200 );
			foreach ( (array) $found as $customer_id ) {
				$customer_id = absint( $customer_id );
				if ( $customer_id > 0 ) {
					$ids[ $customer_id ] = $customer_id;
				}
			}
		}
	} catch ( \Throwable $exception ) {
		// The WordPress user query below can still resolve the search.
	}

	if ( function_exists( 'get_users' ) && empty( $ids ) ) {
		$user_query = array(
			'role'           => 'customer',
			'number'         => 200,
			'fields'         => 'ID',
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
		);
		foreach ( (array) get_users( $user_query ) as $customer_id ) {
			$customer_id = absint( $customer_id );
			if ( $customer_id > 0 ) {
				$ids[ $customer_id ] = $customer_id;
			}
		}

		$meta_query = array(
			'relation' => 'OR',
			array( 'key' => 'first_name', 'value' => $term, 'compare' => 'LIKE' ),
			array( 'key' => 'last_name', 'value' => $term, 'compare' => 'LIKE' ),
		);
		$meta_user_query = array(
			'role'       => 'customer',
			'number'     => 200,
			'fields'     => 'ID',
			'meta_query' => $meta_query,
		);
		foreach ( (array) get_users( $meta_user_query ) as $customer_id ) {
			$customer_id = absint( $customer_id );
			if ( $customer_id > 0 ) {
				$ids[ $customer_id ] = $customer_id;
			}
		}
	}

	return array_values( $ids );
}

/**
 * @param mixed $value Candidate public text.
 * @param int   $max_length Maximum length.
 * @return string
 */
function customers_clean_text( $value, $max_length = 255 ) {
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
 * @param mixed $value Candidate email.
 * @return string
 */
function customers_clean_email( $value ) {
	return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
}

/**
 * Call one allowlisted WooCommerce customer getter.
 *
 * @param object $customer WooCommerce customer.
 * @param string $method Getter method.
 * @return mixed
 */
function customers_getter_value( $customer, $method ) {
	if ( ! is_object( $customer ) || ! method_exists( $customer, $method ) ) {
		return '';
	}

	try {
		return $customer->{$method}();
	} catch ( \Throwable $exception ) {
		return '';
	}
}

/**
 * Convert a WooCommerce date object to a public ISO/RFC3339 value.
 *
 * @param mixed $date WooCommerce date object.
 * @return string|null
 */
function customers_date( $date ) {
	if ( ! $date ) {
		return null;
	}

	try {
		if ( function_exists( 'wc_rest_prepare_date_response' ) ) {
			$formatted = wc_rest_prepare_date_response( $date, true );
			return '' !== (string) $formatted ? customers_clean_text( $formatted, 40 ) : null;
		}

		if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
			return customers_clean_text( $date->date( DATE_ATOM ), 40 );
		}
	} catch ( \Throwable $exception ) {
		return null;
	}

	return null;
}

/**
 * Serialize only explicitly allowlisted address fields.
 *
 * @param object $customer WooCommerce customer.
 * @param string $type billing or shipping.
 * @return array<string, string>
 */
function serialize_customer_address( $customer, $type ) {
	$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
	if ( 'billing' === $type ) {
		$fields[] = 'email';
		$fields[] = 'phone';
	} elseif ( method_exists( $customer, 'get_shipping_phone' ) ) {
		$fields[] = 'phone';
	}

	$address = array();
	foreach ( $fields as $field ) {
		$method = 'get_' . $type . '_' . $field;
		$value  = customers_getter_value( $customer, $method );
		$address[ $field ] = 'email' === $field ? customers_clean_email( $value ) : customers_clean_text( $value, 255 );
	}

	return $address;
}

/**
 * @param object $customer WooCommerce customer.
 * @param bool   $detail Whether to include the address allowlist.
 * @return array<string, mixed>
 */
function serialize_customer( $customer, $detail = false ) {
	$first_name = customers_clean_text( customers_getter_value( $customer, 'get_first_name' ), 120 );
	$last_name  = customers_clean_text( customers_getter_value( $customer, 'get_last_name' ), 120 );
	$display    = customers_clean_text( customers_getter_value( $customer, 'get_display_name' ), 160 );
	if ( '' === $display ) {
		$display = trim( $first_name . ' ' . $last_name );
	}
	if ( '' === $display ) {
		$display = __( 'مشتری بدون نام', 'fandoogh-manager' );
	}

	$total_spent = customers_getter_value( $customer, 'get_total_spent' );
	if ( function_exists( 'wc_format_decimal' ) && is_scalar( $total_spent ) ) {
		$total_spent = wc_format_decimal( $total_spent, 2, false );
	}
	if ( ! is_scalar( $total_spent ) || '' === (string) $total_spent ) {
		$total_spent = '0';
	}

	$data = array(
		'id'                 => absint( customers_getter_value( $customer, 'get_id' ) ),
		'display_name'       => $display,
		'username'           => customers_clean_text( customers_getter_value( $customer, 'get_username' ), 80 ),
		'email'              => customers_clean_email( customers_getter_value( $customer, 'get_email' ) ),
		'phone'              => customers_clean_text( customers_getter_value( $customer, 'get_billing_phone' ), 80 ),
		'first_name'         => $first_name,
		'last_name'          => $last_name,
		'is_paying_customer' => (bool) customers_getter_value( $customer, 'get_is_paying_customer' ),
		'order_count'        => absint( customers_getter_value( $customer, 'get_order_count' ) ),
		'total_spent'        => customers_clean_text( (string) $total_spent, 60 ),
		'date_created'       => customers_date( customers_getter_value( $customer, 'get_date_created' ) ),
		'date_modified'      => customers_date( customers_getter_value( $customer, 'get_date_modified' ) ),
	);

	if ( $detail ) {
		$data['billing']  = serialize_customer_address( $customer, 'billing' );
		$data['shipping'] = serialize_customer_address( $customer, 'shipping' );
	}

	return $data;
}

/**
 * @param mixed $value Query result entry.
 * @return \WC_Customer|false
 */
function customers_normalize_query_entry( $value ) {
	if ( $value instanceof \WC_Customer ) {
		return $value;
	}

	$customer_id = is_object( $value ) && method_exists( $value, 'get_id' ) ? absint( $value->get_id() ) : absint( $value );
	if ( $customer_id < 1 ) {
		return false;
	}

	try {
		$customer = new \WC_Customer( $customer_id );
	} catch ( \Throwable $exception ) {
		return false;
	}

	return $customer instanceof \WC_Customer ? $customer : false;
}

/**
 * @param mixed $customer Candidate customer.
 * @return bool
 */
function customers_is_readable( $customer ) {
	if ( ! $customer instanceof \WC_Customer || ! method_exists( $customer, 'get_id' ) || absint( $customer->get_id() ) < 1 ) {
		return false;
	}

	$role = sanitize_key( (string) customers_getter_value( $customer, 'get_role' ) );
	return 'customer' === $role;
}

/**
 * List registered WooCommerce customers through the official data store.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_customers( $request ) {
	if ( ! customers_available() ) {
		return customers_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API مشتریان در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$page     = customers_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = customers_query_integer( $request->get_param( 'per_page' ), 20, 1, 50 );
	$search   = customers_search_term( $request->get_param( 'search' ) );

	$args = array(
		'page'     => $page,
		'per_page' => $per_page,
		'order'    => 'desc',
		'orderby'  => 'registered_date',
		'role'     => 'customer',
		'search'   => $search,
	);

	$skip_query = false;
	try {
		$store = new \WC_Customer_Data_Store();
		if ( '' !== $search ) {
			$search_ids      = customers_search_ids( $search );
			$args['search']  = '';
			if ( empty( $search_ids ) ) {
				// Avoid include=[0], which some data stores interpret as an
				// unrestricted query and would incorrectly return every customer.
				$skip_query = true;
			} else {
				$args['include'] = $search_ids;
			}
		}
		$result = $skip_query ? (object) array( 'customers' => array(), 'total' => 0, 'max_num_pages' => 0 ) : $store->query_customers( $args );
	} catch ( \Throwable $exception ) {
		return customers_error( 'fandoogh_customers_read_failed', __( 'خواندن فهرست مشتریان انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	$items       = array();
	$query_items = isset( $result->customers ) ? (array) $result->customers : array();
	foreach ( $query_items as $entry ) {
		$customer = customers_normalize_query_entry( $entry );
		if ( ! customers_is_readable( $customer ) ) {
			continue;
		}
		$items[] = serialize_customer( $customer, false );
	}

	$total      = isset( $result->total ) ? absint( $result->total ) : count( $items );
	$total_pages = isset( $result->max_num_pages ) ? max( 0, (int) $result->max_num_pages ) : ( $total ? (int) ceil( $total / $per_page ) : 0 );

	return customers_no_store_response(
		array(
			'data' => $items,
			'meta' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total_pages,
			),
		)
	);
}

/**
 * Return one registered WooCommerce customer with the address allowlist.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function get_customer_detail( $request ) {
	if ( ! customers_available() ) {
		return customers_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API مشتریان در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$customer_id = absint( $request->get_param( 'id' ) );
	if ( $customer_id < 1 ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	try {
		$customer = new \WC_Customer( $customer_id );
	} catch ( \Throwable $exception ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	if ( ! customers_is_readable( $customer ) ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	return customers_no_store_response( array( 'data' => serialize_customer( $customer, true ) ) );
}

/**
 * List a registered customer's orders through WooCommerce's CRUD query API.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_customer_orders( $request ) {
	if ( ! customers_available() || ! orders_available() ) {
		return customers_error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API تاریخچهٔ سفارش مشتری در دسترس نیست.', 'fandoogh-manager' ), 503 );
	}

	$customer_id = absint( $request->get_param( 'id' ) );
	if ( $customer_id < 1 ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	try {
		$customer = new \WC_Customer( $customer_id );
	} catch ( \Throwable $exception ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	if ( ! customers_is_readable( $customer ) ) {
		return customers_error( 'fandoogh_customer_not_found', __( 'مشتری پیدا نشد.', 'fandoogh-manager' ), 404 );
	}

	$page     = customers_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = customers_query_integer( $request->get_param( 'per_page' ), 20, 1, 50 );
	$allowed  = orders_allowed_statuses();
	$args     = array(
		'limit'       => $per_page,
		'page'        => $page,
		'paginate'    => true,
		'return'      => 'objects',
		'type'        => 'shop_order',
		'customer_id' => $customer_id,
		'status'      => array_keys( $allowed ),
		'orderby'     => 'date',
		'order'       => 'DESC',
	);

	try {
		$results = wc_get_orders( $args );
	} catch ( \Throwable $exception ) {
		return customers_error( 'fandoogh_customer_orders_read_failed', __( 'خواندن سفارش‌های مشتری انجام نشد.', 'fandoogh-manager' ), 500 );
	}

	if ( ! is_object( $results ) || ! isset( $results->orders, $results->total, $results->max_num_pages ) ) {
		return customers_error( 'fandoogh_customer_orders_query_invalid', __( 'پاسخ تاریخچهٔ سفارش مشتری معتبر نیست.', 'fandoogh-manager' ), 500 );
	}

	$items = array();
	foreach ( (array) $results->orders as $order ) {
		if ( orders_is_readable_order( $order ) ) {
			$items[] = serialize_order( $order, false );
		}
	}

	return customers_no_store_response(
		array(
			'data' => $items,
			'meta' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => absint( $results->total ),
				'total_pages' => absint( $results->max_num_pages ),
				'customer_id' => $customer_id,
			),
		)
	);
}
