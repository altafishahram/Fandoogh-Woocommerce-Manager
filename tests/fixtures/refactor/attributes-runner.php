<?php
/** Isolated characterization fixture; no WordPress installation or network. */
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	error_reporting( E_ALL );
	set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	} );
	define( 'ABSPATH', __DIR__ . '/' );
	$scenario = $argv[2];
	$trace = array();
	$routes = array();
	$attributes = array();
	$terms = array();
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code, $message, $data ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
	}
	class WP_REST_Response {
		public $data;
		public $status = 200;
		public $headers = array();
		public function __construct( $data ) { $this->data = $data; }
		public function header( $name, $value ) { $this->headers[ $name ] = $value; }
	}
	class WP_REST_Server { const READABLE = 'GET'; }
	function __( $text, $domain ) { return $text; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_title( $value ) {
		$GLOBALS['trace'][] = array( 'sanitize_title', $value );
		return strtolower( str_replace( ' ', '-', trim( strip_tags( $value ) ) ) );
	}
	function sanitize_text_field( $value ) {
		$GLOBALS['trace'][] = array( 'sanitize_text_field', $value );
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function taxonomy_exists( $name ) {
		$GLOBALS['trace'][] = array( 'taxonomy_exists', $name );
		return 'pa_missing' !== $name;
	}
	function get_terms( $args ) {
		$GLOBALS['trace'][] = array( 'get_terms', $args );
		return $GLOBALS['terms'][ $args['taxonomy'] ] ?? array();
	}
	function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
	function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][] = array( $namespace, $route, $args ); }
	if ( ! in_array( $scenario, array( 'no-woocommerce', 'no-attributes-api' ), true ) ) {
		function wc_get_attribute_taxonomies() {
			$GLOBALS['trace'][] = array( 'wc_get_attribute_taxonomies' );
			return $GLOBALS['attributes'];
		}
	}
	if ( ! in_array( $scenario, array( 'no-woocommerce', 'no-taxonomy-api' ), true ) ) {
		function wc_attribute_taxonomy_name( $slug ) {
			$GLOBALS['trace'][] = array( 'wc_attribute_taxonomy_name', $slug );
			return 'pa_' . $slug;
		}
	}
	if ( 'populated' === $scenario || 'permission-error' === $scenario ) {
		$attributes = array(
			(object) array( 'attribute_id' => '-7', 'attribute_name' => ' Color ', 'attribute_label' => '<b>رنگ</b>' ),
			(object) array( 'attribute_id' => 9, 'attribute_name' => 'missing', 'attribute_label' => 'Skip' ),
			(object) array( 'attribute_id' => 8, 'attribute_name' => '' ),
			(object) array( 'attribute_name' => 'size' ),
			(object) array( 'attribute_name' => 'material', 'attribute_label' => array( 'unexpected' ) ),
		);
		$terms = array(
			'pa_color' => array(
				(object) array( 'term_id' => '-21', 'name' => '<b>قرمز</b>', 'slug' => ' Red ' ),
				(object) array( 'term_id' => '22', 'name' => 'Blue', 'slug' => 'Blue' ),
			),
			'pa_size' => new WP_Error( 'term_failure', 'Keep the attribute with empty terms', array() ),
			'pa_material' => null,
		);
	}
	if ( 'object-results' === $scenario ) {
		$attributes = (object) array( 'first' => (object) array( 'attribute_name' => 'size' ) );
		$terms['pa_size'] = (object) array( 'first' => (object) array( 'term_id' => 5, 'name' => 'Large', 'slug' => 'large' ) );
	}
	if ( 'null-results' === $scenario ) { $attributes = null; }
	if ( 'false-results' === $scenario ) { $attributes = false; }
}

namespace Fandoogh_Manager {
	const REST_NAMESPACE = 'fandoogh-manager/v1';
	function products_read_permission( $request ) {
		$GLOBALS['trace'][] = array( 'products_read_permission', $request );
		return $GLOBALS['permission_result'];
	}
	function product_no_store_response( $response ) {
		$GLOBALS['trace'][] = array( 'product_no_store_response' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
}

namespace {
	require $argv[1];
	$permission_result = 'permission-error' === $scenario ? new WP_Error( 'forbidden', 'Access denied', array( 'status' => 403 ) ) : true;
	$request = (object) array( 'fixture' => 'preserve request identity' );
	\Fandoogh_Manager\register_product_attribute_routes();
	$permission = \Fandoogh_Manager\product_attributes_read_permission( $request );
	if ( $permission !== $permission_result ) { throw new \RuntimeException( 'Permission result identity changed' ); }
	$response = \Fandoogh_Manager\list_product_attributes( $request );
	echo json_encode( array( 'response_type' => get_class( $response ), 'response' => $response, 'permission' => $permission, 'routes' => $routes, 'trace' => $trace ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
}
