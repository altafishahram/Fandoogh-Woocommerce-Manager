<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Preserve the host's text rules, including WordPress sanitization filters. */
interface AttributeTextSanitizer {
	/**
	 * @param mixed $value Raw term slug; no coercion before WordPress receives it.
	 * @return string
	 */
	public function slug( $value ): string;

	/**
	 * @param mixed $value Legacy datastore labels may contain non-string values.
	 * @return string
	 */
	public function text( $value ): string;

	/** @param mixed $value Raw datastore identifier. */
	public function id( $value ): int;
}
