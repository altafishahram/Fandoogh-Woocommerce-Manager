<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only port for the existing global-attribute datastore. */
interface AttributeRepository {
	public function isAvailable(): bool;

	/** @return array<array-key, mixed> Legacy datastore rows, in source order. */
	public function all(): array;

	public function taxonomyName( string $slug ): string;

	public function taxonomyExists( string $taxonomy ): bool;

	/** @return array<array-key, mixed> Term rows; legacy read errors yield no terms. */
	public function terms( string $taxonomy ): array;
}
