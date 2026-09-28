<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\CustomerRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Adapter for WooCommerce customer CRUD and data-store queries. */
final class WooCommerceCustomerRepository implements CustomerRepository {
	/** @var callable(?int): object */
	private $customerFactory;
	/** @var callable(): object */
	private $dataStoreFactory;
	private ?object $queryStore = null;
	/** @var callable(array<string, mixed>): array<int, mixed> */
	private $userQuery;

	/**
	 * @param callable(?int): object $customerFactory Customer object factory.
	 * @param callable(): object $dataStoreFactory Data-store factory.
	 * @param callable(array<string, mixed>): array<int, mixed> $userQuery Public user query adapter.
	 */
	public function __construct( callable $customerFactory, callable $dataStoreFactory, callable $userQuery ) {
		$this->customerFactory = $customerFactory;
		$this->dataStoreFactory = $dataStoreFactory;
		$this->userQuery = $userQuery;
	}

	public function isAvailable(): bool {
		return class_exists( '\\WC_Customer' ) && class_exists( '\\WC_Customer_Data_Store' );
	}

	public function initializeQuery(): void {
		$this->queryStore = ( $this->dataStoreFactory )();
	}

	public function query( array $args ) {
		$store = $this->queryStore ?? ( $this->dataStoreFactory )();
		return $store->query_customers( $args );
	}

	public function searchIds( string $term ): array {
		$ids = array();
		if ( '' === $term ) {
			return $ids;
		}

		try {
			$store = ( $this->dataStoreFactory )();
			if ( method_exists( $store, 'search_customers' ) ) {
				$found = $store->search_customers( $term, 200 );
				foreach ( (array) $found as $customer_id ) {
					$customer_id = absint( $customer_id );
					if ( $customer_id > 0 ) {
						$ids[ $customer_id ] = $customer_id;
					}
				}
			}
		} catch ( \Throwable $exception ) {
			// The WordPress user query below can still resolve the search.
		}

		if ( empty( $ids ) ) {
			$user_query = array(
				'role'           => 'customer',
				'number'         => 200,
				'fields'         => 'ID',
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			);
			foreach ( (array) ( $this->userQuery )( $user_query ) as $customer_id ) {
				$customer_id = absint( $customer_id );
				if ( $customer_id > 0 ) {
					$ids[ $customer_id ] = $customer_id;
				}
			}

			$meta_query = array(
				'relation' => 'OR',
				array( 'key' => 'first_name', 'value' => $term, 'compare' => 'LIKE' ),
				array( 'key' => 'last_name', 'value' => $term, 'compare' => 'LIKE' ),
			);
			$meta_user_query = array(
				'role'       => 'customer',
				'number'     => 200,
				'fields'     => 'ID',
				'meta_query' => $meta_query,
			);
			foreach ( (array) ( $this->userQuery )( $meta_user_query ) as $customer_id ) {
				$customer_id = absint( $customer_id );
				if ( $customer_id > 0 ) {
					$ids[ $customer_id ] = $customer_id;
				}
			}
		}

		return array_values( $ids );
	}

	public function findById( int $id ) {
		if ( $id < 1 ) {
			return false;
		}

		try {
			return ( $this->customerFactory )( $id );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	public function create() {
		return ( $this->customerFactory )( null );
	}

	public function normalizeQueryEntry( $value ) {
		if ( $value instanceof \WC_Customer ) {
			return $value;
		}

		$customer_id = is_object( $value ) && method_exists( $value, 'get_id' ) ? absint( $value->get_id() ) : absint( $value );
		$customer = $this->findById( $customer_id );

		return $customer instanceof \WC_Customer ? $customer : false;
	}
}
