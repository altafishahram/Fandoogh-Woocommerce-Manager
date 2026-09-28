<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\CouponRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Adapter around WooCommerce CRUD. Object construction is supplied by the composition root. */
final class WooCommerceCouponRepository implements CouponRepository {
	/** @var callable(int): object */
	private $couponFactory;
	/** @var callable(array<string, mixed>): object */
	private $queryFactory;

	/**
	 * @param callable(int): object $couponFactory Factory for WC_Coupon objects.
	 * @param callable(array<string, mixed>): object $queryFactory Factory for WP_Query objects.
	 */
	public function __construct( callable $couponFactory, callable $queryFactory ) {
		$this->couponFactory = $couponFactory;
		$this->queryFactory = $queryFactory;
	}

	public function isAvailable(): bool {
		return class_exists( '\\WC_Coupon' ) && function_exists( 'wc_get_coupon_id_by_code' );
	}

	public function findById( int $id ) {
		if ( ! $this->isAvailable() ) {
			return null;
		}
		try {
			return ( $this->couponFactory )( $id );
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	public function findIdByCode( string $code ): int {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return 0;
		}
		return \absint( \wc_get_coupon_id_by_code( $code ) );
	}

	public function create() {
		return ( $this->couponFactory )( 0 );
	}

	public function query( array $args ) {
		return ( $this->queryFactory )( $args );
	}
}
