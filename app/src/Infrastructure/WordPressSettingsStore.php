<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\SettingsStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress option adapter; sanitization remains an injected policy. */
final class WordPressSettingsStore implements SettingsStore {
	private string $optionKey;
	/** @var callable(string, mixed): mixed */
	private $reader;
	/** @var callable(string, mixed): mixed */
	private $writer;
	/** @var callable(mixed): array<string, mixed> */
	private $sanitizer;

	/**
	 * @param callable(string, mixed): mixed $reader
	 * @param callable(string, mixed): mixed $writer
	 * @param callable(mixed): array<string, mixed> $sanitizer
	 */
	public function __construct( string $optionKey, callable $reader, callable $writer, callable $sanitizer ) {
		$this->optionKey = $optionKey;
		$this->reader = $reader;
		$this->writer = $writer;
		$this->sanitizer = $sanitizer;
	}

	public function read(): array {
		return ( $this->sanitizer )( ( $this->reader )( $this->optionKey, array() ) );
	}

	public function write( array $settings ): bool {
		return (bool) ( $this->writer )( $this->optionKey, ( $this->sanitizer )( $settings ) );
	}
}
