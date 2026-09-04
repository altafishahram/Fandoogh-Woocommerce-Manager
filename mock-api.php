<?php
/*
 * Mock WordPress REST endpoint for end-to-end testing of the Fandoogh Manager
 * PWA error flows. Implements ONLY the routes the app needs for this test:
 *   GET  /config               -> minimal config payload (real API URLs)
 *   POST /auth/pair            -> first call: success; replay of same code: 409 + Persian message
 *   GET  /auth/me, /auth/csrf  -> session probes (unused once paired via mock)
 *   GET  /coupons              -> coupon list
 *   POST /coupons              -> duplicate code: 409 + Persian message
 * Run: php -S 127.0.0.1:8095 mock-api.php  (from the project root)
 */
$codes_used = array();
$coupon_codes = array( 'WELCOME10' );
$pending_extra = 0;

// Persist state across PHP -S requests (each request is a fresh process).
$__state_file = sys_get_temp_dir() . '/fandoogh-mock-state.json';
if ( file_exists( $__state_file ) ) {
	$__saved = json_decode( (string) file_get_contents( $__state_file ), true ) ?: array();
	$codes_used   = isset( $__saved['codes'] ) ? $__saved['codes'] : array();
	$coupon_codes = isset( $__saved['coupons'] ) ? $__saved['coupons'] : $coupon_codes;
	$pending_extra = isset( $__saved['pending_extra'] ) ? max( 0, (int) $__saved['pending_extra'] ) : 0;
}
function save_state() {
	file_put_contents( $GLOBALS['__state_file'], json_encode( array( 'codes' => $GLOBALS['codes_used'], 'coupons' => $GLOBALS['coupon_codes'], 'pending_extra' => $GLOBALS['pending_extra'] ) ) );
}

function json_out( $status, $payload ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $payload, JSON_UNESCAPED_UNICODE );
	exit;
}

$uri  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$base = rtrim( dirname( __FILE__ ), '/\\' );
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode( file_get_contents( 'php://input' ), true ) ?: array();

