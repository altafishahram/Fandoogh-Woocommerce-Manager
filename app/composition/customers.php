<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/CustomerRepository.php';
require_once __DIR__ . '/../src/Infrastructure/WooCommerceCustomerRepository.php';

/** @return \Fandoogh_Manager\Catalog\CustomerRepository */
function compose_customer_repository(): \Fandoogh_Manager\Catalog\CustomerRepository {
	return new \Fandoogh_Manager\Infrastructure\WooCommerceCustomerRepository(
		static function ( ?int $id = null ): object {
			return null === $id ? new \WC_Customer() : new \WC_Customer( $id );
		},
		static function (): object { return new \WC_Customer_Data_Store(); },
		static function ( array $args ): array { return function_exists( 'get_users' ) ? (array) get_users( $args ) : array(); }
	);
}
