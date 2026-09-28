<?php

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	class WC_Customer {
		private $id;
		public function __construct( $id = 0 ) { $this->id = (int) $id; }
		public function get_id() { return $this->id; }
	}
	class WC_Customer_Data_Store {
		public function search_customers( $term, $limit ) { return array( 5, 0 ); }
		public function query_customers( $args ) { $GLOBALS['customer_query_args'] = $args; return $GLOBALS['customer_query_result']; }
	}
	function absint( $value ) { return abs( (int) $value ); }
	function get_users( $args ) { $GLOBALS['customer_user_queries'][] = $args; return array( 9 ); }
}

namespace Fandoogh_Manager {
	require_once __DIR__ . '/../app/composition/customers.php';
}

namespace {
	$GLOBALS['customer_query_result'] = (object) array( 'customers' => array( 5 ), 'total' => 1, 'max_num_pages' => 1 );
	$GLOBALS['customer_user_queries'] = array();
	$repository = \Fandoogh_Manager\compose_customer_repository();
	if ( ! $repository->isAvailable() ) { throw new \RuntimeException( 'Customer availability contract failed.' ); }
	if ( array( 5 ) !== $repository->searchIds( 'ali' ) ) { throw new \RuntimeException( 'Customer search delegation changed.' ); }
	$args = array( 'page' => 2, 'role' => 'customer' );
	if ( $repository->query( $args ) !== $GLOBALS['customer_query_result'] || $GLOBALS['customer_query_args'] !== $args ) { throw new \RuntimeException( 'Customer query delegation changed.' ); }
	$customer = $repository->findById( 7 );
	if ( ! $customer instanceof WC_Customer || 7 !== $customer->get_id() ) { throw new \RuntimeException( 'Customer lookup delegation changed.' ); }
	if ( ! $repository->create() instanceof WC_Customer ) { throw new \RuntimeException( 'Customer factory delegation changed.' ); }
	if ( ! $repository->normalizeQueryEntry( 8 ) instanceof WC_Customer ) { throw new \RuntimeException( 'Customer query normalization changed.' ); }
	echo "Customer repository: 5 contract checks passed.\n";
}
