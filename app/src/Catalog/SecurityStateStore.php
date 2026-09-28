<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for security options and transient state. */
interface SecurityStateStore {
	/** @return mixed */
	public function getOption( string $key, $default = false );
	public function updateOption( string $key, $value, bool $autoload = true ): void;
	public function deleteOption( string $key ): void;
	/** @return mixed */
	public function getTransient( string $key );
	public function setTransient( string $key, $value, int $expiration ): void;
}
