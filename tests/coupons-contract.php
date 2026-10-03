<?php
/**
 * Focused endpoint unit/contract checks, using in-memory WordPress/Woo stubs.
 * Run: php tests/coupons-contract.php
 * This does NOT boot WordPress, execute SQL, or constitute live Woo integration.
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
		public function __construct( $code, $message, $data = array() ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
	}

	class WP_REST_Response {
		public $data;
		public $status = 200;
		public $headers = array();
		public function __construct( $data ) { $this->data = $data; }
		public function set_status( $status ) { $this->status = $status; }
		public function header( $name, $value ) { $this->headers[ $name ] = $value; }
	}

	class Coupon_Test_Request {
		private $params;
		public function __construct( $params = array() ) { $this->params = $params; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function get_header( $key ) { return 'content-type' === $key ? 'application/json' : ''; }
		public function get_json_params() { return $this->params; }
	}

	function __( $text, $domain = '' ) { return $text; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
	function get_post_status( $id ) { return WC_Coupon::$rows[ $id ]['status'] ?? false; }
	function wc_get_coupon_id_by_code( $code ) {
		foreach ( WC_Coupon::$rows as $id => $row ) {
			if ( 0 === strcasecmp( $row['code'], $code ) ) {
				return $id;
			}
		}
		return 0;
	}

	/** Minimal CRUD double: only setters used by these create/list checks. */
	class WC_Coupon {
		public static $rows = array();
		public static $save_result = null;
		public static $read_failure = '';
		private $data = array(
			'id' => 0, 'post_type' => 'shop_coupon', 'date' => '2026-09-05 12:00:00',
			'code' => '', 'description' => '', 'discount_type' => 'fixed_cart',
			'amount' => '0', 'status' => 'publish',
		);
		public function __construct( $id = 0 ) {
			if ( $id && 'throw' === self::$read_failure ) {
				throw new \RuntimeException( 'Private datastore error' );
			}
			if ( $id && 'missing' !== self::$read_failure && isset( self::$rows[ $id ] ) ) {
				$this->data = self::$rows[ $id ];
			}
		}
		public function get_id() { return $this->data['id']; }
		public function set_code( $value ) { $this->data['code'] = strtolower( $value ); }
		public function set_description( $value ) { $this->data['description'] = $value; }
		public function set_discount_type( $value ) { $this->data['discount_type'] = $value; }
		public function set_amount( $value ) { $this->data['amount'] = $value; }
		public function set_status( $value ) { $this->data['status'] = $value; }
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'get_' ) ) {
				return $this->data[ substr( $name, 4 ) ] ?? null;
			}
			throw new \BadMethodCallException( $name );
		}
		public function save() {
			if ( null !== self::$save_result ) {
				return self::$save_result;
			}
			if ( ! $this->get_id() ) {
				$this->data['id'] = count( self::$rows ) + 1;
			}
			self::$rows[ $this->get_id() ] = $this->data;
			return $this->get_id();
		}
	}
}

namespace Fandoogh_Manager {
	// Endpoint callbacks are tested directly; permission/CSRF hooks are not mocked as tests.
	function get_session_context() {
		return array( 'user' => (object) array( 'ID' => 7 ), 'id' => 'unit-session', 'device_label' => 'unit-device' );
	}
	function apply_session_user_context( $session ) {}
	function record_audit_event( ...$args ) { $GLOBALS['coupon_test_audit'][] = $args; }
}

namespace {
	use function Fandoogh_Manager\create_coupon;
	use function Fandoogh_Manager\list_coupons;

	require __DIR__ . '/../app/coupons.php';
	$wpdb = (object) array( 'last_error' => '' );
	$coupon_test_count = 0;
	$coupon_test_audit = array();

	function expect_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
	function expect_error( $response, $code ) {
		expect_same( true, is_wp_error( $response ), 'Failure must be WP_Error, not a success response' );
		expect_same( $code, $response->code, 'Error code' );
		expect_same( 500, $response->data['status'], 'Failure HTTP status' );
		expect_same( false, strpos( $response->message, 'Private' ), 'Internal errors are not exposed' );
	}
	function test_case( $name, $callback ) {
		try {
			$callback();
			++$GLOBALS['coupon_test_count'];
			echo 'PASS ' . $name . PHP_EOL;
		} catch ( \Throwable $error ) {
			fwrite( STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL );
			exit( 1 );
		}
	}

	// The conditional declaration below deliberately leaves WP_Query unavailable here.
	test_case( 'missing query API returns an error instead of success-empty', function () {
		expect_same( false, class_exists( 'WP_Query' ), 'No query class loaded yet' );
		expect_error( list_coupons( new Coupon_Test_Request() ), 'fandoogh_coupons_query_failed' );
	} );

