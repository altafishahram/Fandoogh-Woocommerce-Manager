<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for the single public settings option. */
interface SettingsStore {
	/** @return array<string, mixed> Sanitized settings. */
	public function read(): array;

	/** @param array<string, mixed> $settings @return bool */
	public function write( array $settings ): bool;
}
