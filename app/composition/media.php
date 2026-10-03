<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/MediaStorage.php';
require_once __DIR__ . '/../src/Infrastructure/WordPressMediaStorage.php';

/** @return \Fandoogh_Manager\Catalog\MediaStorage */
function compose_media_storage(): \Fandoogh_Manager\Catalog\MediaStorage {
	return new \Fandoogh_Manager\Infrastructure\WordPressMediaStorage(
		static function ( array $file, array $overrides ) { return wp_handle_sideload( $file, $overrides ); },
		static function ( array $postData, string $filePath ) { return wp_insert_attachment( $postData, $filePath ); },
		static function ( int $attachmentId, string $filePath ) { return wp_generate_attachment_metadata( $attachmentId, $filePath ); },
		static function ( int $attachmentId, array $metadata ) { return wp_update_attachment_metadata( $attachmentId, $metadata ); },
		static function ( string $filePath ) { return wp_delete_file( $filePath ); },
		static function ( int $attachmentId, bool $force ) { return wp_delete_attachment( $attachmentId, $force ); }
	);
}
