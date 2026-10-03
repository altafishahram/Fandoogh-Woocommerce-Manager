<?php

namespace Fandoogh_Manager\Infrastructure;

use Fandoogh_Manager\Catalog\SecurityDatabase;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Adapter for the WordPress wpdb instance used by security storage. */
final class WordPressSecurityDatabase implements SecurityDatabase {
	/** @var object */
	private $wpdb;

	public function __construct( object $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function charsetCollate(): string { return (string) $this->wpdb->get_charset_collate(); }
	public function prefix(): string { return (string) $this->wpdb->prefix; }
	public function optionsTable(): string { return (string) $this->wpdb->options; }
	public function usersTable(): string { return (string) $this->wpdb->users; }

	public function insert( string $table, array $data, array $formats ) { return $this->wpdb->insert( $table, $data, $formats ); }
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ) { return $this->wpdb->update( $table, $data, $where, $formats, $whereFormats ); }
	public function query( string $query ) { return $this->wpdb->query( $query ); }
	public function getRow( string $query ) { return $this->wpdb->get_row( $query ); }
	public function getResults( string $query ) { return $this->wpdb->get_results( $query ); }
	public function getVar( string $query ) { return $this->wpdb->get_var( $query ); }
	public function getCol( string $query ) { return $this->wpdb->get_col( $query ); }

	public function prepare( string $query, ...$args ) {
		return $this->wpdb->prepare( $query, ...$args );
	}

	public function escLike( string $text ): string { return (string) $this->wpdb->esc_like( $text ); }
	public function insertId(): int { return absint( $this->wpdb->insert_id ); }
}
