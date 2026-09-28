<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for the WordPress file and attachment lifecycle. */
interface MediaStorage {
	/** @param array<string, mixed> $file @param array<string, mixed> $overrides @return mixed */
	public function handleSideload( array $file, array $overrides );

	/** @param array<string, mixed> $postData @return mixed */
	public function createAttachment( array $postData, string $filePath );

	/** @return mixed */
	public function generateMetadata( int $attachmentId, string $filePath );

	/** @param array<string, mixed> $metadata */
	public function updateMetadata( int $attachmentId, array $metadata );

	public function deleteFile( string $filePath ): void;

	public function deleteAttachment( int $attachmentId, bool $force ): void;
}
