<?php
/** CLI-only WordPress doubles for the real PHP shell/asset handlers; no database. */
namespace {
    if ( 'cli' !== PHP_SAPI ) { exit; }
    define( 'ABSPATH', __DIR__ . '/' );
    $asset_test_base = $argv[2] ?? 'https://example.test';
    $asset_test_headers = array();
    $asset_test_status = 200;
    $asset_test_vars = array();
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
    function register_activation_hook( ...$args ) {}
    function register_deactivation_hook( ...$args ) {}
    function add_action( ...$args ) {}
    function add_filter( ...$args ) {}
    function is_admin() { return false; }
    function home_url( $path = '' ) { return rtrim( $GLOBALS['asset_test_base'], '/' ) . '/' . ltrim( $path, '/' ); }
    function rest_url( $path ) { return home_url( 'wp-json/' . $path ); }
    function plugins_url( $path, $file ) { return home_url( 'wp-content/plugins/fandoogh-manager/' . $path ); }
    function trailingslashit( $text ) { return rtrim( $text, '/' ) . '/'; }
    function untrailingslashit( $text ) { return rtrim( $text, '/' ); }
    function absint( $value ) { return abs( (int) $value ); }
    function wp_parse_url( $url ) { return parse_url( $url ); }
    function wp_unslash( $text ) { return stripslashes( $text ); }
    function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
    function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
    function sanitize_file_name( $text ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $text ); }
    function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
    function esc_url( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
    function esc_url_raw( $text, $protocols = array() ) { return $text; }
    function __( $text, $domain = '' ) { return $text; }
    function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
    function get_query_var( $key ) { return $GLOBALS['asset_test_vars'][$key] ?? ''; }
    function get_option( $key, $default = false ) { return $GLOBALS['fixture_options'][$key] ?? $default; }
    function get_theme_mod( $key ) { return 0; }
    function get_bloginfo( $key ) { return 'Test Store'; }
    function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
    function status_header( $status ) { $GLOBALS['asset_test_status'] = $status; }
    function nocache_headers() { $GLOBALS['asset_test_headers']['cache-control'] = 'no-cache, must-revalidate'; }
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    function add_query_arg( $key, $value, $url = null ) {
        $args = is_array( $key ) ? $key : array( $key => $value );
        $url = is_array( $key ) ? $value : $url;
        $parts = explode( '?', $url, 2 );
        parse_str( $parts[1] ?? '', $query );
        return $parts[0] . '?' . http_build_query( array_merge( $query, $args ), '', '&', PHP_QUERY_RFC3986 );
    }
}
namespace Fandoogh_Manager {
    // Capture the actual handler's headers without a real web server.
    function header( $line, $replace = true ) {
        $parts = explode( ':', $line, 2 );
        $GLOBALS['asset_test_headers'][strtolower( $parts[0] )] = trim( $parts[1] ?? '' );
    }
}
namespace {
    require __DIR__ . '/../fandoogh-manager.php';
    $mode = $argv[1] ?? 'urls';
    if ( 'library' === $mode ) { return; }
    if ( 'urls' === $mode ) {
        echo json_encode( \Fandoogh_Manager\app_asset_urls() );
        exit;
    }
    if ( ! in_array( $mode, array( 'shell', 'asset' ), true ) ) { exit( 1 ); }
    $_SERVER['REQUEST_METHOD'] = $argv[4] ?? 'GET';
    ob_start();
    register_shutdown_function( function () {
        $body = ob_get_clean();
        echo json_encode( array( 'status' => $GLOBALS['asset_test_status'], 'headers' => $GLOBALS['asset_test_headers'], 'body' => $body ) );
    } );
    if ( 'shell' === $mode ) {
        $asset_test_vars[\Fandoogh_Manager\APP_QUERY_VAR] = 1;
        \Fandoogh_Manager\maybe_render_app_shell();
    } else {
        $asset_test_vars[\Fandoogh_Manager\APP_ASSET_QUERY_VAR] = $argv[3] ?? 'styles.css';
        \Fandoogh_Manager\maybe_serve_app_asset();
    }
}
