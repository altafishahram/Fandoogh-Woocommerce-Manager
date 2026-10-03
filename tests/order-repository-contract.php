<?php

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	class WC_Order {}
	class WC_Order_Item_Shipping {}
	function wc_get_orders( $args ) { $GLOBALS['order_query_args'] = $args; return $GLOBALS['order_query_result']; }
	function wc_get_order( $id ) { $GLOBALS['order_found_id'] = $id; return $GLOBALS['order_result']; }
	function wc_order_search( $term ) { $GLOBALS['order_search_term'] = $term; return array( 3 ); }
	function wc_get_order_statuses() { return array( 'wc-processing' => 'Processing' ); }
	function wc_get_order_notes( $args ) { $GLOBALS['order_note_args'] = $args; return array( 'note' ); }
	function wc_create_order( $args ) { $GLOBALS['order_create_args'] = $args; return $GLOBALS['order_result']; }
	function wc_create_refund( $args ) { $GLOBALS['refund_args'] = $args; return $GLOBALS['refund_result']; }
}

namespace Fandoogh_Manager {
	require_once __DIR__ . '/../app/composition/orders.php';
}

namespace {
	$GLOBALS['order_query_result'] = (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 );
	$GLOBALS['order_result'] = new WC_Order();
	$GLOBALS['refund_result'] = new WC_Order();
	$repository = \Fandoogh_Manager\compose_order_repository();
	if ( ! $repository->isAvailable() || ! $repository->isCreateAvailable() || ! $repository->isRefundAvailable() ) { throw new \RuntimeException( 'Order availability contract failed.' ); }
	$args = array( 'limit' => 20, 'return' => 'objects' );
	if ( $repository->query( $args ) !== $GLOBALS['order_query_result'] || $GLOBALS['order_query_args'] !== $args ) { throw new \RuntimeException( 'Order query delegation changed.' ); }
	if ( $repository->findById( 7 ) !== $GLOBALS['order_result'] || 7 !== $GLOBALS['order_found_id'] ) { throw new \RuntimeException( 'Order lookup delegation changed.' ); }
	if ( array( 3 ) !== $repository->search( '1003' ) || '1003' !== $GLOBALS['order_search_term'] ) { throw new \RuntimeException( 'Order search delegation changed.' ); }
	if ( array( 'wc-processing' => 'Processing' ) !== $repository->statuses() ) { throw new \RuntimeException( 'Order status delegation changed.' ); }
	$note_args = array( 'order_id' => 7 );
	if ( array( 'note' ) !== $repository->queryNotes( $note_args ) || $GLOBALS['order_note_args'] !== $note_args ) { throw new \RuntimeException( 'Order note delegation changed.' ); }
	$create_args = array( 'customer_id' => 4 );
	if ( $repository->create( $create_args ) !== $GLOBALS['order_result'] || $GLOBALS['order_create_args'] !== $create_args ) { throw new \RuntimeException( 'Order creation delegation changed.' ); }
	if ( ! $repository->createShippingItem() instanceof WC_Order_Item_Shipping ) { throw new \RuntimeException( 'Shipping item factory delegation changed.' ); }
	$refund_args = array( 'order_id' => 7, 'amount' => '10.00' );
	if ( $repository->createRefund( $refund_args ) !== $GLOBALS['refund_result'] || $GLOBALS['refund_args'] !== $refund_args ) { throw new \RuntimeException( 'Refund delegation changed.' ); }
	echo "Order repository: 8 contract checks passed.\n";
}
