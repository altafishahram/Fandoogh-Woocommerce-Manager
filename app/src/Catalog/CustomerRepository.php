<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for WooCommerce customer reads, queries, and object creation. */
interface CustomerRepository {
	public function isAvailable(): bool;

	/** Initialize the list data store before searching, preserving failure order. */
	public function initializeQuery(): void;

	/** @param array<string, mixed> $args @return mixed */
	public function query( array $args );

	/** @return array<int, int> */
	public function searchIds( string $term ): array;

	/** @return object|false */
	public function findById( int $id );

	/** @return object */
	public function create();

	/** @return object|false */
	public function normalizeQueryEntry( $value );
}
