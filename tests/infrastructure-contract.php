<?php

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	require_once __DIR__ . '/../app/src/Catalog/SettingsStore.php';
	require_once __DIR__ . '/../app/src/Infrastructure/WordPressSettingsStore.php';
	require_once __DIR__ . '/../app/src/Catalog/SecurityStateStore.php';
	require_once __DIR__ . '/../app/src/Infrastructure/WordPressSecurityStateStore.php';
	require_once __DIR__ . '/../app/src/Catalog/MediaStorage.php';
	require_once __DIR__ . '/../app/src/Infrastructure/WordPressMediaStorage.php';
	$settingsWrites = array();
	$settings = new \Fandoogh_Manager\Infrastructure\WordPressSettingsStore(
		'option-key',
		static function ( $key, $default ) { return array( 'raw' => true, 'key' => $key ); },
		static function ( $key, $value ) use ( &$settingsWrites ) { $settingsWrites[] = array( $key, $value ); return true; },
		static function ( $value ) { return array( 'sanitized' => $value ); }
	);
	if ( $settings->read() !== array( 'sanitized' => array( 'raw' => true, 'key' => 'option-key' ) ) ) { throw new \RuntimeException( 'Settings read adapter changed.' ); }
	if ( ! $settings->write( array( 'input' => true ) ) || $settingsWrites !== array( array( 'option-key', array( 'sanitized' => array( 'input' => true ) ) ) ) ) { throw new \RuntimeException( 'Settings write adapter changed.' ); }
	$stateCalls = array();
	$state = new \Fandoogh_Manager\Infrastructure\WordPressSecurityStateStore(
		static function ( $key, $default = false ) use ( &$stateCalls ) { $stateCalls[] = array( 'get', $key, $default ); return $default; },
		static function ( $key, $value, $autoload = true ) use ( &$stateCalls ) { $stateCalls[] = array( 'update', $key, $value, $autoload ); },
		static function ( $key ) use ( &$stateCalls ) { $stateCalls[] = array( 'delete', $key ); },
		static function ( $key ) use ( &$stateCalls ) { $stateCalls[] = array( 'get-transient', $key ); return 3; },
		static function ( $key, $value, $expiration ) use ( &$stateCalls ) { $stateCalls[] = array( 'set-transient', $key, $value, $expiration ); }
	);
	if ( 3 !== $state->getTransient( 'rate' ) ) { throw new \RuntimeException( 'Transient read adapter changed.' ); }
	$state->updateOption( 'state', array( 'ok' => true ), false ); $state->deleteOption( 'state' ); $state->setTransient( 'rate', 4, 60 );
	if ( $stateCalls !== array( array( 'get-transient', 'rate' ), array( 'update', 'state', array( 'ok' => true ), false ), array( 'delete', 'state' ), array( 'set-transient', 'rate', 4, 60 ) ) ) { throw new \RuntimeException( 'Security state adapter changed.' ); }
	$mediaCalls = array();
	$media = new \Fandoogh_Manager\Infrastructure\WordPressMediaStorage(
		static function ( $file, $overrides ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'sideload', $file, $overrides ); return array( 'file' => 'out.webp' ); },
		static function ( $post, $file ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'attachment', $post, $file ); return 8; },
		static function ( $id, $file ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'metadata', $id, $file ); return array( 'width' => 10 ); },
		static function ( $id, $metadata ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'update-metadata', $id, $metadata ); },
		static function ( $file ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'delete-file', $file ); },
		static function ( $id, $force ) use ( &$mediaCalls ) { $mediaCalls[] = array( 'delete-attachment', $id, $force ); }
	);
	$media->handleSideload( array( 'name' => 'a.png' ), array( 'test_form' => false ) ); $media->createAttachment( array( 'post_status' => 'inherit' ), 'out.webp' ); $media->generateMetadata( 8, 'out.webp' ); $media->updateMetadata( 8, array( 'width' => 10 ) ); $media->deleteFile( 'out.webp' ); $media->deleteAttachment( 8, true );
	if ( count( $mediaCalls ) !== 6 || 'sideload' !== $mediaCalls[0][0] || 'delete-attachment' !== $mediaCalls[5][0] ) { throw new \RuntimeException( 'Media storage adapter changed.' ); }
	echo "Infrastructure adapters: 12 contract checks passed.\n";
}
