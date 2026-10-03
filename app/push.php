<?php
/** Session-bound Web Push. No customer data leaves the site in a notification. */
namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

const OPERATIONS_PUSH_HOOK = 'fandoogh_operations_push';
const OPERATIONS_TICK_HOOK = 'fandoogh_operations_tick';
const OPERATIONS_BATCH_HOOK = 'fandoogh_operations_push_batch';
const OPERATIONS_FLUSH_HOOK = 'fandoogh_operations_flush_batch';

function push_table() { return compose_security_database()->prefix() . 'fandoogh_manager_push'; }

function maybe_ensure_push_schema() {
	if ( '1' === get_option( 'fandoogh_manager_push_schema', '' ) ) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = push_table();
	$collate = compose_security_database()->charsetCollate();
	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		session_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		endpoint_hash char(64) NOT NULL,
		subscription longtext NOT NULL,
		preferences text NOT NULL,
		pending tinyint(3) unsigned NOT NULL DEFAULT 0,
		last_sent bigint(20) unsigned NOT NULL DEFAULT 0,
		last_error varchar(40) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		UNIQUE KEY session_id (session_id),
		UNIQUE KEY endpoint_hash (endpoint_hash)
	) {$collate};" );
	$db = compose_security_database();
	if ( $table === $db->getVar( $db->prepare( 'SHOW TABLES LIKE %s', $db->escLike( $table ) ) ) ) {
		update_option( 'fandoogh_manager_push_schema', '1', false );
	}
}

