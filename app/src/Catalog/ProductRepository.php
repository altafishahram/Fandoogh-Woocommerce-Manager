<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for WooCommerce product reads and creation. */
interface ProductRepository {
	public function isAvailable(): bool;
	public function isWriteAvailable(): bool;
	/** @param array<string, mixed> $args @return mixed WooCommerce result. */
	public function query( array $args );
	/** @return object|false */
	public function findById( int $id );
	/** @return object|null */
	public function create( string $type );
	public function createVariation();
	public function createAttribute();
	public function findIdBySku( string $sku ): int;
}
