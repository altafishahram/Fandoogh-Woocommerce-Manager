<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Parses only the public coupon request envelope; value validation stays separate. */
final class CouponRequestParser {
	/** @var callable(string, string, int): mixed */
	private $errorFactory;

	/** @param callable(string, string, int): mixed $errorFactory Existing error contract factory. */
	public function __construct( callable $errorFactory ) {
		$this->errorFactory = $errorFactory;
	}

	/**
	 * @param object $request WordPress REST request or a compatible test double.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( object $request ) {
		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( false === strpos( $content_type, 'application/json' ) ) {
			return ( $this->errorFactory )( 'fandoogh_coupon_json_required', __( 'بدنهٔ درخواست کوپن باید JSON باشد.', 'fandoogh-manager' ), 415 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return ( $this->errorFactory )( 'fandoogh_coupon_invalid_body', __( 'بدنهٔ درخواست کوپن معتبر نیست.', 'fandoogh-manager' ), 422 );
		}

		$allowed = array( 'code', 'description', 'discount_type', 'amount', 'date_expires', 'free_shipping', 'individual_use', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'usage_limit', 'usage_limit_per_user', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'status' );
		if ( ! empty( array_diff( array_keys( $body ), $allowed ) ) ) {
			return ( $this->errorFactory )( 'fandoogh_coupon_unknown_field', __( 'یکی از فیلدهای کوپن مجاز نیست.', 'fandoogh-manager' ), 422 );
		}

		return $body;
	}
}
