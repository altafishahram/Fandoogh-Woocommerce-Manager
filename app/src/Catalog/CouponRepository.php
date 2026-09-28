<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for coupon persistence and lookup; CRUD details stay outside use cases. */
interface CouponRepository {
	public function isAvailable(): bool;

	/** @return object|null A WooCommerce coupon object, or null when unreadable. */
	public function findById( int $id );

	public function findIdByCode( string $code ): int;

	/** @return object New unsaved coupon object. */
	public function create();

	/** @param array<string, mixed> $args @return object Query result. */
	public function query( array $args );
}