function maybe_schedule_operations_tick() {
	if ( false === wp_next_scheduled( OPERATIONS_TICK_HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', OPERATIONS_TICK_HOOK ); }
}

function register_push_routes() {
	register_rest_route( REST_NAMESPACE, '/operations/push', array(
		array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\push_status', 'permission_callback' => __NAMESPACE__ . '\\operations_read_permission' ),
		array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\push_subscribe', 'permission_callback' => __NAMESPACE__ . '\\push_write_permission' ),
		array( 'methods' => 'DELETE', 'callback' => __NAMESPACE__ . '\\push_unsubscribe', 'permission_callback' => __NAMESPACE__ . '\\push_write_permission' ),
	) );
	register_rest_route( REST_NAMESPACE, '/operations/push-test', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\push_test', 'permission_callback' => __NAMESPACE__ . '\\push_write_permission' ) );
}

function push_write_permission( $request ) {
	$csrf = csrf_permission( $request );
	return is_wp_error( $csrf ) ? $csrf : operations_read_permission( $request );
}

function push_base64url( $bytes ) { return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); }
function push_decode_key( $value, $size ) {
	if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]{1,120}$/D', $value ) ) { return false; }
	$bytes = base64_decode( strtr( $value, '-_', '+/' ), true );
	return false !== $bytes && strlen( $bytes ) === $size ? $bytes : false;
}

/** Exact hosts and HTTPS prevent user-provided endpoints from becoming SSRF. */
function push_valid_endpoint( $value ) {
	if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) { return false; }
	$url = wp_parse_url( $value );
	if ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https' || isset( $url['user'], $url['pass'] ) || isset( $url['user'] ) || isset( $url['fragment'] ) || ( isset( $url['port'] ) && 443 !== $url['port'] ) || empty( $url['path'] ) ) { return false; }
	$host = strtolower( $url['host'] ?? '' );
	return in_array( $host, array( 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com' ), true ) || (bool) preg_match( '/^[a-z0-9-]+\.notify\.windows\.com$/D', $host );
}

function push_preferences( $raw ) {
	if ( ! is_array( $raw ) ) { $raw = array(); }
	$result = array();
	foreach ( array( 'new_order', 'low_stock', 'delayed_order' ) as $key ) { $result[ $key ] = ! array_key_exists( $key, $raw ) || true === $raw[ $key ]; }
	foreach ( array( 'quiet_start', 'quiet_end' ) as $key ) {
		$value = $raw[ $key ] ?? '';
		$result[ $key ] = is_string( $value ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value ) ? $value : '';
	}
	return $result;
}

function push_is_quiet( $preferences, $clock ) {
	$start = $preferences['quiet_start'] ?? '';
	$end = $preferences['quiet_end'] ?? '';
	if ( '' === $start || '' === $end || $start === $end ) { return false; }
	return $start < $end ? $clock >= $start && $clock < $end : $clock >= $start || $clock < $end;
}

/** Atomic option creation prevents concurrent requests from rotating VAPID keys. */
function push_vapid_keys() {
	$stored = get_option( 'fandoogh_manager_vapid', array() );
	if ( ! empty( $stored['public'] ) && ! empty( $stored['private'] ) ) { return $stored; }
	if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_derive' ) ) { return false; }
	$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
	if ( false === $key ) { return false; }
	$details = openssl_pkey_get_details( $key );
	$private = '';
	if ( empty( $details['ec']['x'] ) || ! openssl_pkey_export( $key, $private ) ) { return false; }
	$stored = array( 'public' => push_base64url( "\x04" . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT ) ), 'private' => $private );
	if ( ! add_option( 'fandoogh_manager_vapid', $stored, '', false ) ) { return get_option( 'fandoogh_manager_vapid', false ); }
	return $stored;
}

function push_current_row( $session ) {
	$db = compose_security_database();
	return $db->getRow( $db->prepare( 'SELECT * FROM ' . push_table() . ' WHERE session_id = %d', $session['id'] ) );
}

function push_status( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	$row = push_current_row( $session );
	$keys = push_vapid_keys();
	return inventory_response( array( 'data' => array( 'available' => (bool) $keys, 'public_key' => $keys ? $keys['public'] : '', 'subscribed' => (bool) $row, 'preferences' => push_preferences( $row ? json_decode( $row->preferences, true ) : array() ), 'last_error' => $row ? $row->last_error : '', 'timezone' => wp_timezone_string() ) ) );
}

function push_subscribe( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	$body = $request->get_json_params();
	$subscription = is_array( $body ) ? ( $body['subscription'] ?? array() ) : array();
	if ( ! is_array( $subscription ) || ! is_array( $subscription['keys'] ?? null ) || ! push_valid_endpoint( $subscription['endpoint'] ?? null ) || false === push_decode_key( $subscription['keys']['p256dh'] ?? null, 65 ) || false === push_decode_key( $subscription['keys']['auth'] ?? null, 16 ) ) {
		return inventory_error( 'fandoogh_push_invalid', __( 'اشتراک اعلان معتبر نیست.', 'fandoogh-manager' ) );
	}
	$client_key = push_decode_key( $subscription['keys']['p256dh'], 65 );
	if ( "\x04" !== $client_key[0] || ! push_vapid_keys() ) { return inventory_error( 'fandoogh_push_unavailable', __( 'رمزنگاری اعلان روی میزبان آماده نیست.', 'fandoogh-manager' ), 503 ); }
	$subscription = array( 'endpoint' => $subscription['endpoint'], 'keys' => array( 'p256dh' => $subscription['keys']['p256dh'], 'auth' => $subscription['keys']['auth'] ) );
	$preferences = push_preferences( $body['preferences'] ?? array() );
	$db = compose_security_database();
	// A browser subscription belongs to its latest authenticated account/session.
	// Remove this session's previous endpoint so two different unique keys cannot conflict.
	$endpoint_hash = hash( 'sha256', $subscription['endpoint'] );
	$db->query( $db->prepare( 'DELETE FROM ' . push_table() . ' WHERE session_id = %d AND endpoint_hash <> %s', $session['id'], $endpoint_hash ) );
	$result = $db->query( $db->prepare( 'INSERT INTO ' . push_table() . ' (session_id,user_id,endpoint_hash,subscription,preferences) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE session_id=VALUES(session_id),user_id=VALUES(user_id),subscription=VALUES(subscription),preferences=VALUES(preferences),last_error=\'\'', $session['id'], $session['user']->ID, $endpoint_hash, wp_json_encode( $subscription ), wp_json_encode( $preferences ) ) );
	if ( false === $result ) { return inventory_error( 'fandoogh_push_save_failed', __( 'ذخیرهٔ اعلان انجام نشد؛ دوباره تلاش کنید.', 'fandoogh-manager' ), 503 ); }
	record_current_session_audit( 'push_subscribed', 'session', $session['id'] );
	return push_status( $request );
}

function push_unsubscribe( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	$db = compose_security_database();
	$result = $db->query( $db->prepare( 'DELETE FROM ' . push_table() . ' WHERE session_id = %d', $session['id'] ) );
	if ( false === $result ) { return inventory_error( 'fandoogh_push_delete_failed', __( 'خاموش‌کردن اعلان انجام نشد.', 'fandoogh-manager' ), 503 ); }
	record_current_session_audit( 'push_unsubscribed', 'session', $session['id'] );
	return inventory_response( array( 'data' => array( 'subscribed' => false ) ) );
}

/** ASN.1 ECDSA signature to JOSE's fixed-width R || S. */
function push_jose_signature( $der ) {
	if ( strlen( $der ) < 8 || ord( $der[0] ) !== 48 || ord( $der[2] ) !== 2 ) { return false; }
	$r_len = ord( $der[3] );
	$s_at = 4 + $r_len;
	if ( $s_at + 2 > strlen( $der ) || ord( $der[ $s_at ] ) !== 2 ) { return false; }
	$r = ltrim( substr( $der, 4, $r_len ), "\x00" );
	$s = ltrim( substr( $der, $s_at + 2, ord( $der[ $s_at + 1 ] ) ), "\x00" );
	if ( strlen( $r ) > 32 || strlen( $s ) > 32 ) { return false; }
	return str_pad( $r, 32, "\x00", STR_PAD_LEFT ) . str_pad( $s, 32, "\x00", STR_PAD_LEFT );
}

/** RFC 8291 aes128gcm single-record payload. PHP 7.4 OpenSSL ECDH. */
function push_encrypt( $subscription, $payload ) {
	$client = push_decode_key( $subscription['keys']['p256dh'] ?? null, 65 );
	$auth = push_decode_key( $subscription['keys']['auth'] ?? null, 16 );
	if ( false === $client || false === $auth || strlen( $payload ) > 3000 ) { return false; }
	$peer = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $client ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
	if ( false === $key ) { return false; }
	$shared = @openssl_pkey_derive( $peer, $key, 32 );
	if ( false === $shared ) { return false; }
	$details = openssl_pkey_get_details( $key );
	$server = "\x04" . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
	$salt = random_bytes( 16 );
	$ikm = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\x00" . $client . $server, $auth );
	$cek = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
	$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );
	$tag = '';
	$cipher = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
	return false === $cipher ? false : $salt . pack( 'N', 4096 ) . chr( 65 ) . $server . $cipher . $tag;
}

