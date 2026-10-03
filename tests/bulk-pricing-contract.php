<?php
/** Signed decimal calculations used by the bulk-price workflow. */

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
}

namespace Fandoogh_Manager {
	function add_action() {}
	function absint( $value ) { return abs( (int) $value ); }
	function wc_format_decimal( $value, $decimals ) { return number_format( (float) $value, $decimals, '.', '' ); }
	require_once __DIR__ . '/../app/bulk-pricing.php';
}

namespace {
	$cases = array(
		array( '100', 'percent', '10', '110' ),
		array( '100', 'percent', '-10', '90' ),
		array( '50', 'fixed', '-75', '0' ),
		array( '50', 'fixed', '25', '75' ),
		array( '50.50', 'fixed', '0.5', '51' ),
	);
	foreach ( $cases as $case ) {
		$result = \Fandoogh_Manager\bulk_price_calculate( $case[0], $case[1], $case[2] );
		if ( (string) $result !== $case[3] ) {
			throw new \RuntimeException( 'Bulk-price calculation failed: ' . json_encode( array( $case, $result ) ) );
		}
	}
	echo "Bulk pricing: 5 signed calculation checks passed.\n";
}
