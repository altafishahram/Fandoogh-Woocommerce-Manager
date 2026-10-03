<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/CouponRequestParser.php';
require_once __DIR__ . '/../src/Catalog/CouponRepository.php';
require_once __DIR__ . '/../src/Infrastructure/WooCommerceCouponRepository.php';

/** @return \Fandoogh_Manager\Catalog\CouponRequestParser */
function compose_coupon_request_parser(): \Fandoogh_Manager\Catalog\CouponRequestParser {
	return new \Fandoogh_Manager\Catalog\CouponRequestParser( __NAMESPACE__ . '\\coupons_error' );
}

/** @return \Fandoogh_Manager\Catalog\CouponRepository */
function compose_coupon_repository(): \Fandoogh_Manager\Catalog\CouponRepository {
	return new \Fandoogh_Manager\Infrastructure\WooCommerceCouponRepository(
		static function ( int $id ): object { return new \WC_Coupon( $id ); },
		static function ( array $args ): object { return new \WP_Query( $args ); }
	);
}

/**
 * Keep the order path's code-based constructor and exception behavior.
 *
 * @param string $code Coupon code supplied with an order.
 * @return object
 */
function compose_order_coupon( string $code ): object {
	return new \WC_Coupon( $code );
}
