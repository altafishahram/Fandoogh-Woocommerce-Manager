<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Builds the existing attribute payload without depending on WordPress APIs. */
final class ProductAttributeList {
	private AttributeRepository $repository;
	private AttributeTextSanitizer $sanitizer;

	public function __construct( AttributeRepository $repository, AttributeTextSanitizer $sanitizer ) {
		$this->repository = $repository;
		$this->sanitizer = $sanitizer;
	}

	public function isAvailable(): bool {
		return $this->repository->isAvailable();
	}

	/**
	 * @return array<int, array{id: int, label: string, slug: string, name: string, terms: array<int, array{id: int, name: string, slug: string}>}>
	 */
	public function items(): array {
		/** @var array<int, array{id: int, label: string, slug: string, name: string, terms: array<int, array{id: int, name: string, slug: string}>}> $items */
		$items = array();
		/** @var mixed $attribute */
		foreach ( $this->repository->all() as $attribute ) {
			/** @var string $slug */
			$slug = $this->sanitizer->slug( (string) ( isset( $attribute->attribute_name ) ? $attribute->attribute_name : '' ) );
			/** @var string $taxonomy */
			$taxonomy = $this->repository->taxonomyName( $slug );
			if ( '' === $slug || ! $this->repository->taxonomyExists( $taxonomy ) ) {
				continue;
			}

			/** @var array<int, array{id: int, name: string, slug: string}> $term_items */
			$term_items = array();
			/** @var mixed $term */
			foreach ( $this->repository->terms( $taxonomy ) as $term ) {
				$term_items[] = array(
					'id'   => $this->sanitizer->id( $term->term_id ),
					'name' => $this->sanitizer->text( $term->name ),
					'slug' => $this->sanitizer->slug( $term->slug ),
				);
			}

			$items[] = array(
				'id'    => $this->sanitizer->id( isset( $attribute->attribute_id ) ? $attribute->attribute_id : 0 ),
				'label' => $this->sanitizer->text( isset( $attribute->attribute_label ) ? $attribute->attribute_label : $slug ),
				'slug'  => $slug,
				'name'  => $taxonomy,
				'terms' => $term_items,
			);
		}

		return $items;
	}
}
