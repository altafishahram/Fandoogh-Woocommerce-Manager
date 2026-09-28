<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/security.php';

const SECURITY_SCHEMA_OPTION = 'fandoogh_manager_security_schema';
const SECURITY_SCHEMA_VERSION = '3';
const SESSION_COOKIE_NAME = 'fandoogh_manager_session';
const SESSION_TTL = 2592000;
// Keep a device signed in across normal overnight gaps while retaining the
// separate 30-day absolute session lifetime and explicit revoke controls.
const SESSION_IDLE_TTL = 604800;
const PAIRING_TTL = 600;
const PAIRING_RATE_LIMIT = 5;
const PAIRING_RATE_WINDOW = 900;
const CSRF_HEADER_NAME = 'X-Fandoogh-CSRF';
const CSRF_GRACE_TTL = 90;
const ACCESS_POLICY_META_KEY = 'fandoogh_manager_access_policy';
const SESSION_ALERT_OPTION_KEY = 'fandoogh_manager_session_alert_state';
const SESSION_ALERT_CRON_HOOK = 'fandoogh_manager_send_session_alert';
const SECURITY_CLEANUP_HOOK = 'fandoogh_manager_cleanup';
const SECURITY_PAIRING_RETENTION = 86400;
const SECURITY_SESSION_RETENTION = 7776000;
const SECURITY_AUDIT_RETENTION = 15552000;
const SECURITY_IDEMPOTENCY_RETENTION = 86400;
const SECURITY_CLEANUP_BATCH = 500;

/**
 * Install the two small site-local tables used for one-time pairing and
 * opaque server-side sessions. No password, pairing code, or session secret
 * is stored in plaintext.
 *
 * @return void
 */
function ensure_security_schema() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$database        = compose_security_database();
	$charset_collate = $database->charsetCollate();
	$pairings_table  = security_pairings_table();
	$sessions_table  = security_sessions_table();
	$audit_table     = security_audit_table();

	$pairings_sql = "CREATE TABLE {$pairings_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		token_hash char(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		scopes longtext NOT NULL,
		created_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		used_at datetime NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
		status varchar(20) NOT NULL DEFAULT 'active',
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		KEY status_expires (status, expires_at),
		KEY user_id (user_id)
	) {$charset_collate};";

	$sessions_sql = "CREATE TABLE {$sessions_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		session_hash char(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		device_hash char(64) NOT NULL,
		device_label varchar(120) NOT NULL DEFAULT '',
		scopes longtext NOT NULL,
		csrf_hash char(64) NOT NULL,
		csrf_previous_hash char(64) NOT NULL DEFAULT '',
		csrf_previous_expires_at datetime NULL,
		created_at datetime NOT NULL,
		last_seen_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		revoked_at datetime NULL,
		status varchar(20) NOT NULL DEFAULT 'active',
		PRIMARY KEY  (id),
		UNIQUE KEY session_hash (session_hash),
		KEY user_status (user_id, status),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	$audit_sql = "CREATE TABLE {$audit_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		session_id bigint(20) unsigned NOT NULL DEFAULT 0,
		event_type varchar(80) NOT NULL,
		resource_type varchar(50) NOT NULL DEFAULT '',
		resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
		device_label varchar(120) NOT NULL DEFAULT '',
		context longtext NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_created (user_id, created_at),
		KEY event_created (event_type, created_at),
		KEY session_id (session_id)
	) {$charset_collate};";

	dbDelta( $pairings_sql );
	dbDelta( $sessions_sql );
	dbDelta( $audit_sql );
	compose_security_state_store()->updateOption( SECURITY_SCHEMA_OPTION, SECURITY_SCHEMA_VERSION, false );
}

/**
 * @return void
 */
function maybe_ensure_security_schema() {
	if ( SECURITY_SCHEMA_VERSION !== (string) compose_security_state_store()->getOption( SECURITY_SCHEMA_OPTION, '' ) ) {
		ensure_security_schema();
	}
}

/**
 * Keep active installations on the same lifecycle as a newly activated one.
 *
 * @return void
 */
function maybe_schedule_security_cleanup() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( false === wp_next_scheduled( SECURITY_CLEANUP_HOOK ) ) {
		$delay = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		wp_schedule_event( time() + $delay, 'daily', SECURITY_CLEANUP_HOOK );
	}
}

/**
 * Prune only plugin-owned security and idempotency data. WooCommerce orders,
 * products, and customer records are never included in this maintenance job.
 *
 * @return void
 */
function cleanup_security_data() {
	$database = compose_security_database();

	$now                 = time();
	$pairing_cutoff      = security_mysql_from_timestamp( $now - SECURITY_PAIRING_RETENTION );
	$session_cutoff      = security_mysql_from_timestamp( $now - SECURITY_SESSION_RETENTION );
	$session_idle_cutoff = security_mysql_from_timestamp( $now - SECURITY_SESSION_RETENTION - SESSION_IDLE_TTL );
	$audit_cutoff        = security_mysql_from_timestamp( $now - SECURITY_AUDIT_RETENTION );

	$database->query(
		$database->prepare(
			'DELETE FROM ' . security_pairings_table() . ' WHERE expires_at < %s LIMIT ' . SECURITY_CLEANUP_BATCH,
			$pairing_cutoff
		)
	);

	$database->query(
		$database->prepare(
			'DELETE FROM ' . security_sessions_table() . ' WHERE ((status <> %s AND COALESCE(revoked_at, expires_at, last_seen_at, created_at) < %s) OR (status = %s AND (expires_at < %s OR last_seen_at < %s))) LIMIT ' . SECURITY_CLEANUP_BATCH,
			'active',
			$session_cutoff,
			'active',
			$session_cutoff,
			$session_idle_cutoff
		)
	);

	$database->query(
		$database->prepare(
			'DELETE FROM ' . security_audit_table() . ' WHERE created_at < %s LIMIT ' . SECURITY_CLEANUP_BATCH,
			$audit_cutoff
		)
	);

	cleanup_security_idempotency_options( $now - SECURITY_IDEMPOTENCY_RETENTION );

	$state       = compose_security_state_store()->getOption( SESSION_ALERT_OPTION_KEY, array() );
	$clean_state = sanitize_session_alert_state( $state );
	if ( $clean_state !== $state ) {
		compose_security_state_store()->updateOption( SESSION_ALERT_OPTION_KEY, $clean_state, false );
	}
}

/**
 * Delete expired dynamic option claims through WordPress's option API after
 * enumerating only prefixes owned by this plugin.
 *
 * @param int $cutoff Unix timestamp.
 * @return void
 */
function cleanup_security_idempotency_options( $cutoff ) {
	$database = compose_security_database();

	$prefixes = array(
		'fandoogh_order_create_',
		'fandoogh_refund_',
		'fandoogh_bulk_price_claim_',
	);

	foreach ( $prefixes as $prefix ) {
		$option_names = $database->getCol(
			$database->prepare(
				'SELECT option_name FROM ' . $database->optionsTable() . ' WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT ' . SECURITY_CLEANUP_BATCH,
				$database->escLike( $prefix ) . '%'
			)
		);

		foreach ( (array) $option_names as $option_name ) {
			$value     = compose_security_state_store()->getOption( $option_name, false );
			$timestamp = 0;
			if ( is_array( $value ) ) {
				$timestamp = max(
					absint( isset( $value['completed_at'] ) ? $value['completed_at'] : 0 ),
					absint( isset( $value['created_at'] ) ? $value['created_at'] : 0 )
				);
			}

			if ( $timestamp < absint( $cutoff ) ) {
				compose_security_state_store()->deleteOption( $option_name );
			}
		}
	}
}

/**
 * @return string
 */
function security_pairings_table() {
	return compose_security_database()->prefix() . 'fandoogh_manager_pairings';
}

/**
 * @return string
 */
function security_sessions_table() {
	return compose_security_database()->prefix() . 'fandoogh_manager_sessions';
}

/**
 * @return string
 */
function security_audit_table() {
	return compose_security_database()->prefix() . 'fandoogh_manager_audit';
}

/**
 * @param int $bytes Number of random bytes.
 * @return string
 */
function security_random_token( $bytes ) {
	try {
		return bin2hex( random_bytes( $bytes ) );
	} catch ( \Throwable $exception ) {
		return wp_generate_password( max( 16, $bytes * 2 ), false, false );
	}
}

/**
 * Hash an opaque secret with a site-local key. Rotating WordPress salts
 * invalidates existing pairing/session records, which is a safe failure mode.
 *
 * @param string $secret Secret to hash.
 * @return string
 */
function security_hash_secret( $secret ) {
	return hash_hmac( 'sha256', (string) $secret, wp_salt( 'auth' ) );
}

/**
 * @return string
 */
function security_now_mysql() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function security_mysql_from_timestamp( $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', absint( $timestamp ) );
}

/**
 * Parse a database timestamp that this plugin writes in UTC.
 *
 * @param mixed $value UTC timestamp in MySQL format.
 * @return int
 */
function security_timestamp_from_mysql( $value ) {
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', trim( (string) $value ), new \DateTimeZone( 'UTC' ) );

	return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : 0;
}

/**
 * @return bool
 */
function security_is_secure_transport_allowed() {
	if ( function_exists( 'is_ssl' ) && is_ssl() ) {
		return true;
	}

	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$host = strtolower( trim( (string) $host, '[]' ) );

	return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
}

/**
 * Require a browser request from this WordPress origin for authentication and
 * state-changing operations. Same-origin GET requests do not need this check.
 *
 * @return bool
 */
