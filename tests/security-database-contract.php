<?php

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	class Security_WPDB_Double {
		public $prefix = 'wp_';
		public $options = 'wp_options';
		public $users = 'wp_users';
		public $insert_id = 17;
		public $calls = array();
		public function get_charset_collate() { return 'COLLATE utf8mb4'; }
		public function insert( $table, $data, $formats ) { $this->calls[] = array( 'insert', $table, $data, $formats ); return 1; }
		public function update( $table, $data, $where, $formats, $where_formats ) { $this->calls[] = array( 'update', $table, $data, $where, $formats, $where_formats ); return 1; }
		public function query( $query ) { $this->calls[] = array( 'query', $query ); return 1; }
		public function get_row( $query ) { $this->calls[] = array( 'row', $query ); return (object) array(); }
		public function get_results( $query ) { $this->calls[] = array( 'results', $query ); return array(); }
		public function get_var( $query ) { $this->calls[] = array( 'var', $query ); return 0; }
		public function get_col( $query ) { $this->calls[] = array( 'col', $query ); return array(); }
		public function prepare( $query ) { $this->calls[] = array( 'prepare', func_get_args() ); return 'prepared query'; }
		public function esc_like( $text ) { return 'escaped-' . $text; }
	}
	function absint( $value ) { return abs( (int) $value ); }
	$GLOBALS['wpdb'] = new Security_WPDB_Double();
}

namespace Fandoogh_Manager {
	require_once __DIR__ . '/../app/composition/security.php';
}

namespace {
	$database = \Fandoogh_Manager\compose_security_database();
	if ( 'COLLATE utf8mb4' !== $database->charsetCollate() || 'wp_' !== $database->prefix() || 'wp_options' !== $database->optionsTable() || 'wp_users' !== $database->usersTable() ) {
		throw new \RuntimeException( 'Security database table metadata changed.' );
	}
	if ( 1 !== $database->insert( 'table', array( 'id' => 1 ), array( '%d' ) ) ) { throw new \RuntimeException( 'Insert delegation changed.' ); }
	if ( 1 !== $database->update( 'table', array( 'status' => 'active' ), array( 'id' => 1 ), array( '%s' ), array( '%d' ) ) ) { throw new \RuntimeException( 'Update delegation changed.' ); }
	if ( 'prepared query' !== $database->prepare( 'SELECT %s', 'value' ) ) { throw new \RuntimeException( 'Prepare delegation changed.' ); }
	if ( 'escaped-prefix' !== $database->escLike( 'prefix' ) || 17 !== $database->insertId() ) { throw new \RuntimeException( 'wpdb helper delegation changed.' ); }
	foreach ( array( 'query', 'row', 'results', 'var', 'col' ) as $operation ) {
		$method = array( 'query' => 'query', 'row' => 'getRow', 'results' => 'getResults', 'var' => 'getVar', 'col' => 'getCol' )[ $operation ];
		$database->{$method}( 'SELECT 1' );
	}
	foreach ( array( array(), array( null, 0, false, '' ), array( array( 'value', 7 ) ) ) as $arguments ) {
		$database->prepare( 'SELECT %s', ...$arguments );
		$call = end( $GLOBALS['wpdb']->calls );
		if ( array( 'prepare', array_merge( array( 'SELECT %s' ), $arguments ) ) !== $call ) {
			throw new \RuntimeException( 'Prepare must preserve nulls, empty values and array arguments.' );
		}
	}
	echo "Security database: 10 contract checks passed.\n";
}
