<?php
/**
 * Focused analytics permission and bounded-query checks using in-memory stubs.
 * Run: php tests/analytics-contract.php
 * This does not boot WordPress, execute SQL, or connect to WooCommerce.
 */

namespace {
	if ( 'cli' !== PHP_SAPI ) {
		exit;
	}
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	}
	function __( $text, $domain = '' ) { return $text; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function user_can( $user_id, $capability ) { return 7 === (int) $user_id; }
	function wc_get_orders( $args ) {
		$GLOBALS['analytics_test_order_calls'][] = $args;
		return $GLOBALS['analytics_test_orders_result'];
	}
}

namespace Fandoogh_Manager {
	$GLOBALS['analytics_test_settings'] = array( 'analytics_enabled' => true );
	$GLOBALS['analytics_test_scopes'] = array( 'analytics' => array( 'read' => true ) );
	$GLOBALS['analytics_test_orders_result'] = null;
	$GLOBALS['analytics_test_order_calls'] = array();

	function get_session_context() {
		return array( 'user' => (object) array( 'ID' => 7 ), 'scopes' => $GLOBALS['analytics_test_scopes'] );
	}
	function get_settings() { return $GLOBALS['analytics_test_settings']; }
	function session_has_scope( $scope, $scopes ) {
		$parts = explode( '.', $scope, 2 );
		return 2 === count( $parts ) && ! empty( $scopes[ $parts[0] ][ $parts[1] ] );
	}
	function apply_session_user_context( $session ) {}
}

namespace {
	use function Fandoogh_Manager\analytics_fetch_orders;
	use function Fandoogh_Manager\analytics_read_permission;

	class Analytics_Test_Request {}
	require __DIR__ . '/../app/analytics.php';
	$analytics_test_count = 0;

	function analytics_expect_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
	function analytics_expect_error( $value, $code, $status ) {
		analytics_expect_same( true, is_wp_error( $value ), 'Expected a WP_Error' );
		analytics_expect_same( $code, $value->code, 'Error code' );
		analytics_expect_same( $status, $value->data['status'], 'HTTP status' );
	}
	function analytics_test_case( $name, $callback ) {
		try {
			$callback();
			++$GLOBALS['analytics_test_count'];
			echo 'PASS ' . $name . PHP_EOL;
		} catch ( \Throwable $error ) {
			fwrite( STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL );
			exit( 1 );
		}
	}
	function analytics_reset_fixture() {
		$GLOBALS['analytics_test_settings'] = array( 'analytics_enabled' => true );
		$GLOBALS['analytics_test_scopes'] = array( 'analytics' => array( 'read' => true ) );
		$GLOBALS['analytics_test_orders_result'] = null;
		$GLOBALS['analytics_test_order_calls'] = array();
	}

	analytics_test_case( 'disabled reports take precedence over a missing session scope', function () {
		analytics_reset_fixture();
		$GLOBALS['analytics_test_settings']['analytics_enabled'] = false;
		$GLOBALS['analytics_test_scopes'] = array();
		analytics_expect_error( analytics_read_permission( new Analytics_Test_Request() ), 'fandoogh_analytics_disabled', 403 );
	} );

	analytics_test_case( 'enabled reports reject a session without analytics.read', function () {
		analytics_reset_fixture();
		$GLOBALS['analytics_test_scopes'] = array();
		analytics_expect_error( analytics_read_permission( new Analytics_Test_Request() ), 'fandoogh_analytics_forbidden', 403 );
	} );

	foreach ( array( false, new WP_Error( 'woocommerce_failure', 'Private failure' ), (object) array( 'orders' => 'not-an-array' ) ) as $invalid_result ) {
		analytics_test_case( 'invalid paginated WooCommerce result is not rendered as an empty report', function () use ( $invalid_result ) {
			analytics_reset_fixture();
			$GLOBALS['analytics_test_orders_result'] = $invalid_result;
			analytics_expect_error( analytics_fetch_orders( array( 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-01 23:59:59' ) ), 'fandoogh_analytics_failed', 503 );
			analytics_expect_same( 1, count( $GLOBALS['analytics_test_order_calls'] ), 'Failed query stops immediately' );
		} );
	}

	analytics_test_case( 'page requests remain bounded at the analytics maximum', function () {
		analytics_reset_fixture();
		$GLOBALS['analytics_test_orders_result'] = (object) array(
			'orders' => array_fill( 0, \Fandoogh_Manager\ANALYTICS_PAGE_SIZE, (object) array() ),
			'max_num_pages' => \Fandoogh_Manager\ANALYTICS_MAX_PAGES + 1,
		);
		$result = analytics_fetch_orders( array( 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-01 23:59:59' ) );
		analytics_expect_same( false, is_wp_error( $result ), 'Bounded result succeeds' );
		analytics_expect_same( \Fandoogh_Manager\ANALYTICS_MAX_PAGES, count( $GLOBALS['analytics_test_order_calls'] ), 'No more than the page cap is queried' );
		analytics_expect_same( true, $result['truncated'], 'A page beyond the cap is reported as truncated' );
		$last_args = end( $GLOBALS['analytics_test_order_calls'] );
		analytics_expect_same( \Fandoogh_Manager\ANALYTICS_MAX_PAGES, $last_args['paged'], 'Final query is the capped page' );
	} );

	echo $analytics_test_count . ' analytics unit/contract checks passed (standalone stubs; not live WooCommerce integration).' . PHP_EOL;
}