// Serve static files from disk like a normal server. The real plugin serves
// the shell at /manager/ with assets relative to it; / here maps to app/.
if ( $method === 'GET' && ! preg_match( '#^/wp-json/#', $uri ) ) {
	$file = $base . ( $uri === '/' ? '/app/index.html' : $uri );
	if ( ! is_file( $file ) && is_file( $base . '/app' . $uri ) ) {
		$file = $base . '/app' . $uri; // /app.js -> app/app.js, /styles.css -> app/styles.css
	}
	$ext = pathinfo( $file, PATHINFO_EXTENSION );
	if ( is_file( $file ) && $ext !== 'php' ) {
		$types = array( 'html' => 'text/html', 'js' => 'application/javascript', 'css' => 'text/css', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon' );
		header( 'Content-Type: ' . ( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream' ) );
		echo file_get_contents( $file );
		exit;
	}
}

$origin = 'http://' . $_SERVER['HTTP_HOST'];
function ep( $path ) { global $origin; return $origin . '/wp-json/fandoogh-manager/v1/' . $path; }

// fonts.php is served as CSS by the real plugin (asset route); stub it here.
if ( preg_match( '#^/fonts\.css$#', $uri ) ) {
	header( 'Content-Type: text/css' );
	echo '/* mock: no local fonts configured */';
	exit;
}

if ( preg_match( '#^/wp-json/fandoogh-manager/v1/config$#', $uri ) ) {
	// The app's normalizeConfig() reads a FLAT payload (no REST 'data' wrapper).
	json_out( 200, array(
		'site_url'    => $origin,
		'app_url'     => $origin . '/',
		'version'     => 'mock',
		'branding'    => array( 'site_name' => 'فروشگاه آزمون' ),
		'api'         => array(
			'pair'     => ep( 'auth/pair' ),
			'me'       => ep( 'auth/me' ),
			'csrf'     => ep( 'auth/csrf' ),
			'logout'   => ep( 'auth/logout' ),
			'devices'  => ep( 'auth/devices' ),
			'audit'    => ep( 'auth/audit' ),
			'products' => ep( 'products' ),
			'categories' => ep( 'product-categories' ),
			'customers'  => ep( 'customers' ),
			'orders'     => ep( 'orders' ),
			'analytics'  => ep( 'analytics/summary' ),
			'coupons'    => ep( 'coupons' ),
			'reviews'    => ep( 'reviews' ),
			'inventory'  => ep( 'inventory' ),
			'media'      => ep( 'media' ),
		),
	) );
}

if ( preg_match( '#^/wp-json/fandoogh-manager/v1/auth/pair$#', $uri ) && $method === 'POST' ) {
	$code = isset( $body['pairing_code'] ) ? (string) $body['pairing_code'] : '';
	// Mirror the real flow: wrong/expired code first, then replay detection.
	if ( $code !== '123456789012' ) {
		json_out( 401, array( 'code' => 'fandoogh_pairing_invalid', 'message' => 'کد جفت‌سازی معتبر نیست یا منقضی شده است.' ) );
	}
	if ( in_array( $code, $GLOBALS['codes_used'], true ) ) {
		json_out( 409, array( 'code' => 'fandoogh_pairing_replayed', 'message' => 'کد جفت‌سازی قبلاً مصرف شده است.' ) );
	}
	$GLOBALS['codes_used'][] = $code;
	save_state();
	json_out( 200, array(
		'data' => array(
			'csrf_token' => 'mock-csrf-token',
			'user'       => array( 'display_name' => 'مدیر آزمون', 'login' => 'admin', 'role_label' => 'مدیر فروشگاه' ),
			'scopes'     => array( 'coupons' => array( 'read' => true, 'write' => true, 'create' => true, 'update' => true ), 'orders' => array( 'read' => true, 'write' => true, 'create' => true, 'update' => true ) ),
		),
	) );
}

if ( preg_match( '#^/wp-json/fandoogh-manager/v1/auth/(me|csrf)$#', $uri ) ) {
	json_out( 200, array( 'data' => array( 'authenticated' => false ) ) );
}

if ( preg_match( '#^/wp-json/fandoogh-manager/v1/orders$#', $uri ) && $method === 'GET' ) {
	$orders = array(
		array( 'id' => '8101', 'number' => '8101', 'status' => 'pending', 'status_label' => 'در انتظار پرداخت', 'created_at' => '2026-09-03T08:00:00+03:30', 'total' => '1200000', 'currency' => 'IRT', 'customer' => array( 'display' => 'نگار احمدی' ) ),
		array( 'id' => '8102', 'number' => '8102', 'status' => 'pending', 'status_label' => 'در انتظار پرداخت', 'created_at' => '2026-09-03T07:40:00+03:30', 'total' => '450000', 'currency' => 'IRT', 'customer' => array( 'display' => 'آرش موسوی' ) ),
		array( 'id' => '8103', 'number' => '8103', 'status' => 'on-hold', 'status_label' => 'در انتظار بررسی', 'created_at' => '2026-09-02T19:10:00+03:30', 'total' => '980000', 'currency' => 'IRT', 'customer' => array( 'display' => 'سپیده رحیمی' ) ),
		array( 'id' => '8104', 'number' => '8104', 'status' => 'processing', 'status_label' => 'در حال پردازش', 'created_at' => '2026-09-02T15:25:00+03:30', 'total' => '2100000', 'currency' => 'IRT', 'customer' => array( 'display' => 'کیان جعفری' ) ),
	);
	for ( $i = 0; $i < $pending_extra; $i++ ) {
		$orders[] = array( 'id' => (string) ( 8200 + $i ), 'number' => (string) ( 8200 + $i ), 'status' => 'pending', 'status_label' => 'در انتظار پرداخت', 'created_at' => '2026-09-04T0' . ( 1 + $i ) . ':00:00+03:30', 'total' => '300000', 'currency' => 'IRT', 'customer' => array( 'display' => 'مشتری تست' ) );
	}
	$pending = array_values( array_filter( $orders, function ( $o ) { return in_array( $o['status'], array( 'pending' ), true ); } ) );
	json_out( 200, array(
		'data' => $pending,
		'meta' => array( 'total' => count( $pending ), 'total_pages' => 1, 'page' => 1 ),
	) );
}

if ( preg_match( '#^/wp-json/fandoogh-manager/v1/coupons$#', $uri ) ) {
	if ( $method === 'GET' ) {
		json_out( 200, array( 'data' => array(), 'meta' => array( 'total' => 0 ) ) );
	}
	if ( $method === 'POST' ) {
		$code = isset( $body['code'] ) ? trim( strtoupper( (string) $body['code'] ) ) : '';
		if ( in_array( $code, $GLOBALS['coupon_codes'], true ) ) {
			json_out( 409, array( 'code' => 'fandoogh_coupon_duplicate', 'message' => 'این کد کوپن قبلاً وجود دارد.' ) );
		}
		$GLOBALS['coupon_codes'][] = $code;
		save_state();
		json_out( 201, array( 'data' => array( 'id' => 99, 'code' => $code ) ) );
	}
}

http_response_code( 404 );
header( 'Content-Type: application/json' );
echo json_encode( array( 'code' => 'not_found', 'message' => 'مسیر یافت نشد.' ) );