function push_deliver( $subscription, $kind ) {
	$keys = push_vapid_keys();
	if ( ! $keys || ! push_valid_endpoint( $subscription['endpoint'] ?? null ) ) { return 'unavailable'; }
	$endpoint = wp_parse_url( $subscription['endpoint'] );
	$audience = 'https://' . $endpoint['host'];
	$jwt = push_base64url( '{"typ":"JWT","alg":"ES256"}' ) . '.' . push_base64url( wp_json_encode( array( 'aud' => $audience, 'exp' => time() + 3600, 'sub' => home_url( '/' ) ) ) );
	$signature = '';
	if ( ! openssl_sign( $jwt, $signature, $keys['private'], OPENSSL_ALGO_SHA256 ) ) { return 'crypto'; }
	$signature = push_jose_signature( $signature );
	if ( false === $signature ) { return 'crypto'; }
	$labels = array( 'new_order' => 'سفارش تازه‌ای برای بررسی ثبت شده است.', 'low_stock' => 'موجودی یک کالا به حد هشدار رسیده است.', 'delayed_order' => 'سفارش‌هایی بیش از ۴۸ ساعت منتظر ارسال مانده‌اند.', 'test' => 'اعلان‌های این گوشی آماده است.' );
	if ( ! isset( $labels[ $kind ] ) ) { return 'invalid-kind'; }
	try { $body = push_encrypt( $subscription, wp_json_encode( array( 'title' => 'فندوق · هشدار فروشگاه', 'body' => $labels[ $kind ], 'kind' => $kind ) ) ); } catch ( \Throwable $error ) { return 'crypto'; }
	if ( false === $body ) { return 'crypto'; }
	$response = wp_safe_remote_post( $subscription['endpoint'], array( 'timeout' => 8, 'redirection' => 0, 'headers' => array( 'Authorization' => 'vapid t=' . $jwt . '.' . push_base64url( $signature ) . ', k=' . $keys['public'], 'TTL' => '300', 'Urgency' => 'normal', 'Topic' => 'fandoogh-' . $kind, 'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream' ), 'body' => $body, 'data_format' => 'body' ) );
	if ( is_wp_error( $response ) ) { return 'network'; }
	$status = wp_remote_retrieve_response_code( $response );
	return $status >= 200 && $status < 300 ? '' : ( in_array( $status, array( 404, 410 ), true ) ? 'expired' : 'http-' . $status );
}

