<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\SecurityStateStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress option/transient adapter for security state. */
final class WordPressSecurityStateStore implements SecurityStateStore {
	/** @var callable(string, mixed): mixed */
	private $optionReader;
	/** @var callable(string, mixed, bool): mixed */
	private $optionWriter;
	/** @var callable(string): mixed */
	private $optionDeleter;
	/** @var callable(string): mixed */
	private $transientReader;
	/** @var callable(string, mixed, int): mixed */
	private $transientWriter;

	public function __construct( callable $optionReader, callable $optionWriter, callable $optionDeleter, callable $transientReader, callable $transientWriter ) {
		$this->optionReader = $optionReader;
		$this->optionWriter = $optionWriter;
		$this->optionDeleter = $optionDeleter;
		$this->transientReader = $transientReader;
		$this->transientWriter = $transientWriter;
	}

	public function getOption( string $key, $default = false ) { return ( $this->optionReader )( $key, $default ); }
	public function updateOption( string $key, $value, bool $autoload = true ): void { ( $this->optionWriter )( $key, $value, $autoload ); }
	public function deleteOption( string $key ): void { ( $this->optionDeleter )( $key ); }
	public function getTransient( string $key ) { return ( $this->transientReader )( $key ); }
	public function setTransient( string $key, $value, int $expiration ): void { ( $this->transientWriter )( $key, $value, $expiration ); }
}
