<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\AttributeTextSanitizer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keep the exact WordPress sanitizers and their existing filter behavior. */
final class WordPressAttributeTextSanitizer implements AttributeTextSanitizer {
	/** @param mixed $value Raw slug. */
	public function slug( $value ): string {
		return \sanitize_title( $value );
	}

	/** @param mixed $value Raw display label. */
	public function text( $value ): string {
		return \sanitize_text_field( $value );
	}

	/** @param mixed $value Raw identifier. */
	public function id( $value ): int {
		return \absint( $value );
	}
}
