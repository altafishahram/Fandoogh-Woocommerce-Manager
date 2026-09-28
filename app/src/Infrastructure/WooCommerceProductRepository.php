<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Adapter for WooCommerce product APIs; constructors are supplied externally. */
final class WooCommerceProductRepository implements ProductRepository {
	/** @var callable(string): object|null */
	private $productFactory;
	/** @var callable(): object */
	private $variationFactory;
	/** @var callable(): object */
	private $attributeFactory;

	/**
	 * @param callable(string): object|null $productFactory Product type factory.
	 * @param callable(): object $variationFactory Variation factory.
	 * @param callable(): object $attributeFactory Attribute factory.
	 */
	public function __construct( callable $productFactory, callable $variationFactory, callable $attributeFactory ) {
		$this->productFactory = $productFactory;
		$this->variationFactory = $variationFactory;
		$this->attributeFactory = $attributeFactory;
	}

	public function isAvailable(): bool {
		return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' ) && class_exists( '\\WC_Product' );
	}

	public function isWriteAvailable(): bool {
		return $this->isAvailable() && class_exists( '\\WC_Product_Simple' ) && class_exists( '\\WC_Product_Variable' ) && class_exists( '\\WC_Product_Attribute' );
	}

	public function query( array $args ) { return \wc_get_products( $args ); }

	public function findById( int $id ) { return $id && function_exists( 'wc_get_product' ) ? \wc_get_product( $id ) : false; }

	public function create( string $type ) { return ( $this->productFactory )( $type ); }

	public function createVariation() { return ( $this->variationFactory )(); }

	public function createAttribute() { return ( $this->attributeFactory )(); }

	public function findIdBySku( string $sku ): int {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) { return 0; }
		return \absint( \wc_get_product_id_by_sku( $sku ) );
	}
}