function security_is_same_origin_request() {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	if ( '' === $origin && isset( $_SERVER['HTTP_REFERER'] ) ) {
		$origin = trim( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}

	if ( '' === $origin ) {
		return false;
	}

	$expected = origin_safe_url( home_url( '/' ) );
	$received = origin_safe_url( $origin );

	return '' !== $expected && hash_equals( untrailingslashit( $expected ), untrailingslashit( $received ) );
}

/**
 * @param mixed $value Candidate device/user-provided label.
 * @param int   $max_length Maximum length.
 * @return string
 */
function security_clean_label( $value, $max_length = 120 ) {
	$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
}

/**
 * @param string $code User-entered code.
 * @return string
 */
function normalize_pairing_code( $code ) {
	$code = strtr(
		(string) $code,
		array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		)
	);
	$code = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $code ) );
	return substr( $code, 0, 32 );
}

/**
 * @param string $code Raw digits.
 * @return string
 */
function format_pairing_code( $code ) {
	$code = preg_replace( '/[^0-9]/', '', (string) $code );
	return implode( '-', str_split( $code, 4 ) );
}

/**
 * @return array<string, array<string, bool>>
 */
function default_pairing_scopes() {
	return array(
		'products' => array(
			'read'   => true,
			'write'  => true,
			'create' => true,
			'update' => true,
		),
		'categories' => array(
			'read'  => true,
			'write' => true,
		),
		'customers' => array(
			'read'   => true,
			'write'  => true,
			'create' => true,
			'update' => true,
		),
		'devices' => array(
			'read'   => true,
			'revoke' => true,
		),
		'audit'   => array(
			'read' => true,
		),
		'orders'   => array(
			'read'          => true,
			'create'        => true,
			'update_status' => true,
			'update_shipment' => true,
		'add_note'      => true,
		'refund'        => true,
	),
	'coupons'  => array(
		'read'  => true,
		'write' => true,
	),
	'reviews'  => array(
		'read'  => true,
		'write' => true,
	),
	'inventory' => array(
		'read'  => true,
		'write' => true,
	),
	'analytics' => array(
		'read' => true,
	),
	'admin'    => array(
		'major_changes' => true,
	),
	'media'    => array(
			'upload' => true,
		),
	);
}

/**
 * Return the bounded per-user web-app access policy. The policy is stored in
 * user meta rather than in the public settings option so each user's access
 * can be changed without exposing identities or permissions through REST.
 * WordPress administrators retain a protected full-access profile so the
 * site's primary operator cannot lock themselves out of the recovery panel.
 *
 * @return array<string, bool>
 */
function default_user_access_policy() {
	return array(
		'enabled'         => true,
		'product_create'  => true,
		'product_edit'    => true,
		'analytics_read'  => true,
		'major_changes'   => true,
	);
}

/**
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function user_access_policy_is_protected( $user_id ) {
	return user_can( absint( $user_id ), 'manage_options' );
}

/**
 * @param int $user_id WordPress user ID.
 * @return array<string, bool>
 */
function get_user_access_policy( $user_id ) {
	$policy = default_user_access_policy();
	$user_id = absint( $user_id );
	if ( 0 === $user_id ) {
		$policy['enabled'] = false;
		return $policy;
	}

	if ( user_access_policy_is_protected( $user_id ) ) {
		return $policy;
	}

	$stored = get_user_meta( $user_id, ACCESS_POLICY_META_KEY, true );
	if ( is_array( $stored ) ) {
		foreach ( $policy as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$policy[ $key ] = ! empty( $stored[ $key ] );
			}
		}
	}

	return $policy;
}

/**
 * Persist a bounded policy and return the resulting value. Administrators
 * are intentionally kept at the protected full-access policy.
 *
 * @param int   $user_id WordPress user ID.
 * @param mixed $raw_policy Candidate policy.
 * @return array<string, bool>
 */
function update_user_access_policy( $user_id, $raw_policy ) {
	$user_id = absint( $user_id );
	$policy  = default_user_access_policy();
	if ( ! is_array( $raw_policy ) ) {
		$raw_policy = array();
	}

	foreach ( $policy as $key => $default ) {
		$policy[ $key ] = ! empty( $raw_policy[ $key ] );
	}

	if ( user_access_policy_is_protected( $user_id ) ) {
		$policy = default_user_access_policy();
	}

	if ( $user_id > 0 ) {
		update_user_meta( $user_id, ACCESS_POLICY_META_KEY, $policy );
	}

	return $policy;
}

/**
 * @param int $user_id WordPress user ID.
 * @return array<string, array<string, bool>>
 */
function scopes_for_user( $user_id ) {
	if ( ! user_can( $user_id, 'edit_products' ) && ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return array();
	}

	$access_policy = get_user_access_policy( $user_id );
	if ( empty( $access_policy['enabled'] ) ) {
		return array();
	}

	$scopes = default_pairing_scopes();

	if ( empty( get_settings()['analytics_enabled'] ) ) {
		unset( $scopes['analytics'] );
	}

	if ( ! user_can( $user_id, 'upload_files' ) ) {
		unset( $scopes['media'] );
	}

	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		unset( $scopes['orders'] );
		unset( $scopes['customers'] );
		unset( $scopes['devices'] );
		unset( $scopes['coupons'] );
		unset( $scopes['reviews'] );
		unset( $scopes['inventory'] );
		unset( $scopes['admin'] );
	}

	if ( ! user_can( $user_id, 'manage_options' ) ) {
		unset( $scopes['audit'] );
	}

	if ( empty( $access_policy['product_create'] ) ) {
		unset( $scopes['products']['create'] );
	}

	if ( empty( $access_policy['product_edit'] ) ) {
		unset( $scopes['products']['update'] );
	}

	if ( empty( $access_policy['analytics_read'] ) ) {
		unset( $scopes['analytics'] );
	}

	if ( empty( $access_policy['major_changes'] ) ) {
		unset( $scopes['admin']['major_changes'] );
	}

	return $scopes;
}

/**
 * Return the WordPress users eligible to receive a Fandoogh Manager pairing
 * code. Only a limited public identity projection is queried for the admin
 * selector; passwords, email addresses and user meta are not rendered.
 *
 * @return array<int, object>
 */
function pairing_target_users() {
	$users = get_users(
		array(
			'fields'  => array( 'ID', 'user_login', 'display_name' ),
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 0,
		)
	);

	$eligible = array();
	foreach ( (array) $users as $user ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) || empty( scopes_for_user( absint( $user->ID ) ) ) ) {
			continue;
		}

		$eligible[] = $user;
	}

	return $eligible;
}

/**
 * Resolve one eligible pairing target. The same capability boundary is used
 * for the admin selector and for the final code issuance.
 *
 * @param int $user_id Candidate WordPress user ID.
 * @return \WP_User|null
 */
function pairing_target_user( $user_id ) {
	$user_id = absint( $user_id );
	$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

	if ( ! $user instanceof \WP_User || empty( scopes_for_user( $user_id ) ) ) {
		return null;
	}

	return $user;
}

/**
 * Keep the scopes captured when the code was issued bounded by the user's
 * current capabilities. This prevents a role downgrade after issuance from
 * leaving stale privileges in a still-valid one-time code.
 *
 * @param mixed $issued_scopes Scopes captured in the pairing record.
 * @param mixed $current_scopes Scopes currently allowed for the user.
 * @return array<string, array<string, bool>>
 */
function intersect_pairing_scopes( $issued_scopes, $current_scopes ) {
	if ( ! is_array( $issued_scopes ) || ! is_array( $current_scopes ) ) {
		return array();
	}

	$scopes = array();
	foreach ( $issued_scopes as $group => $group_scopes ) {
		if ( ! is_string( $group ) || ! is_array( $group_scopes ) || ! isset( $current_scopes[ $group ] ) || ! is_array( $current_scopes[ $group ] ) ) {
			continue;
		}

		foreach ( $group_scopes as $scope => $enabled ) {
			if ( is_string( $scope ) && ! empty( $enabled ) && ! empty( $current_scopes[ $group ][ $scope ] ) ) {
				$scopes[ $group ][ $scope ] = true;
			}
		}
	}

	return $scopes;
}

/**
 * @param string $scope Dot-separated scope name.
 * @param array<string, mixed> $scopes Scope map.
 * @return bool
 */
function session_has_scope( $scope, $scopes ) {
	$parts = explode( '.', (string) $scope, 2 );
	if ( 2 !== count( $parts ) || ! isset( $scopes[ $parts[0] ] ) || ! is_array( $scopes[ $parts[0] ] ) ) {
		return false;
	}

	return ! empty( $scopes[ $parts[0] ][ $parts[1] ] );
}

/**
 * Check the high-impact operation policy using the already-intersected
 * session scopes. A session must never gain a privilege after it was issued:
 * the current user policy is applied in get_session_context(), while this
 * check preserves the session snapshot as the second half of the boundary.
 *
 * @param array<string, mixed> $session Session context.
 * @return bool
 */
function session_has_major_changes_access( $session ) {
	if ( ! is_array( $session ) ) {
		return false;
	}
	return session_has_scope( 'admin.major_changes', isset( $session['scopes'] ) ? $session['scopes'] : array() );
}

/**
 * @param mixed $payload Response payload.
 * @return \WP_REST_Response
 */
function security_no_store_response( $payload ) {
	$response = rest_ensure_response( $payload );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	return $response;
}

/**
 * Keep audit event names closed over the operations the plugin can perform.
 *
 * @return array<string, bool>
 */