/** Re-check session expiry, inactivity, capabilities and intersected scopes on every send. */
function push_row_scopes( $row ) {
	$db = compose_security_database();
	$session = $db->getRow( $db->prepare( 'SELECT * FROM ' . security_sessions_table() . ' WHERE id = %d AND user_id = %d', $row->session_id, $row->user_id ) );
	if ( ! $session || 'active' !== $session->status || security_timestamp_from_mysql( $session->expires_at ) <= time() || security_timestamp_from_mysql( $session->last_seen_at ) + SESSION_IDLE_TTL <= time() || ( ! user_can( $row->user_id, 'manage_woocommerce' ) && ! user_can( $row->user_id, 'manage_options' ) ) ) { return array(); }
	return intersect_pairing_scopes( json_decode( $session->scopes, true ), scopes_for_user( $row->user_id ) );
}

function push_kind_allowed( $kind, $scopes ) {
	return session_has_scope( 'products.read', $scopes ) && session_has_scope( 'low_stock' === $kind ? 'inventory.read' : 'orders.read', $scopes );
}

function push_kind_bit( $kind ) {
	$bits = array( 'new_order' => 1, 'low_stock' => 2, 'delayed_order' => 4 );
	return $bits[ $kind ] ?? 0;
}

function push_mark_pending( $row, $kind ) {
	$db = compose_security_database();
	$db->query( $db->prepare( 'UPDATE ' . push_table() . ' SET pending = pending | %d WHERE id = %d', push_kind_bit( $kind ), $row->id ) );
}

function push_send_row( $row, $kind, $test = false ) {
	$db = compose_security_database();
	$scopes = push_row_scopes( $row );
	if ( ! session_has_scope( 'products.read', $scopes ) ) {
		$db->query( $db->prepare( 'DELETE FROM ' . push_table() . ' WHERE id = %d', $row->id ) );
		return 'session-expired';
	}
	$preferences = push_preferences( json_decode( $row->preferences, true ) );
	if ( ! $test && ( empty( $preferences[ $kind ] ) || ! push_kind_allowed( $kind, $scopes ) ) ) { return 'disabled'; }
	if ( ! $test && push_is_quiet( $preferences, wp_date( 'H:i' ) ) ) {
		push_mark_pending( $row, $kind );
		return 'quiet';
	}
	// Atomic rate guard: one notification per device/minute, pending types retried hourly.
	$claimed = $db->query( $db->prepare( 'UPDATE ' . push_table() . ' SET last_sent = %d WHERE id = %d AND last_sent < %d', time(), $row->id, time() - 60 ) );
	if ( ! $claimed ) {
		if ( ! $test ) { push_mark_pending( $row, $kind ); }
		return 'rate-limit';
	}
	$error = push_deliver( json_decode( $row->subscription, true ), $kind );
	if ( ! $test && '' !== $error && 'expired' !== $error ) { push_mark_pending( $row, $kind ); }
	if ( 'expired' === $error ) { $db->query( $db->prepare( 'DELETE FROM ' . push_table() . ' WHERE id = %d', $row->id ) ); }
	else { $db->update( push_table(), array( 'last_error' => $error ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) ); }
	return $error;
}

