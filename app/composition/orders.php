<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/OrderRepository.php';
require_once __DIR__ . '/../src/Infrastructure/WooCommerceOrderRepository.php';

/** @return \Fandoogh_Manager\Catalog\OrderRepository */
function compose_order_repository(): \Fandoogh_Manager\Catalog\OrderRepository {
	return new \Fandoogh_Manager\Infrastructure\WooCommerceOrderRepository(
		static function ( array $args ) { return \wc_get_orders( $args ); },
		static function ( int $id ) { return \wc_get_order( $id ); },
		static function ( string $term ) { return \wc_order_search( $term ); },
		static function (): array { return (array) \wc_get_order_statuses(); },
		static function ( array $args ) { return \wc_get_order_notes( $args ); },
		static function ( array $args ) { return \wc_create_order( $args ); },
		static function (): object { return new \WC_Order_Item_Shipping(); },
		static function ( array $args ) { return \wc_create_refund( $args ); }
	);
}
