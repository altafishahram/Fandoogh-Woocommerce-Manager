<?php

namespace Fandoogh_Manager\Catalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Port for the plugin-owned security database operations. */
interface SecurityDatabase {
	public function charsetCollate(): string;
	public function prefix(): string;
	public function optionsTable(): string;
	public function usersTable(): string;

	/** @param array<string, mixed> $data @param array<int, string> $formats @return mixed */
	public function insert( string $table, array $data, array $formats );

	/** @param array<string, mixed> $data @param array<string, mixed> $where @param array<int, string> $formats @param array<int, string> $whereFormats @return mixed */
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats );

	/** @return mixed */
	public function query( string $query );

	/** @return mixed */
	public function getRow( string $query );

	/** @return mixed */
	public function getResults( string $query );

	/** @return mixed */
	public function getVar( string $query );

	/** @return mixed */
	public function getCol( string $query );

	/** @param mixed ...$args @return mixed */
	public function prepare( string $query, ...$args );

	public function escLike( string $text ): string;
	public function insertId(): int;
}
