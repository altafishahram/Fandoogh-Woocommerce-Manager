<?php
/** Regression checks for constructor inputs and optional WooCommerce APIs. */

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	class WC_Customer {
		private int $id;
		public function __construct( int $id ) { $this->id = $id; }
		public function get_id(): int { return $this->id; }
	}
	class WC_Coupon {
		public string $code;
		public function __construct( string $code ) {
			if ( 'throw' === $code ) { throw new \RuntimeException( 'coupon-read-error' ); }
			$this->code = $code;
		}
	}
	function absint( $value ): int { return abs( (int) $value ); }
}

namespace Fandoogh_Manager {
	require_once __DIR__ . '/../app/orders.php';
}

namespace {
	$customer = \Fandoogh_Manager\orders_create_customer( array( 'customer_type' => 'registered', 'customer_id' => 7 ) );
	if ( array( 'id' => 7, 'created' => false ) !== $customer ) {
		throw new \RuntimeException( 'Registered order customers must not depend on the customer list data store.' );
	}
	foreach ( array( '00123', 'Mixed-Case' ) as $code ) {
		$coupon = \Fandoogh_Manager\compose_order_coupon( $code );
		if ( $coupon->code !== $code ) { throw new \RuntimeException( 'Order coupon constructor input changed.' ); }
	}
	$caught = null;
	try { \Fandoogh_Manager\compose_order_coupon( 'throw' ); } catch ( \RuntimeException $exception ) { $caught = $exception->getMessage(); }
	if ( 'coupon-read-error' !== $caught ) { throw new \RuntimeException( 'Order coupon read failures must propagate to the order handler.' ); }
	$order = new class {
		public function get_id(): int { throw new \RuntimeException( 'Notes should not read an order when their API is unavailable.' ); }
	};
	if ( array() !== \Fandoogh_Manager\serialize_order_notes( $order ) ) { throw new \RuntimeException( 'Missing order-note API must return no notes.' ); }

	$events = array();
	$storeCount = 0;
	$repository = new \Fandoogh_Manager\Infrastructure\WooCommerceCustomerRepository(
		static function ( ?int $id ): object { return new WC_Customer( $id ?? 0 ); },
		static function () use ( &$events, &$storeCount ): object {
			$id = ++$storeCount;
			$events[] = 'store:' . $id;
			return new class( $id, $events ) {
				private int $id;
				/** @var array<int, string> */
				private array $events;
				public function __construct( int $id, array &$events ) { $this->id = $id; $this->events =& $events; }
				/** @return array<int, int> */
				public function search_customers( string $term, int $limit ): array { $this->events[] = 'search:' . $this->id; return array( 7 ); }
				/** @param array<string, mixed> $args */
				public function query_customers( array $args ): object { $this->events[] = 'query:' . $this->id; return (object) array( 'customers' => array( 7 ) ); }
			};
		},
		static function ( array $args ): array { return array(); }
	);
	$repository->initializeQuery();
	$repository->searchIds( 'name' );
	$repository->query( array( 'include' => array( 7 ) ) );
	if ( array( 'store:1', 'store:2', 'search:2', 'query:1' ) !== $events ) {
		throw new \RuntimeException( 'Customer list/search construction order changed.' );
	}
	echo "Refactor compatibility: 6 regression checks passed.\n";
}
