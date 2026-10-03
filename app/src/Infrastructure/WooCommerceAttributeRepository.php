<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\AttributeRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress/WooCommerce reads; no payload formatting or dependency creation. */
final class WooCommerceAttributeRepository implements AttributeRepository {
	public function isAvailable(): bool {
		return function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' );
	}

	/** @return array<array-key, mixed> */
	public function all(): array {
		return (array) \wc_get_attribute_taxonomies();
	}

	public function taxonomyName( string $slug ): string {
		return \wc_attribute_taxonomy_name( $slug );
	}

	public function taxonomyExists( string $taxonomy ): bool {
		return \taxonomy_exists( $taxonomy );
	}

	/** @return array<array-key, mixed> */
	public function terms( string $taxonomy ): array {
		/** @var array<array-key, mixed>|\WP_Error|mixed $terms */
		$terms = \get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		if ( \is_wp_error( $terms ) ) {
			return array();
		}

		return (array) $terms;
	}
}
