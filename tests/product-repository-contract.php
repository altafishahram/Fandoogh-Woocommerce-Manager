<?php

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	class WC_Product {}
	class WC_Product_Simple extends WC_Product {}
	class WC_Product_Variable extends WC_Product {}
	class WC_Product_Variation extends WC_Product {}
	class WC_Product_Attribute {}
	class Product_Query_Double {}
	function wc_get_products( $args ) { $GLOBALS['query_args'] = $args; return $GLOBALS['query_result']; }
	function wc_get_product( $id ) { $GLOBALS['found_id'] = $id; return $GLOBALS['found_product']; }
	function wc_get_product_id_by_sku( $sku ) { $GLOBALS['found_sku'] = $sku; return '19'; }
	function absint( $value ) { return abs( (int) $value ); }
}

namespace Fandoogh_Manager {
	require_once __DIR__ . '/../app/composition/products.php';
}

namespace {
	$GLOBALS['query_result'] = (object) array( 'products' => array(), 'total' => 0 );
	$GLOBALS['found_product'] = new WC_Product();
	$repository = \Fandoogh_Manager\compose_product_repository();
	if ( ! $repository->isAvailable() || ! $repository->isWriteAvailable() ) { throw new \RuntimeException( 'Product availability contract failed.' ); }
	$args = array( 'limit' => 20, 'return' => 'objects' );
	if ( $repository->query( $args ) !== $GLOBALS['query_result'] || $GLOBALS['query_args'] !== $args ) { throw new \RuntimeException( 'Query delegation changed.' ); }
	if ( $repository->findById( 7 ) !== $GLOBALS['found_product'] || 7 !== $GLOBALS['found_id'] ) { throw new \RuntimeException( 'Find delegation changed.' ); }
	if ( ! $repository->create( 'simple' ) instanceof WC_Product_Simple ) { throw new \RuntimeException( 'Simple factory changed.' ); }
	if ( ! $repository->create( 'variable' ) instanceof WC_Product_Variable ) { throw new \RuntimeException( 'Variable factory changed.' ); }
	if ( null !== $repository->create( 'subscription' ) ) { throw new \RuntimeException( 'Missing subscription must remain unavailable.' ); }
	if ( ! $repository->createVariation() instanceof WC_Product_Variation ) { throw new \RuntimeException( 'Variation factory changed.' ); }
	if ( ! $repository->createAttribute() instanceof WC_Product_Attribute ) { throw new \RuntimeException( 'Attribute factory changed.' ); }
	if ( 19 !== $repository->findIdBySku( 'SKU-19' ) || 'SKU-19' !== $GLOBALS['found_sku'] ) { throw new \RuntimeException( 'SKU lookup changed.' ); }
	echo "Product repository: 9 contract checks passed.\n";
}
