<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for WooCommerce order reads, queries, notes, and mutations. */
interface OrderRepository {
	public function isAvailable(): bool;
	public function isCreateAvailable(): bool;
	public function isRefundAvailable(): bool;

	/** @param array<string, mixed> $args @return mixed */
	public function query( array $args );

	/** @return object|false */
	public function findById( int $id );

	/** @return mixed */
	public function search( string $term );

	/** @return array<string, string> */
	public function statuses(): array;

	/** @param array<string, mixed> $args @return mixed */
	public function queryNotes( array $args );

	/** @param array<string, mixed> $args @return mixed */
	public function create( array $args );

	/** @return object */
	public function createShippingItem();

	/** @param array<string, mixed> $args @return mixed */
	public function createRefund( array $args );
}