function push_test( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) { return $session; }
	$row = push_current_row( $session );
	if ( ! $row ) { return inventory_error( 'fandoogh_push_missing', __( 'ابتدا اعلان این گوشی را فعال کنید.', 'fandoogh-manager' ), 404 ); }
	$error = push_send_row( $row, 'test', true );
	if ( '' !== $error ) { return inventory_error( 'fandoogh_push_delivery', 'rate-limit' === $error ? __( 'یک دقیقه بعد دوباره امتحان کنید.', 'fandoogh-manager' ) : __( 'ارسال اعلان انجام نشد؛ اتصال میزبان و اشتراک گوشی را بررسی کنید.', 'fandoogh-manager' ), 'rate-limit' === $error ? 429 : 503 ); }
	return inventory_response( array( 'data' => array( 'sent' => true ) ) );
}

/** Queue lightweight identifiers; checkout and stock writes never wait for a push service. */
function queue_operations_push( $kind, $id ) {
	$args = array( $kind, absint( $id ) );
	if ( ! wp_next_scheduled( OPERATIONS_PUSH_HOOK, $args ) ) { wp_schedule_single_event( time() + 15, OPERATIONS_PUSH_HOOK, $args ); }
}
function operations_new_order( $id ) { queue_operations_push( 'new_order', $id ); }
function operations_store_order( $order ) { if ( is_object( $order ) ) { operations_new_order( $order->get_id() ); } }
function operations_stock_changed( $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { return; }
	$key = 'fandoogh_low_stock_' . $product->get_id();
	if ( ! operations_low_stock( $product ) ) { delete_transient( $key ); return; }
	if ( ! get_transient( $key ) ) { set_transient( $key, true, DAY_IN_SECONDS ); queue_operations_push( 'low_stock', $product->get_id() ); }
}

function operations_stock_status_changed( $id, $status, $product = null ) {
	if ( ! is_object( $product ) ) { $product = compose_product_repository()->findById( (int) $id ); }
	operations_stock_changed( $product );
}

function operations_product_updated( $product, $properties ) {
	if ( array_intersect( (array) $properties, array( 'stock_quantity', 'stock_status', 'manage_stock', 'low_stock_amount' ) ) ) { operations_stock_changed( $product ); }
}

