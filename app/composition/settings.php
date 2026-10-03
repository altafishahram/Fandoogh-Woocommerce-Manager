<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/SettingsStore.php';
require_once __DIR__ . '/../src/Infrastructure/WordPressSettingsStore.php';

/** @return \Fandoogh_Manager\Catalog\SettingsStore */
function compose_settings_store(): \Fandoogh_Manager\Catalog\SettingsStore {
	return new \Fandoogh_Manager\Infrastructure\WordPressSettingsStore(
		OPTION_KEY,
		static function ( string $key, $default ) { return get_option( $key, $default ); },
		static function ( string $key, $value ) { return update_option( $key, $value ); },
		static function ( $value ): array { return sanitize_settings( $value ); }
	);
}
