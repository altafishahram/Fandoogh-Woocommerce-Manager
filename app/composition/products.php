<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/ProductRepository.php';
require_once __DIR__ . '/../src/Infrastructure/WooCommerceProductRepository.php';

/** @return \Fandoogh_Manager\Catalog\ProductRepository */
function compose_product_repository(): \Fandoogh_Manager\Catalog\ProductRepository {
	return new \Fandoogh_Manager\Infrastructure\WooCommerceProductRepository(
		static function ( string $type ): ?object {
			if ( 'variable' === $type ) { return new \WC_Product_Variable(); }
			if ( 'subscription' === $type && class_exists( '\\WC_Product_Subscription' ) ) { return new \WC_Product_Subscription(); }
			if ( 'variable-subscription' === $type && class_exists( '\\WC_Product_Variable_Subscription' ) ) { return new \WC_Product_Variable_Subscription(); }
			if ( in_array( $type, array( 'subscription', 'variable-subscription' ), true ) ) { return null; }
			return new \WC_Product_Simple();
		},
		static function (): object { return new \WC_Product_Variation(); },
		static function (): object { return new \WC_Product_Attribute(); }
	);
}