	if ( ! class_exists( 'WP_Query' ) ) {
		/** In-memory query contract double; it does not reproduce WordPress SQL. */
		class WP_Query {
			public static $last_args = array();
			public static $failure = '';
			public $posts = array();
			public $found_posts = 0;
			public $max_num_pages = 0;
			public function __construct( $args ) {
				self::$last_args = $args;
				if ( 'throw' === self::$failure ) {
					throw new \RuntimeException( 'Private query error' );
				}
				if ( 'database' === self::$failure ) {
					$GLOBALS['wpdb']->last_error = 'Private database error';
					return;
				}
				if ( 'false' === self::$failure || 'wp_error' === self::$failure ) {
					$this->posts = 'false' === self::$failure ? false : new WP_Error( 'query', 'Private query error' );
					return;
				}
				$rows = array_filter( WC_Coupon::$rows, function ( $row ) use ( $args ) {
					if ( $row['post_type'] !== $args['post_type'] || ! in_array( $row['status'], $args['post_status'], true ) ) {
						return false;
					}
					return ! isset( $args['s'] ) || false !== stripos( $row['code'] . ' ' . $row['description'], $args['s'] );
				} );
				usort( $rows, function ( $left, $right ) use ( $args ) {
					foreach ( $args['orderby'] as $field => $direction ) {
						$key = 'ID' === $field ? 'id' : $field;
						$comparison = $left[ $key ] <=> $right[ $key ];
						if ( $comparison ) {
							return 'DESC' === $direction ? -$comparison : $comparison;
						}
					}
					return 0;
				} );
				$this->found_posts = count( $rows );
				$this->max_num_pages = (int) ceil( $this->found_posts / $args['posts_per_page'] );
				$rows = array_slice( $rows, ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
				$this->posts = 'ids' === $args['fields'] ? array_column( $rows, 'id' ) : $rows;
			}
		}
	}

	function reset_fixture() {
		WC_Coupon::$rows = array();
		WC_Coupon::$save_result = null;
		WC_Coupon::$read_failure = '';
		WP_Query::$failure = '';
		WP_Query::$last_args = array();
		$GLOBALS['wpdb']->last_error = '';
		$GLOBALS['coupon_test_audit'] = array();
	}
	function create_fixture( $code, $extra = array() ) {
		$response = create_coupon( new Coupon_Test_Request( array_merge( array( 'code' => $code, 'amount' => '10' ), $extra ) ) );
		expect_same( true, $response instanceof WP_REST_Response, 'Create response type' );
		expect_same( 201, $response->status, 'Create status' );
		return $response->data['data']['id'];
	}
	function listed_ids( $params = array() ) {
		$response = list_coupons( new Coupon_Test_Request( $params ) );
		expect_same( true, $response instanceof WP_REST_Response, 'List response type' );
		expect_same( 200, $response->status, 'List status' );
		return array_column( $response->data['data'], 'id' );
	}

	test_case( 'created coupon is listed without wc_get_coupons and retains response shape', function () {
		reset_fixture();
		expect_same( false, function_exists( 'wc_get_coupons' ), 'No nonstandard helper stub' );
		$id = create_fixture( 'WELCOME', array( 'description' => 'Welcome discount', 'discount_type' => 'percent' ) );
		$response = list_coupons( new Coupon_Test_Request() );
		expect_same( array( $id ), array_column( $response->data['data'], 'id' ), 'Created ID appears' );
		expect_same( 'welcome', $response->data['data'][0]['code'], 'CRUD normalized code' );
		expect_same( 'Welcome discount', $response->data['data'][0]['description'], 'Existing serialization' );
		expect_same( 'percent', $response->data['data'][0]['discount_type'], 'Discount type' );
		expect_same( 'publish', $response->data['data'][0]['status'], 'Created status' );
		expect_same( array( 'page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1 ), $response->data['meta'], 'Pagination metadata' );
		expect_same( 'no-store, no-cache, must-revalidate, max-age=0', $response->headers['Cache-Control'], 'No-store contract preserved' );
		expect_same( 'shop_coupon', WP_Query::$last_args['post_type'], 'Coupon post type' );
		expect_same( 'ids', WP_Query::$last_args['fields'], 'Query IDs only' );
		expect_same( false, WP_Query::$last_args['no_found_rows'], 'Pagination counts enabled' );
	} );

	test_case( 'date ordering and ID tie-break keep pages stable', function () {
		reset_fixture();
		$old = create_fixture( 'OLD' );
		$new = create_fixture( 'NEW' );
		$tied = create_fixture( 'TIED' );
		WC_Coupon::$rows[ $old ]['date'] = '2026-09-01 12:00:00';
		expect_same( array( $tied, $new ), listed_ids( array( 'per_page' => 2 ) ), 'First page newest first' );
		expect_same( array( $old ), listed_ids( array( 'page' => 2, 'per_page' => 2 ) ), 'Second page without duplicates' );
		$response = list_coupons( new Coupon_Test_Request( array( 'page' => 2, 'per_page' => 2 ) ) );
		expect_same( array( 'page' => 2, 'per_page' => 2, 'total' => 3, 'total_pages' => 2 ), $response->data['meta'], 'Counts cover all pages' );
	} );

	test_case( 'explicit statuses exclude trash, auto-drafts, inherited and other post types', function () {
		reset_fixture();
		$allowed = array();
		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ) as $status ) {
			$id = create_fixture( 'STATUS_' . str_replace( '-', '_', $status ) );
			WC_Coupon::$rows[ $id ]['status'] = $status;
			if ( in_array( $status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
				$allowed[] = $id;
			}
		}
		$other = create_fixture( 'NOT_A_COUPON' );
		WC_Coupon::$rows[ $other ]['post_type'] = 'product';
		expect_same( array_reverse( $allowed ), listed_ids(), 'Only allowed coupon records' );
	} );

	test_case( 'search uses sanitized bounded s and paginates matching codes/descriptions', function () {
		reset_fixture();
		$code = create_fixture( 'SUMMER25' );
		$description = create_fixture( 'OTHER', array( 'description' => 'Summer campaign' ) );
		create_fixture( 'WINTER' );
		expect_same( array( $description ), listed_ids( array( 'search' => ' <b>summer</b> ', 'per_page' => 1 ) ), 'Description match first' );
		expect_same( 'summer', WP_Query::$last_args['s'], 'Search sanitized' );
		expect_same( false, isset( WP_Query::$last_args['search'] ), 'Use WP_Query search parameter' );
		expect_same( array( $code ), listed_ids( array( 'search' => 'summer', 'page' => 2, 'per_page' => 1 ) ), 'Partial code on next page' );
		$response = list_coupons( new Coupon_Test_Request( array( 'search' => 'summer', 'per_page' => 1 ) ) );
		expect_same( 2, $response->data['meta']['total'], 'Search total' );
		listed_ids( array( 'search' => str_repeat( 'a', 120 ) ) );
		expect_same( 80, strlen( WP_Query::$last_args['s'] ), 'Search length bounded' );
		listed_ids( array( 'search' => array( 'unexpected' ) ) );
		expect_same( false, isset( WP_Query::$last_args['s'] ), 'Structured search not forwarded' );
	} );

	test_case( 'pagination remains bounded', function () {
		reset_fixture();
		listed_ids( array( 'page' => 9999999, 'per_page' => 9999999 ) );
		expect_same( 100000, WP_Query::$last_args['paged'], 'Maximum page' );
		expect_same( 50, WP_Query::$last_args['posts_per_page'], 'Maximum page size' );
	} );

	test_case( 'genuine empty result stays successful despite stale database diagnostics', function () {
		reset_fixture();
		$GLOBALS['wpdb']->last_error = 'Private error from an earlier unrelated query';
		$response = list_coupons( new Coupon_Test_Request() );
		expect_same( 200, $response->status, 'Successful empty query' );
		expect_same( array(), $response->data['data'], 'No matches' );
		expect_same( 0, $response->data['meta']['total'], 'Empty total' );
		expect_same( 0, $response->data['meta']['total_pages'], 'Empty page count' );
	} );

	foreach ( array( 'throw', 'database', 'false', 'wp_error' ) as $failure ) {
		test_case( 'query failure ' . $failure . ' cannot become success-empty', function () use ( $failure ) {
			reset_fixture();
			WP_Query::$failure = $failure;
			expect_error( list_coupons( new Coupon_Test_Request() ), 'fandoogh_coupons_query_failed' );
		} );
	}
	foreach ( array( 'throw', 'missing' ) as $failure ) {
		test_case( 'coupon hydration failure ' . $failure . ' is not silently dropped', function () use ( $failure ) {
			reset_fixture();
			create_fixture( 'READFAIL' );
			WC_Coupon::$read_failure = $failure;
			expect_error( list_coupons( new Coupon_Test_Request() ), 'fandoogh_coupons_query_failed' );
		} );
	}
	foreach ( array( 0, false ) as $failure ) {
		test_case( 'save returning ' . var_export( $failure, true ) . ' is not announced as created', function () use ( $failure ) {
			reset_fixture();
			WC_Coupon::$save_result = $failure;
			$response = create_coupon( new Coupon_Test_Request( array( 'code' => 'SAVEFAIL', 'amount' => '10' ) ) );
			expect_error( $response, 'fandoogh_coupon_save_failed' );
			expect_same( array(), WC_Coupon::$rows, 'Nothing persisted' );
			expect_same( array(), $GLOBALS['coupon_test_audit'], 'No creation audit on failure' );
		} );
	}

	echo $coupon_test_count . ' coupon unit/contract checks passed (standalone stubs; not live WP integration).' . PHP_EOL;
}
