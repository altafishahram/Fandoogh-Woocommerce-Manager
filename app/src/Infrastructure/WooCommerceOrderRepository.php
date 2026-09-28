<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Adapter around WooCommerce order functions and object factories. */
final class WooCommerceOrderRepository implements OrderRepository {
	/** @var callable(array<string, mixed>): mixed */
	private $queryOrders;
	/** @var callable(int): mixed */
	private $findOrder;
	/** @var callable(string): mixed */
	private $searchOrders;
	/** @var callable(): array<string, string> */
	private $statusReader;
	/** @var callable(array<string, mixed>): mixed */
	private $queryNotesCallback;
	/** @var callable(array<string, mixed>): mixed */
	private $orderCreator;
	/** @var callable(): object */
	private $shippingItemFactory;
	/** @var callable(array<string, mixed>): mixed */
	private $refundCreator;

	/**
	 * @param callable(array<string, mixed>): mixed $queryOrders Order query.
	 * @param callable(int): mixed $findOrder Order lookup.
	 * @param callable(string): mixed $searchOrders Order search helper.
	 * @param callable(): array<string, string> $statusReader Status reader.
	 * @param callable(array<string, mixed>): mixed $queryNotesCallback Note query.
	 * @param callable(array<string, mixed>): mixed $orderCreator Order factory.
	 * @param callable(): object $shippingItemFactory Shipping line factory.
	 * @param callable(array<string, mixed>): mixed $refundCreator Refund factory.
	 */
	public function __construct( callable $queryOrders, callable $findOrder, callable $searchOrders, callable $statusReader, callable $queryNotesCallback, callable $orderCreator, callable $shippingItemFactory, callable $refundCreator ) {
		$this->queryOrders = $queryOrders;
		$this->findOrder = $findOrder;
		$this->searchOrders = $searchOrders;
		$this->statusReader = $statusReader;
		$this->queryNotesCallback = $queryNotesCallback;
		$this->orderCreator = $orderCreator;
		$this->shippingItemFactory = $shippingItemFactory;
		$this->refundCreator = $refundCreator;
	}

	public function isAvailable(): bool {
		return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order' ) && function_exists( 'wc_get_order_statuses' ) && class_exists( '\\WC_Order' );
	}

	public function isCreateAvailable(): bool {
		return $this->isAvailable() && function_exists( 'wc_create_order' );
	}

	public function isRefundAvailable(): bool {
		return $this->isAvailable() && function_exists( 'wc_create_refund' );
	}

	public function query( array $args ) {
		return ( $this->queryOrders )( $args );
	}

	public function findById( int $id ) {
		if ( $id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		return ( $this->findOrder )( $id );
	}

	public function search( string $term ) {
		if ( ! function_exists( 'wc_order_search' ) ) {
			return array();
		}

		return ( $this->searchOrders )( $term );
	}

	public function statuses(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		return (array) ( $this->statusReader )();
	}

	public function queryNotes( array $args ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return array();
		}

		return ( $this->queryNotesCallback )( $args );
	}

	public function create( array $args ) {
		return ( $this->orderCreator )( $args );
	}

	public function createShippingItem() {
		return ( $this->shippingItemFactory )();
	}

	public function createRefund( array $args ) {
		return ( $this->refundCreator )( $args );
	}
}