function dispatch_operations_push( $kind, $id = 0 ) {
	if ( ! in_array( $kind, array( 'new_order', 'low_stock', 'delayed_order' ), true ) ) { return; }
	if ( 'new_order' === $kind ) {
		$order = compose_order_repository()->findById( (int) $id );
		if ( ! $order || ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing', 'completed' ), true ) ) { return; }
		$created = $order->get_date_created();
		if ( ! $created || $created->getTimestamp() < time() - DAY_IN_SECONDS ) { return; }
		if ( $order->get_meta( '_fandoogh_push_new_order', true ) ) { return; }
		$order->update_meta_data( '_fandoogh_push_new_order', gmdate( DATE_ATOM ) );
		$order->save_meta_data();
	} elseif ( 'low_stock' === $kind ) {
		$product = compose_product_repository()->findById( (int) $id );
		if ( ! $product || ! operations_low_stock( $product ) ) { return; }
	}
	$db = compose_security_database();
	$upper = (int) $db->getVar( 'SELECT MAX(id) FROM ' . push_table() );
	operations_push_batch( $kind, 0, $upper );
}

/** Five outbound requests per cron job; continue using stable IDs. */
function operations_push_batch( $kind, $after, $upper ) {
	if ( ! push_kind_bit( $kind ) ) { return; }
	$db = compose_security_database();
	$rows = $db->getResults( $db->prepare( 'SELECT * FROM ' . push_table() . ' WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 5', $after, $upper ) );
	foreach ( (array) $rows as $row ) { push_send_row( $row, $kind ); }
	if ( count( (array) $rows ) === 5 ) { $last = end( $rows ); wp_schedule_single_event( time() + 10, OPERATIONS_BATCH_HOOK, array( $kind, (int) $last->id, (int) $upper ) ); }
}

function operations_flush_batch( $after, $upper ) {
	$db = compose_security_database();
	$rows = $db->getResults( $db->prepare( 'SELECT * FROM ' . push_table() . ' WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 5', $after, $upper ) );
	foreach ( (array) $rows as $row ) {
		if ( ! session_has_scope( 'products.read', push_row_scopes( $row ) ) ) { $db->query( $db->prepare( 'DELETE FROM ' . push_table() . ' WHERE id = %d', $row->id ) ); continue; }
		foreach ( array( 'new_order', 'low_stock', 'delayed_order' ) as $kind ) {
			if ( ! ( (int) $row->pending & push_kind_bit( $kind ) ) ) { continue; }
			$result = push_send_row( $row, $kind );
			if ( in_array( $result, array( '', 'disabled' ), true ) ) { $db->query( $db->prepare( 'UPDATE ' . push_table() . ' SET pending = pending & %d WHERE id = %d', 7 ^ push_kind_bit( $kind ), $row->id ) ); }
		}
	}
	if ( count( (array) $rows ) === 5 ) { $last = end( $rows ); wp_schedule_single_event( time() + 10, OPERATIONS_FLUSH_HOOK, array( (int) $last->id, (int) $upper ) ); }
}

function operations_hourly_tick() {
	$db = compose_security_database();
	$upper = (int) $db->getVar( 'SELECT MAX(id) FROM ' . push_table() );
	if ( $upper ) { wp_schedule_single_event( time() + 1, OPERATIONS_FLUSH_HOOK, array( 0, $upper ) ); }
	// Search all processing pages incrementally; do not repeatedly scan only page 1.
	if ( ! compose_order_repository()->isAvailable() ) { return; }
	$page = max( 1, (int) get_option( 'fandoogh_operations_delay_page', 1 ) );
	try {
		$result = compose_order_repository()->query( array( 'status' => 'processing', 'date_created' => '<' . ( time() - 2 * DAY_IN_SECONDS ), 'limit' => 100, 'page' => $page, 'paginate' => true, 'order' => 'ASC', 'orderby' => 'date' ) );
		if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) ) { return; }
		update_option( 'fandoogh_operations_delay_page', $page >= $result->max_num_pages ? 1 : $page + 1, false );
		foreach ( $result->orders as $order ) {
			if ( method_exists( $order, 'needs_shipping_address' ) && ! $order->needs_shipping_address() ) { continue; }
			$shipment = shipping_read_snapshot( $order );
			$paid = $order->get_date_paid() ?: $order->get_date_created();
			if ( ! $paid || $paid->getTimestamp() > time() - 2 * DAY_IN_SECONDS || ! empty( $shipment['shipped_at'] ) || ! in_array( $shipment['status'], array( 'pending', 'ready', 'failed' ), true ) ) { continue; }
			if ( get_transient( 'fandoogh_delay_digest' ) ) { break; }
			set_transient( 'fandoogh_delay_digest', true, DAY_IN_SECONDS );
			dispatch_operations_push( 'delayed_order' );
			break;
		}
	} catch ( \Throwable $error ) { /* Retry the same page at the next tick. */ }
}
