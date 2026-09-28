<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/SecurityStateStore.php';
require_once __DIR__ . '/../src/Infrastructure/WordPressSecurityStateStore.php';
require_once __DIR__ . '/../src/Catalog/SecurityDatabase.php';
require_once __DIR__ . '/../src/Infrastructure/WordPressSecurityDatabase.php';

/** @return \Fandoogh_Manager\Catalog\SecurityStateStore */
function compose_security_state_store(): \Fandoogh_Manager\Catalog\SecurityStateStore {
	return new \Fandoogh_Manager\Infrastructure\WordPressSecurityStateStore(
		static function ( string $key, $default = false ) { return get_option( $key, $default ); },
		static function ( string $key, $value, bool $autoload = true ) { update_option( $key, $value, $autoload ); },
		static function ( string $key ) { delete_option( $key ); },
		static function ( string $key ) { return get_transient( $key ); },
		static function ( string $key, $value, int $expiration ) { set_transient( $key, $value, $expiration ); }
	);
}

/** @return \Fandoogh_Manager\Catalog\SecurityDatabase */
function compose_security_database(): \Fandoogh_Manager\Catalog\SecurityDatabase {
	global $wpdb;
	return new \Fandoogh_Manager\Infrastructure\WordPressSecurityDatabase( $wpdb );
}
