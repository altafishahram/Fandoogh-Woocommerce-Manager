<?php
/**
 * Conservative source inventory, not a substitute for behavioral tests.
 * Capture before refactoring: php tools/refactor-contracts.php --capture
 * Verify later: php tools/refactor-contracts.php
 * The reviewed fixture must never be refreshed just to make a failure pass.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

/** @return array<string, array<int, string>> */
function refactor_inventory(): array {
	$root = dirname( __DIR__ );
	$files = array( $root . '/fandoogh-manager.php' );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );
	$inventory = array( 'functions' => array(), 'hooks' => array(), 'storage' => array(), 'constants' => array(), 'literals' => array() );
	$hooks = array( 'add_action', 'add_filter', 'remove_action', 'remove_filter', 'register_activation_hook', 'register_deactivation_hook', 'register_rest_route', 'register_setting', 'add_shortcode', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_clear_scheduled_hook', 'wp_unschedule_hook' );
	$storage = array( 'get_option', 'add_option', 'update_option', 'delete_option', 'get_transient', 'set_transient', 'delete_transient', 'get_user_meta', 'update_user_meta', 'delete_user_meta', 'get_meta', 'update_meta_data', 'delete_meta_data', 'wp_remote_get', 'wp_remote_post', 'wp_remote_request' );
	foreach ( $files as $file ) {
		$tokens = array_values( array_filter( token_get_all( file_get_contents( $file ) ), static function ( $token ): bool {
			return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		} ) );
		$count = count( $tokens );
		for ( $index = 0; $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$inventory['literals'][] = $token[1];
			}
			// Existing procedural entry points retain their exact signatures.
			if ( T_FUNCTION === $token[0] && isset( $tokens[ $index + 1 ][0] ) && T_STRING === $tokens[ $index + 1 ][0] && false === strpos( str_replace( '\\', '/', $file ), '/app/src/' ) ) {
				$signature = '';
				for ( $next = $index; $next < $count && '{' !== $tokens[ $next ] && ';' !== $tokens[ $next ]; ++$next ) {
					$signature .= is_array( $tokens[ $next ] ) ? $tokens[ $next ][1] : $tokens[ $next ];
				}
				$inventory['functions'][] = $signature;
			}
			if ( T_CONST === $token[0] ) {
				$definition = '';
				for ( $next = $index; $next < $count && ';' !== $tokens[ $next ]; ++$next ) {
					$definition .= is_array( $tokens[ $next ] ) ? $tokens[ $next ][1] : $tokens[ $next ];
				}
				$inventory['constants'][] = $definition;
			}
			if ( T_STRING !== $token[0] || '(' !== ( $tokens[ $index + 1 ] ?? null ) ) {
				continue;
			}
			$category = in_array( $token[1], $hooks, true ) ? 'hooks' : ( in_array( $token[1], $storage, true ) ? 'storage' : '' );
			if ( '' === $category ) {
				continue;
			}
			$call = $token[1];
			$depth = 0;
			for ( $next = $index + 1; $next < $count; ++$next ) {
				$part = $tokens[ $next ];
				$call .= is_array( $part ) ? $part[1] : $part;
				if ( '(' === $part ) { ++$depth; }
				if ( ')' === $part && 0 === --$depth ) { break; }
			}
			$inventory[ $category ][] = $call;
		}
	}
	foreach ( $inventory as $category => $values ) {
		// Literal membership allows moving/deduplicating strings between layers.
		if ( 'literals' === $category ) { $values = array_values( array_unique( $values ) ); }
		sort( $values );
		$inventory[ $category ] = $values;
	}
	return $inventory;
}

$fixture = __DIR__ . '/../tests/fixtures/refactor/contracts.json';
$actual = refactor_inventory();
if ( '--capture' === ( $argv[1] ?? '' ) ) {
	if ( file_exists( $fixture ) ) {
		fwrite( STDERR, "Refusing to overwrite the reviewed baseline.\n" );
		exit( 1 );
	}
	file_put_contents( $fixture, json_encode( $actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
	echo "Captured current working-tree contracts.\n";
	exit;
}
$expected = json_decode( file_get_contents( $fixture ), true, 512, JSON_THROW_ON_ERROR );
$failed = false;
foreach ( $expected as $category => $values ) {
	$remaining = $actual[ $category ];
	if ( 'storage' === $category ) {
		// Storage calls may move behind an adapter. Preserve the operation and
		// validate option/transient keys through the literal baseline separately.
		$expected_operations = array_values( array_unique( array_map( static function ( string $call ): string { return (string) strtok( $call, '(' ); }, $values ) ) );
		$actual_operations = array_values( array_unique( array_map( static function ( string $call ): string { return (string) strtok( $call, '(' ); }, $actual[ $category ] ) ) );
		sort( $expected_operations );
		sort( $actual_operations );
		if ( $expected_operations !== $actual_operations ) {
			fwrite( STDERR, 'Changed storage operations: expected ' . json_encode( $expected_operations ) . ', got ' . json_encode( $actual_operations ) . PHP_EOL );
			$failed = true;
		}
		echo $category . ': checked ' . count( $values ) . ' baseline entries.' . PHP_EOL;
		continue;
	}
	foreach ( $values as $value ) {
		$position = array_search( $value, $remaining, true );
		if ( false === $position ) {
			fwrite( STDERR, 'Changed/missing ' . $category . ': ' . $value . PHP_EOL );
			$failed = true;
			continue;
		}
		unset( $remaining[ $position ] );
	}
	// New hook registrations/storage calls also need explicit review.
	if ( in_array( $category, array( 'hooks', 'storage' ), true ) && count( $remaining ) ) {
		fwrite( STDERR, 'Unexpected ' . $category . ': ' . implode( "\n", $remaining ) . PHP_EOL );
		$failed = true;
	}
	echo $category . ': checked ' . count( $values ) . ' baseline entries.' . PHP_EOL;
}
exit( $failed ? 1 : 0 );
