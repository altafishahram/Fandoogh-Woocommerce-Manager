<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\MediaStorage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress media adapter; upload orchestration stays in the endpoint. */
final class WordPressMediaStorage implements MediaStorage {
	/** @var callable(array<string, mixed>, array<string, mixed>): mixed */
	private $sideload;
	/** @var callable(array<string, mixed>, string): mixed */
	private $attachmentCreator;
	/** @var callable(int, string): mixed */
	private $metadataGenerator;
	/** @var callable(int, array<string, mixed>): mixed */
	private $metadataUpdater;
	/** @var callable(string): mixed */
	private $fileDeleter;
	/** @var callable(int, bool): mixed */
	private $attachmentDeleter;

	public function __construct( callable $sideload, callable $attachmentCreator, callable $metadataGenerator, callable $metadataUpdater, callable $fileDeleter, callable $attachmentDeleter ) {
		$this->sideload = $sideload;
		$this->attachmentCreator = $attachmentCreator;
		$this->metadataGenerator = $metadataGenerator;
		$this->metadataUpdater = $metadataUpdater;
		$this->fileDeleter = $fileDeleter;
		$this->attachmentDeleter = $attachmentDeleter;
	}

	public function handleSideload( array $file, array $overrides ) { return ( $this->sideload )( $file, $overrides ); }
	public function createAttachment( array $postData, string $filePath ) { return ( $this->attachmentCreator )( $postData, $filePath ); }
	public function generateMetadata( int $attachmentId, string $filePath ) { return ( $this->metadataGenerator )( $attachmentId, $filePath ); }
	public function updateMetadata( int $attachmentId, array $metadata ) { ( $this->metadataUpdater )( $attachmentId, $metadata ); }
	public function deleteFile( string $filePath ): void { ( $this->fileDeleter )( $filePath ); }
	public function deleteAttachment( int $attachmentId, bool $force ): void { ( $this->attachmentDeleter )( $attachmentId, $force ); }
}