function allowed_audit_events() {
	return array_fill_keys(
		array(
			'pairing_success',
			'pairing_auth_failed',
			'pairing_rate_limited',
			'pairing_replay',
			'pairing_issued',
			'session_created',
			'session_revoked',
			'session_revoke_all',
			'access_policy_updated',
			'access_removed',
			'product_created',
			'product_updated',
			'product_bulk_price_scheduled',
			'product_bulk_price_updated',
			'category_created',
			'category_updated',
			'variation_created',
			'variation_updated',
			'order_created',
			'order_status_updated',
			'order_note_added',
			'order_refunded',
			'shipment_updated',
			'order_tracking_updated',
			'fulfillment_updated',
			'coupon_created',
			'coupon_updated',
			'coupon_deleted',
			'review_moderated',
			'review_replied',
			'inventory_updated',
			'media_uploaded',
			'font_uploaded',
			'font_deleted',
		),
		true
	);
}

/**
 * Limit audit context to operational identifiers and bounded status text.
 * Passwords, pairing codes, CSRF values, tokens, raw requests and arbitrary
 * metadata are not accepted by this serializer.
 *
 * @param mixed $context Candidate context.
 * @return string JSON object.
 */
function audit_context_json( $context ) {
	if ( ! is_array( $context ) ) {
		return '{}';
	}

	$allowed_keys = array(
		'outcome',
		'reason',
		'count',
		'target_session_id',
		'target_user_id',
		'attachment_id',
		'order_id',
		'product_id',
		'category_id',
		'variation_id',
		'customer_id',
		'coupon_id',
		'review_id',
		'refund_id',
		'file_type',
		'status',
		'from_status',
		'to_status',
	);
	$clean = array();

	foreach ( $allowed_keys as $key ) {
		if ( ! array_key_exists( $key, $context ) || ! is_scalar( $context[ $key ] ) ) {
			continue;
		}

		if ( in_array( $key, array( 'count', 'target_session_id', 'target_user_id', 'attachment_id', 'order_id', 'product_id', 'category_id', 'variation_id', 'customer_id' ), true ) ) {
			$clean[ $key ] = absint( $context[ $key ] );
		} else {
			$clean[ $key ] = security_clean_label( $context[ $key ], 160 );
		}
	}

	$json = wp_json_encode( $clean );
	if ( ! is_string( $json ) || strlen( $json ) > 2000 ) {
		return '{}';
	}

	return $json;
}

/**
 * Append one audit event. A failed audit insert must not turn a completed
 * store operation into a second, ambiguous failure.
 *
 * @param string               $event_type Closed event name.
 * @param int                  $user_id WordPress user ID.
 * @param int                  $session_id Session ID.
 * @param string               $device_label Device label.
 * @param string               $resource_type Public resource type.
 * @param int                  $resource_id Public resource ID.
 * @param array<string, mixed> $context Safe, bounded context.
 * @param bool                 $allow_anonymous Whether user_id=0 is valid.
 * @return bool
 */
function record_audit_event( $event_type, $user_id = 0, $session_id = 0, $device_label = '', $resource_type = '', $resource_id = 0, $context = array(), $allow_anonymous = false ) {
	$event_type = sanitize_key( (string) $event_type );
	if ( ! isset( allowed_audit_events()[ $event_type ] ) ) {
		return false;
	}

	$user_id = $allow_anonymous ? 0 : absint( $user_id ? $user_id : get_current_user_id() );
	if ( $user_id < 1 && ! $allow_anonymous ) {
		return false;
	}

	$database = compose_security_database();
	$inserted = $database->insert(
		security_audit_table(),
		array(
			'user_id'       => $user_id,
			'session_id'    => absint( $session_id ),
			'event_type'    => substr( $event_type, 0, 80 ),
			'resource_type' => sanitize_key( substr( (string) $resource_type, 0, 50 ) ),
			'resource_id'   => absint( $resource_id ),
			'device_label'  => security_clean_label( $device_label, 120 ),
			'context'       => audit_context_json( $context ),
			'created_at'    => security_now_mysql(),
		),
		array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	return false !== $inserted;
}

/**
 * Record a bounded event when authentication has not established an identity.
 * The explicit flag prevents the normal current-user fallback from assigning
 * a browser's WordPress identity to an anonymous pairing attempt.
 *
 * @param string               $event_type Closed event name.
 * @param string               $resource_type Public resource type.
 * @param int                  $resource_id Public resource ID.
 * @param array<string, mixed> $context Safe, bounded context.
 * @return bool
 */
function record_anonymous_audit_event( $event_type, $resource_type = '', $resource_id = 0, $context = array() ) {
	return record_audit_event( $event_type, 0, 0, '', $resource_type, $resource_id, $context, true );
}

/**
 * Record an event for the current PWA session when a callback does not already
 * hold its session context.
 *
 * @param string               $event_type Closed event name.
 * @param string               $resource_type Public resource type.
 * @param int                  $resource_id Public resource ID.
 * @param array<string, mixed> $context Safe, bounded context.
 * @return bool
 */
function record_current_session_audit( $event_type, $resource_type = '', $resource_id = 0, $context = array() ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return false;
	}

	return record_audit_event( $event_type, $session['user']->ID, $session['id'], $session['device_label'], $resource_type, $resource_id, $context );
}

/**
 * Issue a one-time pairing code for an eligible target user. The raw code is
 * returned once to the admin page and is never persisted.
 *
 * @param int $user_id Target WordPress user ID.
 * @return string|\WP_Error
 */
function issue_pairing_code( $user_id ) {
	ensure_security_schema();

	$user   = pairing_target_user( $user_id );
	$scopes = $user ? scopes_for_user( $user->ID ) : array();
	if ( empty( $scopes ) ) {
		return new \WP_Error( 'fandoogh_pairing_target_invalid', __( 'کاربر هدف وجود ندارد یا مجوز استفاده از مدیریت فندوق را ندارد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	try {
		$raw_code = str_pad( (string) random_int( 100000000000, 999999999999 ), 12, '0', STR_PAD_LEFT );
	} catch ( \Throwable $exception ) {
		$raw_code = preg_replace( '/[^0-9]/', '', wp_generate_password( 12, false, false ) );
		$raw_code = str_pad( substr( $raw_code, 0, 12 ), 12, '0', STR_PAD_LEFT );
	}

	$database = compose_security_database();
	$inserted = $database->insert(
		security_pairings_table(),
		array(
			'token_hash'   => security_hash_secret( normalize_pairing_code( $raw_code ) ),
			'user_id'      => absint( $user_id ),
			'scopes'       => wp_json_encode( $scopes ),
			'created_at'   => security_now_mysql(),
			'expires_at'   => security_mysql_from_timestamp( time() + PAIRING_TTL ),
			'attempts'     => 0,
			'max_attempts' => PAIRING_RATE_LIMIT,
			'status'       => 'active',
		),
		array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
	);

	if ( false === $inserted ) {
		return new \WP_Error( 'fandoogh_pairing_storage', __( 'ساخت کد جفت‌سازی ممکن نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	return format_pairing_code( $raw_code );
}

/**
 * @return string
 */
function pairing_rate_key() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	return 'fandoogh_pair_rate_' . substr( hash( 'sha256', $ip ), 0, 32 );
}

/**
 * @return bool
 */
function consume_pairing_rate_limit() {
	$key   = pairing_rate_key();
	$count = absint( compose_security_state_store()->getTransient( $key ) );

	if ( $count >= PAIRING_RATE_LIMIT ) {
		return false;
	}

	compose_security_state_store()->setTransient( $key, $count + 1, PAIRING_RATE_WINDOW );
	return true;
}

/**
 * @return object|null
 */
function find_pairing_record( $code ) {
	$database = compose_security_database();
	$hash = security_hash_secret( normalize_pairing_code( $code ) );

	return $database->getRow(
		$database->prepare(
			'SELECT * FROM ' . security_pairings_table() . ' WHERE token_hash = %s AND status = %s AND expires_at > %s AND attempts < max_attempts LIMIT 1',
			$hash,
			'active',
			security_now_mysql()
		)
	);
}

/**
 * Find the state of a pairing record by its one-way code hash. This is used
 * only to distinguish a replay from an unknown/expired code; the raw code is
 * never stored, returned, or logged.
 *
 * @param string $code Normalized pairing code.
 * @return object|null
 */
function find_pairing_record_state( $code ) {
	$database = compose_security_database();

	return $database->getRow(
		$database->prepare(
			"SELECT id, status, used_at FROM " . security_pairings_table() . " WHERE token_hash = %s LIMIT 1",
			security_hash_secret( normalize_pairing_code( $code ) )
		)
	);
}

/**
 * @param int $pairing_id Pairing ID.
 * @return void
 */
function increment_pairing_attempts( $pairing_id ) {
	$database = compose_security_database();
	$database->query(
		$database->prepare(
			"UPDATE " . security_pairings_table() . " SET attempts = attempts + 1, status = IF(attempts + 1 >= max_attempts, 'locked', status) WHERE id = %d AND status = %s",
			absint( $pairing_id ),
			'active'
		)
	);
}

/**
 * @param int $pairing_id Pairing ID.
 * @return bool
 */
function consume_pairing_record( $pairing_id ) {
	$database = compose_security_database();
	$updated = $database->query(
		$database->prepare(
			"UPDATE " . security_pairings_table() . " SET used_at = %s, status = %s WHERE id = %d AND status = %s AND used_at IS NULL AND expires_at > %s AND attempts < max_attempts",
			security_now_mysql(),
			'used',
			absint( $pairing_id ),
			'active',
			security_now_mysql()
		)
	);

	return 1 === (int) $updated;
}

/**
 * @param string $token Raw session token.
 * @return string
 */
function session_hash( $token ) {
	return security_hash_secret( $token );
}

/**
 * @return string
 */
function session_cookie_path() {
	$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	return '/' === substr( $path, -1 ) ? $path : $path . '/';
}

/**
 * @param string $token Raw token.
 * @param int    $expires Unix expiry.
 * @return void
 */
function set_session_cookie( $token, $expires ) {
	$options = array(
		'expires'  => absint( $expires ),
		'path'     => session_cookie_path(),
		'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
		'httponly' => true,
		'samesite' => 'Strict',
	);

	if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
		$options['domain'] = COOKIE_DOMAIN;
	}

	setcookie( SESSION_COOKIE_NAME, $token, $options );
}

/**
 * @return void
 */
function clear_session_cookie() {
	set_session_cookie( '', time() - 3600 );
	}

/**
 * @param int    $user_id WordPress user ID.
 * @param string $device_id Client-generated transient ID.
 * @param string $device_label Human-readable device label.
 * @param array  $scopes Granted scopes.
 * @return array<string, mixed>|\WP_Error
 */
function create_session_record( $user_id, $device_id, $device_label, $scopes ) {
	$raw_session = security_random_token( 32 );
	$raw_csrf    = security_random_token( 32 );
	$now         = time();

	$database = compose_security_database();
	$inserted = $database->insert(
		security_sessions_table(),
		array(
			'session_hash' => session_hash( $raw_session ),
			'user_id'      => absint( $user_id ),
			'device_hash'  => security_hash_secret( security_clean_label( $device_id, 180 ) ),
			'device_label' => security_clean_label( $device_label, 120 ),
			'scopes'       => wp_json_encode( $scopes ),
			'csrf_hash'    => security_hash_secret( $raw_csrf ),
			'csrf_previous_hash'          => '',
			'csrf_previous_expires_at'    => null,
			'created_at'   => security_now_mysql(),
			'last_seen_at' => security_now_mysql(),
			'expires_at'   => security_mysql_from_timestamp( $now + SESSION_TTL ),
			'status'       => 'active',
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new \WP_Error( 'fandoogh_session_storage', __( 'ساخت نشست امن ممکن نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	$session_id = $database->insertId();
	record_audit_event( 'session_created', $user_id, $session_id, $device_label, 'session', $session_id );

	set_session_cookie( $raw_session, $now + SESSION_TTL );

	return array(
		'id'         => $session_id,
		'csrf_token' => $raw_csrf,
		'expires_at' => security_mysql_from_timestamp( $now + SESSION_TTL ),
	);
}

/**
 * Return the current session without exposing its raw cookie value.
 *
 * @return array<string, mixed>|\WP_Error
 */
/**
 * Recipient address for new-session alerts: the configured address, or the
 * site administration email when no override is saved.
 *
 * @return string
 */
function session_alert_recipient() {
	$settings = get_settings();
	$email    = isset( $settings['session_alert_email'] ) ? trim( (string) $settings['session_alert_email'] ) : '';

	if ( '' !== $email && is_email( $email ) ) {
		return $email;
	}

	return (string) get_option( 'admin_email' );
}

/**
 * Send the optional administrator email after a successful pairing. Failures
 * must never block or fail the pairing response, so the result is ignored.
 *
 * @param \WP_User $user Paired WordPress user.
 * @param string   $device_label Human-readable device label.
 * @param int      $session_id New session ID.
 * @return void
 */
function send_session_alert_email( $user, $device_label, $session_id ) {
	$settings = get_settings();
	if ( empty( $settings['session_alerts_enabled'] ) ) {
		return;
	}

	$recipient = session_alert_recipient();
	if ( '' === $recipient || ! is_email( $recipient ) ) {
		return;
	}

	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject   = sprintf(
		/* translators: 1: site name, 2: user login */
		__( '[%1$s] نشست جدید Fandoogh Manager برای %2$s', 'fandoogh-manager' ),
		$site_name,
		$user->user_login
	);
	$label     = security_clean_label( $device_label, 120 );
	$when      = admin_security_date_label( security_now_mysql() );
	$body      = sprintf(
		/* translators: 1: display name, 2: device label, 3: Jalali datetime, 4: session id */
		__( "یک نشست جدید وب‌اپ مدیریت ساخته شد.\n\nکاربر: %1$s\nدستگاه: %2$s\nزمان: %3$s\nشناسهٔ نشست: %4$s\n\nاگر این اتصال را شما انجام نداده‌اید، فوراً از بخش «امنیت و دستگاه‌ها» وب‌اپ یا پنل افزونه نشست را باطل کنید.", 'fandoogh-manager' ),
		sanitize_text_field( $user->display_name ) . ' (' . sanitize_user( $user->user_login, true ) . ')',
		'' !== $label ? $label : 'نامشخص',
		$when,
		absint( $session_id )
	);
	$body     .= "\n\n" . app_base_url();

	wp_mail( $recipient, $subject, $body );
}

/**
 * Queue the optional new-session alert outside the pairing response path.
 *
 * Scheduled arguments deliberately contain only identifiers and the bounded
 * device label. Session cookies, CSRF tokens, pairing codes, and passwords
 * must never enter the cron queue.
 *
 * @param int    $user_id Session owner ID.
 * @param int    $session_id New session ID.
 * @param string $device_label Human-readable device label.
 * @return bool Whether an alert is already queued or was scheduled.
 */
function queue_session_alert_email( $user_id, $session_id, $device_label ) {
	$settings = get_settings();
	if ( empty( $settings['session_alerts_enabled'] ) ) {
		return false;
	}

	$recipient = session_alert_recipient();
	if ( '' === $recipient || ! is_email( $recipient ) ) {
		return false;
	}

	$user_id      = absint( $user_id );
	$session_id   = absint( $session_id );
	$device_label = security_clean_label( $device_label, 120 );
	if ( $user_id < 1 || $session_id < 1 || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
		return false;
	}

	$args = array( $user_id, $session_id, $device_label );

	try {
		if ( false !== wp_next_scheduled( SESSION_ALERT_CRON_HOOK, $args ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event( time() + 1, SESSION_ALERT_CRON_HOOK, $args, true );
		return true === $scheduled;
	} catch ( \Throwable $exception ) {
		return false;
	}
}

/**
 * Deliver a queued new-session alert. Cron execution is best-effort and must
 * never expose or propagate mail transport failures.
 *
 * @param int    $user_id Session owner ID.
 * @param int    $session_id New session ID.
 * @param string $device_label Human-readable device label.
 * @return void
 */
function send_scheduled_session_alert_email( $user_id, $session_id, $device_label ) {
	try {
		$user_id    = absint( $user_id );
		$session_id = absint( $session_id );
		$user       = $user_id ? get_user_by( 'id', $user_id ) : false;

		if ( $session_id < 1 || ! $user instanceof \WP_User ) {
			return;
		}

		send_session_alert_email( $user, security_clean_label( $device_label, 120 ), $session_id );
	} catch ( \Throwable $exception ) {
		return;
	}
}

add_action( SESSION_ALERT_CRON_HOOK, __NAMESPACE__ . '\\send_scheduled_session_alert_email', 10, 3 );

/**
 * Count active sessions of the current user created after this session and
 * remember which ones have already been acknowledged. The count and the
 * acknowledged marker both live in one site option so no schema change and
 * no user-meta growth is needed.
 *
 * @param int   $user_id WordPress user ID.
 * @param int   $session_id Current session ID.
 * @param array $rows Active-session rows of this user (id, device_label, created_at).
 * @return int Number of unacknowledged newer active sessions.
 */
function session_alert_unacknowledged_count( $user_id, $session_id, $rows ) {
	$state = compose_security_state_store()->getOption( SESSION_ALERT_OPTION_KEY, array() );
	$state = is_array( $state ) ? $state : array();
	$key   = (string) absint( $user_id );
	$seen  = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
	$count = 0;

	foreach ( (array) $rows as $row ) {
		$row_id = absint( $row->id );
		if ( $row_id === absint( $session_id ) || 'active' !== (string) $row->status ) {
			continue;
		}
		if ( (int) $row_id <= (int) $session_id ) {
			continue;
		}
		if ( isset( $seen[ $row_id ] ) ) {
			continue;
		}
		$count++;
	}

	if ( 0 === $count && isset( $state[ $key ] ) ) {
		unset( $state[ $key ] );
		$state = sanitize_session_alert_state( $state );
		compose_security_state_store()->updateOption( SESSION_ALERT_OPTION_KEY, $state, false );
	}

	return $count;
}

/**
 * Mark every current newer active session as seen for this user.
 *
 * @param int $user_id WordPress user ID.
 * @param int $session_id Current session ID.
 * @param array $rows Active-session rows of this user.
 * @return void
 */
function session_alert_acknowledge( $user_id, $session_id, $rows ) {
	$state = compose_security_state_store()->getOption( SESSION_ALERT_OPTION_KEY, array() );
	$state = is_array( $state ) ? $state : array();
	$key   = (string) absint( $user_id );
	$seen  = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();

	foreach ( (array) $rows as $row ) {
		$row_id = absint( $row->id );
		if ( $row_id === absint( $session_id ) || 'active' !== (string) $row->status ) {
			continue;
		}
		if ( (int) $row_id <= (int) $session_id ) {
			continue;
		}
		$seen[ $row_id ] = true;
	}

	$state[ $key ] = $seen;
	$state = sanitize_session_alert_state( $state );
	compose_security_state_store()->updateOption( SESSION_ALERT_OPTION_KEY, $state, false );
}

/**
 * Bound the alert-state option: only int keys, per-user maps capped at 200
 * entries, at most 100 users, and drop entries whose sessions are gone.
 *
 * @param array $state Raw option value.
 * @return array
 */
function sanitize_session_alert_state( $state ) {
	if ( ! is_array( $state ) ) {
		return array();
	}

	$clean = array();
	foreach ( $state as $user_key => $sessions ) {
		if ( ! is_array( $sessions ) ) {
			continue;
		}
		$user_key = (string) absint( $user_key );
		if ( '' === $user_key || '0' === $user_key ) {
			continue;
		}
		$seen = array();
		foreach ( array_keys( $sessions ) as $session_key ) {
			$session_key = absint( $session_key );
			if ( $session_key > 0 ) {
				$seen[ $session_key ] = true;
			}
			if ( count( $seen ) >= 200 ) {
				break;
			}
		}
		if ( ! empty( $seen ) ) {
			$clean[ $user_key ] = $seen;
		}
		if ( count( $clean ) >= 100 ) {
			break;
		}
	}

	return $clean;
}

/**
 * Return the current session without exposing its raw cookie value.
 *
 * @return array<string, mixed>|\WP_Error
 */
function get_session_context() {
	if ( ! security_is_secure_transport_allowed() ) {
		return new \WP_Error( 'fandoogh_insecure_transport', __( 'نشست مدیریتی فقط روی HTTPS قابل استفاده است.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( empty( $_COOKIE[ SESSION_COOKIE_NAME ] ) ) {
		return new \WP_Error( 'fandoogh_not_authenticated', __( 'نشست مدیریتی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$raw_session = (string) wp_unslash( $_COOKIE[ SESSION_COOKIE_NAME ] );
	if ( ! preg_match( '/^[a-f0-9]{64}$/i', $raw_session ) ) {
		clear_session_cookie();
		return new \WP_Error( 'fandoogh_not_authenticated', __( 'نشست مدیریتی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$database = compose_security_database();
	$row = $database->getRow(
		$database->prepare(
			"SELECT * FROM " . security_sessions_table() . " WHERE session_hash = %s LIMIT 1",
			session_hash( $raw_session )
		)
	);

	if ( ! is_object( $row ) || 'active' !== (string) $row->status ) {
		clear_session_cookie();
		return new \WP_Error( 'fandoogh_not_authenticated', __( 'نشست مدیریتی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$now          = time();
	$expires_at   = security_timestamp_from_mysql( $row->expires_at );
	$last_seen_at = security_timestamp_from_mysql( $row->last_seen_at );
	if ( $expires_at <= $now || ( $last_seen_at && $last_seen_at + SESSION_IDLE_TTL <= $now ) ) {
		$database->update(
			security_sessions_table(),
			array(
				'status'     => 'expired',
				'revoked_at' => security_now_mysql(),
			),
			array( 'id' => absint( $row->id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clear_session_cookie();
		return new \WP_Error( 'fandoogh_session_expired', __( 'نشست مدیریتی منقضی شده است.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$user = get_user_by( 'id', absint( $row->user_id ) );
	if ( ! $user ) {
		clear_session_cookie();
		return new \WP_Error( 'fandoogh_user_missing', __( 'کاربر این نشست دیگر وجود ندارد.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$scopes = json_decode( (string) $row->scopes, true );
	if ( ! is_array( $scopes ) ) {
		$scopes = array();
	}

	// Re-evaluate the user policy on every request. This makes an admin
	// restriction or access removal effective for already-issued sessions and
	// prevents a stale session snapshot from retaining a revoked capability.
	$scopes = intersect_pairing_scopes( $scopes, scopes_for_user( $user->ID ) );
	if ( ! session_has_scope( 'products.read', $scopes ) ) {
		$database->update(
			security_sessions_table(),
			array(
				'status'     => 'revoked',
				'revoked_at' => security_now_mysql(),
			),
			array( 'id' => absint( $row->id ), 'status' => 'active' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		clear_session_cookie();
		return new \WP_Error( 'fandoogh_access_removed', __( 'دسترسی این کاربر به وب‌اپ مدیریت حذف شده است.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( ! $last_seen_at || $last_seen_at + 300 <= $now ) {
		$database->update(
			security_sessions_table(),
			array( 'last_seen_at' => security_now_mysql() ),
			array( 'id' => absint( $row->id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	return array(
		'id'          => absint( $row->id ),
		'user'        => $user,
		'scopes'      => $scopes,
		'csrf_hash'   => (string) $row->csrf_hash,
		'csrf_previous_hash'       => isset( $row->csrf_previous_hash ) ? (string) $row->csrf_previous_hash : '',
		'csrf_previous_expires_at' => isset( $row->csrf_previous_expires_at ) ? (string) $row->csrf_previous_expires_at : '',
		'expires_at'  => (string) $row->expires_at,
		'device_label'=> (string) $row->device_label,
	);
}

/**
 * Set WordPress's in-request user context without issuing a WordPress login
 * cookie. WooCommerce and WordPress capability-aware helpers can then make
 * the same decision as the session middleware.
 *
 * @param array<string, mixed> $session Session context.
 * @return void
 */
function apply_session_user_context( $session ) {
	if ( is_array( $session ) && isset( $session['user']->ID ) ) {
		wp_set_current_user( absint( $session['user']->ID ) );
	}
}

/**
 * @param int    $session_id Session ID.
 * @param string $raw_csrf New raw CSRF token.
 * @return bool
 */
function rotate_session_csrf( $session_id, $raw_csrf ) {
	$database = compose_security_database();

	// One SQL statement makes the old current hash the short-lived previous
	// hash at the same time that the new current hash is installed. The raw
	// token is never sent to the database, logs, or audit context.
	$updated = $database->query(
		$database->prepare(
			"UPDATE " . security_sessions_table() . " SET csrf_previous_hash = csrf_hash, csrf_previous_expires_at = %s, csrf_hash = %s WHERE id = %d AND status = %s",
			security_mysql_from_timestamp( time() + CSRF_GRACE_TTL ),
			security_hash_secret( $raw_csrf ),
			absint( $session_id ),
			'active'
		)
	);

	return 1 === (int) $updated;
}

/**
 * @param int $session_id Session ID.
 * @return void
 */
function revoke_session( $session_id ) {
	$database = compose_security_database();
	$database->update(
		security_sessions_table(),
		array(
			'status'     => 'revoked',
			'revoked_at' => security_now_mysql(),
		),
		array( 'id' => absint( $session_id ), 'status' => 'active' ),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function authenticated_permission( $request ) {
	$session = get_session_context();
	return is_wp_error( $session ) ? $session : true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function csrf_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$header = (string) $request->get_header( CSRF_HEADER_NAME );
	$header_hash = '' !== $header && preg_match( '/^[a-f0-9]{64}$/i', $header ) ? security_hash_secret( $header ) : '';
	$current_ok = '' !== $header_hash && hash_equals( (string) $session['csrf_hash'], $header_hash );
	$previous_ok = false;
	if ( ! $current_ok && '' !== $header_hash && ! empty( $session['csrf_previous_hash'] ) ) {
		$previous_expires_at = security_timestamp_from_mysql( isset( $session['csrf_previous_expires_at'] ) ? $session['csrf_previous_expires_at'] : '' );
		$previous_ok = $previous_expires_at > time() && hash_equals( (string) $session['csrf_previous_hash'], $header_hash );
	}

	if ( ! $current_ok && ! $previous_ok ) {
		return new \WP_Error( 'fandoogh_csrf_failed', __( 'درخواست CSRF معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( ! security_is_same_origin_request() ) {
		return new \WP_Error( 'fandoogh_origin_failed', __( 'مبدأ درخواست معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @return void
 */
function register_security_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/auth/pair',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\pair_session',
			'permission_callback' => __NAMESPACE__ . '\\public_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/me',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_session_user',
			'permission_callback' => __NAMESPACE__ . '\\authenticated_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/csrf',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_csrf_token',
			'permission_callback' => __NAMESPACE__ . '\\authenticated_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/logout',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\logout_session',
			'permission_callback' => __NAMESPACE__ . '\\csrf_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/devices',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_manager_devices',
			'permission_callback' => __NAMESPACE__ . '\\devices_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/devices/(?P<id>\\d+)/revoke',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\revoke_manager_device',
			'permission_callback' => __NAMESPACE__ . '\\devices_revoke_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/devices/revoke-all',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\revoke_all_manager_devices',
			'permission_callback' => __NAMESPACE__ . '\\devices_revoke_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/acknowledge-new-sessions',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\acknowledge_new_sessions',
			'permission_callback' => __NAMESPACE__ . '\\devices_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/auth/audit',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_audit_events',
			'permission_callback' => __NAMESPACE__ . '\\audit_read_permission',
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function devices_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'devices.read', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ دستگاه‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز مشاهدهٔ دستگاه‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function devices_revoke_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'devices.revoke', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز ابطال دستگاه‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	$user_id = absint( $session['user']->ID );
	if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز ابطال دستگاه‌ها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function audit_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'audit.read', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ گزارش رویدادها را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'گزارش رویدادها فقط برای مدیر اصلی در دسترس است.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function security_query_integer( $value, $default, $min, $max ) {
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return $default;
	}

	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * @param object $row Session database row.
 * @param int    $current_session_id Current session ID.
 * @return array<string, mixed>
 */
function serialize_manager_device( $row, $current_session_id ) {
	return array(
		'id'           => absint( $row->id ),
		'device_label' => security_clean_label( $row->device_label, 120 ),
		'status'       => sanitize_key( (string) $row->status ),
		'created_at'   => security_clean_label( $row->created_at, 30 ),
		'last_seen_at' => security_clean_label( $row->last_seen_at, 30 ),
		'expires_at'   => security_clean_label( $row->expires_at, 30 ),
		'revoked_at'   => empty( $row->revoked_at ) ? null : security_clean_label( $row->revoked_at, 30 ),
		'current'      => absint( $row->id ) === absint( $current_session_id ),
	);
}

/**
 * Return the current WordPress user's sessions without exposing hashes,
 * cookies, CSRF values, or device identifiers.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function list_manager_devices() {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$database = compose_security_database();
	$rows = $database->getResults(
		$database->prepare(
			"SELECT id, device_label, status, created_at, last_seen_at, expires_at, revoked_at FROM " . security_sessions_table() . " WHERE user_id = %d ORDER BY id DESC LIMIT 100",
			absint( $session['user']->ID )
		)
	);

	$items = array();
	foreach ( (array) $rows as $row ) {
		if ( is_object( $row ) ) {
			$items[] = serialize_manager_device( $row, $session['id'] );
		}
	}

	$meta = array(
		'new_sessions' => session_alert_unacknowledged_count( absint( $session['user']->ID ), absint( $session['id'] ), (array) $rows ),
	);

	return security_no_store_response(
		array(
			'data' => $items,
			'meta' => $meta,
		)
	);
}

/**
 * Revoke one session belonging to the current WordPress user.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function revoke_manager_device( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$target_id = absint( $request->get_param( 'id' ) );
	if ( $target_id < 1 ) {
		return new \WP_Error( 'fandoogh_device_not_found', __( 'دستگاه پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	$database = compose_security_database();
	$target = $database->getRow(
		$database->prepare(
			"SELECT id, device_label, status FROM " . security_sessions_table() . " WHERE id = %d AND user_id = %d LIMIT 1",
			$target_id,
			absint( $session['user']->ID )
		)
	);
	if ( ! is_object( $target ) ) {
		return new \WP_Error( 'fandoogh_device_not_found', __( 'دستگاه پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	if ( 'active' !== (string) $target->status ) {
		return new \WP_Error( 'fandoogh_device_inactive', __( 'این دستگاه قبلاً غیرفعال شده است.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	$updated = $database->query(
		$database->prepare(
			"UPDATE " . security_sessions_table() . " SET status = %s, revoked_at = %s WHERE id = %d AND user_id = %d AND status = %s",
			'revoked',
			security_now_mysql(),
			$target_id,
			absint( $session['user']->ID ),
			'active'
		)
	);
	if ( 1 !== (int) $updated ) {
		return new \WP_Error( 'fandoogh_device_revoke_failed', __( 'ابطال دستگاه انجام نشد.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	record_audit_event( 'session_revoked', $session['user']->ID, $session['id'], $session['device_label'], 'session', $target_id, array( 'reason' => 'device_revoke', 'target_session_id' => $target_id ) );
	$is_current = absint( $target_id ) === absint( $session['id'] );
	if ( $is_current ) {
		clear_session_cookie();
	}

	return security_no_store_response(
		array(
			'data' => array(
				'revoked' => true,
				'current' => $is_current,
			),
		)
	);
}

/**
 * Revoke all other active sessions for the current WordPress user.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function revoke_all_manager_devices() {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$database = compose_security_database();
	$revoked_count = $database->query(
		$database->prepare(
			"UPDATE " . security_sessions_table() . " SET status = %s, revoked_at = %s WHERE user_id = %d AND status = %s AND id <> %d",
			'revoked',
			security_now_mysql(),
			absint( $session['user']->ID ),
			'active',
			absint( $session['id'] )
		)
	);
	$revoked_count = false === $revoked_count ? 0 : absint( $revoked_count );

	record_audit_event( 'session_revoke_all', $session['user']->ID, $session['id'], $session['device_label'], 'session', 0, array( 'count' => $revoked_count, 'reason' => 'revoke_all_other_devices' ) );

	return security_no_store_response( array( 'data' => array( 'revoked_count' => $revoked_count ) ) );
}

/**
 * Mark all newer active sessions of the current user as acknowledged so the
 * in-app new-session warning stops showing until the next new session.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function acknowledge_new_sessions() {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$database = compose_security_database();
	$rows = $database->getResults(
		$database->prepare(
			"SELECT id, status FROM " . security_sessions_table() . " WHERE user_id = %d ORDER BY id DESC LIMIT 100",
			absint( $session['user']->ID )
		)
	);

	session_alert_acknowledge( absint( $session['user']->ID ), absint( $session['id'] ), (array) $rows );

	return security_no_store_response( array( 'data' => array( 'acknowledged' => true ) ) );
}

/**
 * Return the cross-user session projection used only by the protected
 * WordPress administrator screen. Session hashes, device hashes, CSRF
 * values, cookies and pairing secrets never leave the database.
 *
 * @return array<int, array<string, mixed>>
 */
function admin_security_session_rows() {
	$database = compose_security_database();
	$users_table = $database->usersTable();

	$rows = $database->getResults(
		"SELECT s.id, s.user_id, s.device_label, s.status, s.created_at, s.last_seen_at, s.expires_at, s.revoked_at, u.user_login, u.display_name
		FROM " . security_sessions_table() . " s
		LEFT JOIN {$users_table} u ON u.ID = s.user_id
		ORDER BY CASE WHEN s.status = 'active' THEN 0 ELSE 1 END, s.last_seen_at DESC, s.id DESC
		LIMIT 500"
	);

	$items = array();
	$now   = time();
	foreach ( (array) $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}

		$expires   = security_timestamp_from_mysql( $row->expires_at );
		$last_seen = security_timestamp_from_mysql( $row->last_seen_at );
		$active    = 'active' === (string) $row->status && $expires > $now && ( ! $last_seen || $last_seen + SESSION_IDLE_TTL > $now );
		$items[] = array(
			'id'             => absint( $row->id ),
			'user_id'        => absint( $row->user_id ),
			'user_login'     => sanitize_user( (string) $row->user_login, true ),
			'user_name'      => security_clean_label( $row->display_name, 160 ),
			'device_label'   => security_clean_label( $row->device_label, 120 ),
			'status'         => sanitize_key( (string) $row->status ),
			'connected'      => $active,
			'created_at'     => security_clean_label( $row->created_at, 30 ),
			'last_seen_at'   => security_clean_label( $row->last_seen_at, 30 ),
			'expires_at'     => security_clean_label( $row->expires_at, 30 ),
			'revoked_at'     => empty( $row->revoked_at ) ? '' : security_clean_label( $row->revoked_at, 30 ),
		);
	}

	return $items;
}

/**
 * Return users who can be managed by Fandoogh Manager, including users with
 * existing sessions or a saved access policy. This keeps a revoked user
 * visible so an administrator can restore access intentionally.
 *
 * @param array<int, array<string, mixed>> $session_rows Session projections.
 * @return array<int, array<string, mixed>>
 */
function admin_security_users( $session_rows = array() ) {
	$session_counts = array();
	foreach ( (array) $session_rows as $session ) {
		$user_id = isset( $session['user_id'] ) ? absint( $session['user_id'] ) : 0;
		if ( $user_id < 1 ) {
			continue;
		}
		if ( ! isset( $session_counts[ $user_id ] ) ) {
			$session_counts[ $user_id ] = array( 'total' => 0, 'connected' => 0 );
		}
		$session_counts[ $user_id ]['total']++;
		if ( ! empty( $session['connected'] ) ) {
			$session_counts[ $user_id ]['connected']++;
		}
	}

	$users = get_users(
		array(
			'fields'  => array( 'ID', 'user_login', 'display_name' ),
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 0,
		)
	);

	$items = array();
	foreach ( (array) $users as $user ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
			continue;
		}

		$user_id = absint( $user->ID );
		$policy  = get_user_access_policy( $user_id );
		$capable = user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
		$counts  = isset( $session_counts[ $user_id ] ) ? $session_counts[ $user_id ] : array( 'total' => 0, 'connected' => 0 );
		$stored  = get_user_meta( $user_id, ACCESS_POLICY_META_KEY, true );

		if ( ! $capable && 0 === $counts['total'] && ! is_array( $stored ) ) {
			continue;
		}

		$items[] = array(
			'id'              => $user_id,
			'user_login'      => sanitize_user( (string) $user->user_login, true ),
			'user_name'       => security_clean_label( $user->display_name, 160 ),
			'capable'         => $capable,
			'protected'       => user_access_policy_is_protected( $user_id ),
			'policy'          => $policy,
			'session_count'   => absint( $counts['total'] ),
			'connected_count' => absint( $counts['connected'] ),
		);
	}

	return $items;
}

/**
 * Revoke one session from the protected administrator panel.
 *
 * @param int    $session_id Session ID.
 * @param string $reason Audit reason.
 * @return true|false|\WP_Error
 */
function admin_revoke_session( $session_id, $reason = 'admin_revoke' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_admin_forbidden', __( 'فقط مدیر اصلی می‌تواند نشست‌ها را از پنل افزونه مدیریت کند.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	$session_id = absint( $session_id );
	if ( $session_id < 1 ) {
		return new \WP_Error( 'fandoogh_session_not_found', __( 'نشست انتخاب‌شده معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	$database = compose_security_database();
	$row = $database->getRow(
		$database->prepare(
			"SELECT id, user_id, device_label, status FROM " . security_sessions_table() . " WHERE id = %d LIMIT 1",
			$session_id
		)
	);
	if ( ! is_object( $row ) ) {
		return new \WP_Error( 'fandoogh_session_not_found', __( 'نشست انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	if ( 'active' !== (string) $row->status ) {
		return false;
	}

	$updated = $database->query(
		$database->prepare(
			"UPDATE " . security_sessions_table() . " SET status = %s, revoked_at = %s WHERE id = %d AND status = %s",
			'revoked',
			security_now_mysql(),
			$session_id,
			'active'
		)
	);
	if ( 1 !== (int) $updated ) {
		return new \WP_Error( 'fandoogh_session_revoke_failed', __( 'ابطال نشست انجام نشد؛ دوباره صفحه را تازه کنید.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	record_audit_event( 'session_revoked', get_current_user_id(), 0, 'wp-admin', 'session', $session_id, array( 'reason' => security_clean_label( $reason, 80 ), 'target_session_id' => $session_id, 'target_user_id' => absint( $row->user_id ) ) );
	return true;
}

/**
 * Revoke all active sessions belonging to a selected user.
 *
 * @param int    $user_id WordPress user ID.
 * @param string $reason Audit reason.
 * @return int|\WP_Error
 */
function admin_revoke_user_sessions( $user_id, $reason = 'admin_revoke_user' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_admin_forbidden', __( 'فقط مدیر اصلی می‌تواند نشست‌ها را از پنل افزونه مدیریت کند.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	$user_id = absint( $user_id );
	if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
		return new \WP_Error( 'fandoogh_user_not_found', __( 'کاربر انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}

	$database = compose_security_database();
	$revoked_count = $database->query(
		$database->prepare(
			"UPDATE " . security_sessions_table() . " SET status = %s, revoked_at = %s WHERE user_id = %d AND status = %s",
			'revoked',
			security_now_mysql(),
			$user_id,
			'active'
		)
	);
	$revoked_count = false === $revoked_count ? 0 : absint( $revoked_count );
	record_audit_event( 'session_revoke_all', get_current_user_id(), 0, 'wp-admin', 'user', $user_id, array( 'count' => $revoked_count, 'reason' => security_clean_label( $reason, 80 ), 'target_user_id' => $user_id ) );

	return $revoked_count;
}

/**
 * Change web-app access for a user and revoke existing sessions so the
 * decision is effective immediately. Pending one-time pairing codes are also
 * invalidated when access is removed.
 *
 * @param int  $user_id WordPress user ID.
 * @param bool $enabled Whether a new session may be issued.
 * @return array<string, mixed>|\WP_Error
 */
function admin_set_user_access( $user_id, $enabled ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_admin_forbidden', __( 'فقط مدیر اصلی می‌تواند دسترسی کاربران را مدیریت کند.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	$user_id = absint( $user_id );
	$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
	if ( ! $user ) {
		return new \WP_Error( 'fandoogh_user_not_found', __( 'کاربر انتخاب‌شده پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
	}
	if ( user_access_policy_is_protected( $user_id ) ) {
		return new \WP_Error( 'fandoogh_protected_user', __( 'دسترسی administrator اصلی از این بخش قابل حذف نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$policy            = get_user_access_policy( $user_id );
	$policy['enabled'] = (bool) $enabled;
	$policy            = update_user_access_policy( $user_id, $policy );
	$revoked_count     = admin_revoke_user_sessions( $user_id, $enabled ? 'access_restore' : 'access_removed' );
	if ( is_wp_error( $revoked_count ) ) {
		return $revoked_count;
	}

	$database = compose_security_database();
	$pairing_revoked = 0;
	if ( ! $enabled ) {
		$pairing_revoked = $database->query(
			$database->prepare(
				"UPDATE " . security_pairings_table() . " SET status = %s WHERE user_id = %d AND status = %s",
				'revoked',
				$user_id,
				'active'
			)
		);
		$pairing_revoked = false === $pairing_revoked ? 0 : absint( $pairing_revoked );
	}

	record_audit_event( $enabled ? 'access_policy_updated' : 'access_removed', get_current_user_id(), 0, 'wp-admin', 'user', $user_id, array( 'outcome' => $enabled ? 'enabled' : 'disabled', 'count' => $revoked_count, 'target_user_id' => $user_id ) );
	return array(
		'policy'          => $policy,
		'revoked_count'   => $revoked_count,
		'pairing_revoked' => $pairing_revoked,
	);
}

/**
 * Return a bounded audit event list to the main administrator only.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function list_audit_events( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$page     = security_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
	$per_page = security_query_integer( $request->get_param( 'per_page' ), 50, 1, 100 );
	$event    = sanitize_key( (string) $request->get_param( 'event' ) );
	if ( '' !== $event && ! isset( allowed_audit_events()[ $event ] ) ) {
		return new \WP_Error( 'fandoogh_invalid_audit_event', __( 'فیلتر رویداد معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$where  = array();
	$params = array();
	if ( '' !== $event ) {
		$where[]  = 'event_type = %s';
		$params[] = $event;
	}
	$where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
	$table     = security_audit_table();
	$database = compose_security_database();
	$count_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
	$count_sql = empty( $params ) ? $count_sql : $database->prepare( $count_sql, $params );

	$total = absint( $database->getVar( $count_sql ) );
	$offset = ( $page - 1 ) * $per_page;
	$list_sql = "SELECT id, user_id, session_id, event_type, resource_type, resource_id, device_label, context, created_at FROM {$table}{$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
	$list_params = array_merge( $params, array( $per_page, $offset ) );
	$list_sql = $database->prepare( $list_sql, $list_params );
	$rows = $database->getResults( $list_sql );

	$items = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}

		$context = json_decode( (string) $row->context, true );
		$items[] = array(
			'id'            => absint( $row->id ),
			'user_id'       => absint( $row->user_id ),
			'session_id'    => absint( $row->session_id ),
			'event_type'    => sanitize_key( (string) $row->event_type ),
			'resource_type' => sanitize_key( (string) $row->resource_type ),
			'resource_id'   => absint( $row->resource_id ),
			'device_label'  => security_clean_label( $row->device_label, 120 ),
			'context'       => is_array( $context ) ? $context : array(),
			'created_at'    => security_clean_label( $row->created_at, 30 ),
		);
	}

	return security_no_store_response(
		array(
			'data' => $items,
			'meta' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total ? (int) ceil( $total / $per_page ) : 0,
			),
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function pair_session( $request ) {
	if ( ! security_is_secure_transport_allowed() || ! security_is_same_origin_request() ) {
		return new \WP_Error( 'fandoogh_pairing_transport', __( 'جفت‌سازی فقط از مبدأ هم‌سایت و روی HTTPS انجام می‌شود.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return new \WP_Error( 'fandoogh_json_required', __( 'بدنهٔ درخواست باید JSON باشد.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	if ( ! consume_pairing_rate_limit() ) {
		record_anonymous_audit_event( 'pairing_rate_limited', 'auth', 0, array( 'outcome' => 'blocked', 'reason' => 'rate_window' ) );
		return new \WP_Error( 'fandoogh_pairing_rate_limited', __( 'تعداد تلاش‌ها زیاد است؛ بعداً دوباره امتحان کنید.', 'fandoogh-manager' ), array( 'status' => 429 ) );
	}

	$body = $request->get_json_params();
	$body = is_array( $body ) ? $body : array();
	$code = isset( $body['pairing_code'] ) ? normalize_pairing_code( (string) $body['pairing_code'] ) : '';
	$username = isset( $body['username'] ) ? security_clean_label( $body['username'], 80 ) : '';
	$password = isset( $body['password'] ) && is_scalar( $body['password'] ) ? (string) $body['password'] : '';

	if ( '' === $code || ! preg_match( '/^\d{12}$/', $code ) || '' === $username || '' === $password ) {
		record_anonymous_audit_event( 'pairing_auth_failed', 'auth', 0, array( 'outcome' => 'rejected', 'reason' => 'invalid_input' ) );
		return new \WP_Error( 'fandoogh_pairing_invalid_input', __( 'نام کاربری، رمز عبور و کد جفت‌سازی الزامی است.', 'fandoogh-manager' ), array( 'status' => 400 ) );
	}

	$pairing = find_pairing_record( $code );
	if ( ! $pairing ) {
		$pairing_state = find_pairing_record_state( $code );
		if ( is_object( $pairing_state ) && ( 'used' === (string) $pairing_state->status || ! empty( $pairing_state->used_at ) ) ) {
			record_anonymous_audit_event( 'pairing_replay', 'auth', 0, array( 'outcome' => 'rejected', 'reason' => 'already_consumed' ) );
		} else {
			record_anonymous_audit_event( 'pairing_auth_failed', 'auth', 0, array( 'outcome' => 'rejected', 'reason' => 'invalid_pairing' ) );
		}
		return new \WP_Error( 'fandoogh_pairing_invalid', __( 'کد جفت‌سازی معتبر نیست یا منقضی شده است.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$user = wp_authenticate( $username, $password );
	if ( is_wp_error( $user ) || ! $user instanceof \WP_User || absint( $user->ID ) !== absint( $pairing->user_id ) ) {
		increment_pairing_attempts( $pairing->id );
		record_anonymous_audit_event( 'pairing_auth_failed', 'auth', 0, array( 'outcome' => 'rejected', 'reason' => 'credentials_or_target_mismatch' ) );
		return new \WP_Error( 'fandoogh_pairing_invalid', __( 'اطلاعات ورود یا کد جفت‌سازی معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 401 ) );
	}

	$issued_scopes = json_decode( (string) $pairing->scopes, true );
	$scopes        = intersect_pairing_scopes( $issued_scopes, scopes_for_user( $user->ID ) );
	if ( ! session_has_scope( 'products.read', $scopes ) ) {
		return new \WP_Error( 'fandoogh_pairing_forbidden', __( 'این کاربر مجوز استفاده از مدیریت را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( ! consume_pairing_record( $pairing->id ) ) {
		record_anonymous_audit_event( 'pairing_replay', 'auth', 0, array( 'outcome' => 'rejected', 'reason' => 'already_consumed' ) );
		return new \WP_Error( 'fandoogh_pairing_replayed', __( 'کد جفت‌سازی قبلاً مصرف شده است.', 'fandoogh-manager' ), array( 'status' => 409 ) );
	}

	$device_id    = isset( $body['device_id'] ) ? security_clean_label( $body['device_id'], 180 ) : security_random_token( 16 );
	$device_label = isset( $body['device_label'] ) ? security_clean_label( $body['device_label'], 120 ) : 'PWA browser';
	$session      = create_session_record( $user->ID, $device_id, $device_label, $scopes );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	record_audit_event( 'pairing_success', $user->ID, $session['id'], $device_label, 'auth', 0, array( 'outcome' => 'paired' ) );
	queue_session_alert_email( $user->ID, $session['id'], $device_label );

	return security_no_store_response( array(
		'data' => array(
			'user' => array(
				'id'           => absint( $user->ID ),
				'login'        => sanitize_user( $user->user_login, true ),
				'display_name' => sanitize_text_field( $user->display_name ),
			),
			'scopes'      => $scopes,
			'csrf_token'  => $session['csrf_token'],
			'expires_at'  => $session['expires_at'],
		),
	) );
}

/**
 * @return array|\WP_Error
 */
function get_session_user() {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return security_no_store_response( array(
		'data' => array(
			'user'        => array(
				'id'           => absint( $session['user']->ID ),
				'login'        => sanitize_user( $session['user']->user_login, true ),
				'display_name' => sanitize_text_field( $session['user']->display_name ),
			),
			'scopes'      => $session['scopes'],
			'device_label'=> $session['device_label'],
			'expires_at'  => $session['expires_at'],
		),
	) );
}

/**
 * @return array|\WP_Error
 */
function get_csrf_token() {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$token = security_random_token( 32 );
	if ( ! rotate_session_csrf( $session['id'], $token ) ) {
		return new \WP_Error( 'fandoogh_csrf_storage', __( 'توکن CSRF ایجاد نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	return security_no_store_response( array( 'data' => array( 'csrf_token' => $token ) ) );
}

/**
 * @return array<string, mixed>
 */
function logout_session() {
	$session = get_session_context();
	if ( ! is_wp_error( $session ) ) {
		revoke_session( $session['id'] );
		record_audit_event( 'session_revoked', $session['user']->ID, $session['id'], $session['device_label'], 'session', $session['id'], array( 'reason' => 'logout' ) );
	}
	clear_session_cookie();

	return security_no_store_response( array( 'data' => array( 'logged_out' => true ) ) );
}

/**
 * Return the selected target user ID from the admin form. Falling back to the
 * current administrator keeps the old one-click behavior compatible.
 *
 * @return int
 */
function pairing_admin_target_user_id() {
	$raw_user_id = isset( $_POST['fandoogh_manager_pairing_user_id'] ) ? wp_unslash( $_POST['fandoogh_manager_pairing_user_id'] ) : get_current_user_id();
	$user_id     = absint( $raw_user_id );

	return $user_id > 0 ? $user_id : absint( get_current_user_id() );
}

/**
 * Return a safe label for the admin-only target selector.
 *
 * @param object $user Limited WP_User/stdClass identity.
 * @return string
 */
function pairing_user_label( $user ) {
	$display_name = is_object( $user ) && isset( $user->display_name ) ? sanitize_text_field( (string) $user->display_name ) : '';
	$user_login   = is_object( $user ) && isset( $user->user_login ) ? sanitize_user( (string) $user->user_login, true ) : '';

	if ( '' !== $display_name && '' !== $user_login ) {
		return $display_name . ' (' . $user_login . ')';
	}

	return '' !== $user_login ? $user_login : ( '' !== $display_name ? $display_name : __( 'کاربر بدون نام', 'fandoogh-manager' ) );
}

/**
 * Render pairing controls inside the administrator settings page.
 *
 * @param array|string|\WP_Error $pairing_result One-time code result.
 * @return void
 */
function render_pairing_admin_section( $pairing_result = '' ) {
	$target_user_id = pairing_admin_target_user_id();
	$pairing_code   = '';
	$target_label   = '';

	if ( is_array( $pairing_result ) ) {
		$pairing_code   = isset( $pairing_result['code'] ) ? (string) $pairing_result['code'] : '';
		$target_user_id = isset( $pairing_result['target_user_id'] ) ? absint( $pairing_result['target_user_id'] ) : $target_user_id;
		$target_label   = isset( $pairing_result['target_label'] ) ? (string) $pairing_result['target_label'] : '';
	} elseif ( is_string( $pairing_result ) ) {
		$pairing_code = $pairing_result;
	}

	echo '<section class="fandoogh-admin-card fandoogh-admin-card--pairing"><p class="fandoogh-eyebrow">' . esc_html__( 'اتصال امن', 'fandoogh-manager' ) . '</p><h2>' . esc_html__( 'Secure pairing', 'fandoogh-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Administrator می‌تواند کد یک‌بارمصرف را برای هر کاربر واجد شرایط بسازد. کاربر هدف باید با نام کاربری و رمز خودش وارد وب‌اپ شود؛ کد به حساب صادرکننده منتقل نمی‌شود.', 'fandoogh-manager' ) . '</p>';

	if ( is_wp_error( $pairing_result ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $pairing_result->get_error_message() ) . '</p></div>';
	} elseif ( '' !== $pairing_code ) {
		$target_label = '' !== $target_label ? $target_label : __( 'کاربر انتخاب‌شده', 'fandoogh-manager' );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'کد زیر برای کاربر هدف ساخته شد؛ آن را فقط همان کاربر وارد کند. کد پس از این پاسخ دوباره نمایش داده نمی‌شود:', 'fandoogh-manager' ) . '</strong><br>' . esc_html__( 'کاربر هدف: ', 'fandoogh-manager' ) . esc_html( $target_label ) . '<br><code style="font-size:1.35em;letter-spacing:.12em">' . esc_html( $pairing_code ) . '</code></p></div>';
	}

	$target_users = pairing_target_users();
	echo '<form method="post" action="">';
	wp_nonce_field( 'fandoogh_manager_generate_pairing', 'fandoogh_manager_pairing_nonce' );
	echo '<input type="hidden" name="fandoogh_manager_generate_pairing" value="1">';
	echo '<p><label for="fandoogh-manager-pairing-user"><strong>' . esc_html__( 'کاربر هدف', 'fandoogh-manager' ) . '</strong></label><br>';
	if ( empty( $target_users ) ) {
		echo '<span class="description">' . esc_html__( 'هیچ کاربر واجد شرایطی برای مدیریت فندوق پیدا نشد.', 'fandoogh-manager' ) . '</span></p>';
	} else {
		echo '<select id="fandoogh-manager-pairing-user" name="fandoogh_manager_pairing_user_id" required>';
		foreach ( $target_users as $target_user ) {
			$target_id = isset( $target_user->ID ) ? absint( $target_user->ID ) : 0;
			if ( 0 === $target_id ) {
				continue;
			}

			echo '<option value="' . esc_attr( $target_id ) . '" ' . selected( $target_user_id, $target_id, false ) . '>' . esc_html( pairing_user_label( $target_user ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Scopeها بر اساس capability فعلی کاربر هدف صادر می‌شوند و هنگام ورود دوباره بررسی خواهند شد.', 'fandoogh-manager' ) . '</p>';
		submit_button( __( 'ساخت کد Pairing برای کاربر هدف', 'fandoogh-manager' ), 'secondary', 'submit', false );
	}
	echo '</form>';
	echo '</section>';
}

/**
 * Handle the inline pairing form without putting the raw code in a URL or
 * transient. The page response is the only place where the code is shown.
 *
 * @return array|string|\WP_Error
 */
function maybe_handle_pairing_admin_post() {
	if ( 'POST' !== strtoupper( (string) ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) || empty( $_POST['fandoogh_manager_generate_pairing'] ) ) {
		return '';
	}

	check_admin_referer( 'fandoogh_manager_generate_pairing', 'fandoogh_manager_pairing_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_pairing_admin_forbidden', __( 'شما اجازهٔ ساخت کد جفت‌سازی را ندارید.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	$target_user_id = pairing_admin_target_user_id();
	$target_user    = pairing_target_user( $target_user_id );
	if ( ! $target_user ) {
		return new \WP_Error( 'fandoogh_pairing_target_invalid', __( 'کاربر هدف وجود ندارد یا مجوز استفاده از مدیریت فندوق را ندارد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$pairing_code = issue_pairing_code( $target_user_id );
	if ( is_wp_error( $pairing_code ) ) {
		return $pairing_code;
	}

	record_audit_event( 'pairing_issued', get_current_user_id(), 0, 'wp-admin', 'user', $target_user_id, array( 'outcome' => 'issued' ) );

	return array(
		'code'           => $pairing_code,
		'target_user_id' => $target_user_id,
		'target_label'   => pairing_user_label( $target_user ),
	);
}
