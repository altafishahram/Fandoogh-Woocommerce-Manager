<?php
/** CLI-only contract and actual admin HTML fixture; not a live WordPress test. */
if ( 'cli' !== PHP_SAPI ) { exit; }
$admin_test_mode = $argv[1] ?? 'test';
$admin_test_tab = $argv[2] ?? 'overview';
$argv[1] = 'library';
$argv[2] = 'https://example.test';
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_js( $text ) { return addslashes( $text ); }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $text ) ); }
function sanitize_email( $text ) { return filter_var( $text, FILTER_SANITIZE_EMAIL ); }
function sanitize_hex_color( $text ) { return preg_match( '/^#[a-f0-9]{6}$/i', $text ) ? $text : null; }
function sanitize_title( $text ) { return sanitize_key( $text ); }
function sanitize_user( $text, $strict = false ) { return sanitize_key( $text ); }
function current_user_can( $cap ) { return $GLOBALS['fixture_authorized'] ?? true; }
function get_current_user_id() { return 1; }
function get_users( $args = array() ) { return array(); }
function get_user_by( ...$args ) { return false; }
function is_wp_error( $value ) { return false; }
function is_ssl() { return $GLOBALS['fixture_ssl'] ?? true; }
function wp_enqueue_style( ...$args ) { $GLOBALS['fixture_styles'][] = $args; }
function wp_enqueue_script( ...$args ) { $GLOBALS['fixture_scripts'][] = $args; }
function wp_add_inline_style( ...$args ) {}
function admin_url( $path ) { return home_url( 'wp-admin/' . $path ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function __return_false() { return false; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['fixture_cleared_hooks'][] = $hook; }
function wp_unschedule_hook( $hook ) { $GLOBALS['fixture_unscheduled_hooks'][] = $hook; }
function flush_rewrite_rules() {}
function checked( $value, $expected = true, $echo = true ) { $out = (string) $value === (string) $expected ? 'checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function register_setting( $group, $key, $args ) { $GLOBALS['fixture_registered_setting'] = $args; }
function add_settings_error( ...$args ) { $GLOBALS['fixture_errors'][] = $args; }
function settings_errors( ...$args ) {}
function add_settings_section( $id, $title, $callback, $page ) { $GLOBALS['wp_settings_sections'][$page][$id] = compact( 'id', 'title', 'callback' ); }
function add_settings_field( $id, $title, $callback, $page, $section, $args = array() ) { $GLOBALS['wp_settings_fields'][$page][$section][$id] = compact( 'title', 'callback', 'args' ); }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce">'; }
function settings_fields( $group ) { wp_nonce_field( $group ); echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '"><input type="hidden" name="action" value="update">'; }
function do_settings_fields( $page, $section ) {
    foreach ( $GLOBALS['wp_settings_fields'][$page][$section] ?? array() as $field ) {
        echo '<tr><th scope="row">' . esc_html( $field['title'] ) . '</th><td>';
        call_user_func( $field['callback'], $field['args'] );
        echo '</td></tr>';
    }
}
function submit_button( $text = 'Save', $class = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit" class="button button-' . esc_attr( $class ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
require __DIR__ . '/app-assets-fixture.php';
$wpdb = new class {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public function get_results( $sql ) {
        $GLOBALS['fixture_session_reads'] = ( $GLOBALS['fixture_session_reads'] ?? 0 ) + 1;
        if ( isset( $GLOBALS['fixture_session_rows'] ) ) { return $GLOBALS['fixture_session_rows']; }
        return array( (object) array(
            'id' => 7, 'user_id' => 3, 'user_login' => 'manager-test', 'display_name' => 'مدیر آزمایشی',
            'device_label' => 'مرورگر آزمایشی با نام بلند برای بررسی جدول دستگاه‌ها', 'status' => 'active',
            'created_at' => gmdate( 'Y-m-d H:i:s' ), 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'revoked_at' => null,
        ) );
    }
};
$_SERVER['REQUEST_METHOD'] = 'GET';
\Fandoogh_Manager\register_settings();
\Fandoogh_Manager\order_tracking_register_settings();
function admin_test_render( $tab ) {
    $_GET['tab'] = $tab;
    ob_start();
    \Fandoogh_Manager\render_admin_page();
    return ob_get_clean();
}
if ( 'html' === $admin_test_mode ) { echo admin_test_render( $admin_test_tab ); exit; }
$checks = 0;
function admin_assert( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    $GLOBALS['checks']++;
}
$stored = \Fandoogh_Manager\default_settings();
$stored['analytics_enabled'] = true;
$stored['slug'] = 'store-desk';
$stored['image_quality'] = 91;
$stored['session_alert_email'] = 'security@example.test';
$GLOBALS['fixture_options'][\Fandoogh_Manager\OPTION_KEY] = $stored;
$baseline = \Fandoogh_Manager\get_settings();
$sanitize = $GLOBALS['fixture_registered_setting']['sanitize_callback'];
foreach ( \Fandoogh_Manager\admin_tabs() as $tab => $definition ) {
    $html = admin_test_render( $tab );
    admin_assert( substr_count( $html, 'aria-current="page"' ) === 1, "$tab: one selected tab" );
    admin_assert( strpos( $html, 'data-admin-tab="' . $tab . '"' ) !== false, "$tab: selected content" );
    admin_assert( substr_count( $html, '<form ' ) === substr_count( $html, '</form>' ), "$tab: balanced forms" );
    if ( empty( $definition['keys'] ) ) { continue; }
    admin_assert( strpos( $html, 'name="_wpnonce"' ) !== false, "$tab: settings nonce" );
    $input = array( '_tab' => $tab );
    foreach ( $definition['keys'] as $key ) { $input[$key] = $baseline[$key]; }
    $result = call_user_func( $sanitize, $input );
    admin_assert( $baseline === $result, "$tab: preserves all settings" );
    admin_assert( $result === call_user_func( $sanitize, $result ), "$tab: sanitizer safe to run twice" );
}
$result = call_user_func( $sanitize, array( '_tab' => 'analytics', 'slug' => 'injected' ) );
admin_assert( false === $result['analytics_enabled'], 'Unchecked checkbox disables reports' );
unset( $result['analytics_enabled'] );
$unrelated = $baseline;
unset( $unrelated['analytics_enabled'] );
admin_assert( $result === $unrelated, 'Unrelated/injected keys cannot alter other tabs' );
$result = call_user_func( $sanitize, array( '_tab' => 'sessions' ) );
admin_assert( false === $result['session_alerts_enabled'] && true === $result['analytics_enabled'], 'Session checkbox does not reset reports' );
foreach ( array( 'invalid', array( 'appearance' ), 'overview' ) as $invalid ) {
    admin_assert( $baseline === call_user_func( $sanitize, array( '_tab' => $invalid, 'slug' => 'oops' ) ), 'Invalid tab fails without mutation' );
}
admin_assert( strpos( admin_test_render( '<script>' ), 'data-admin-tab="overview"' ) !== false, 'Unknown URL falls back safely' );
admin_assert( strpos( admin_test_render( 'appearance' ), '[analytics_enabled]' ) === false, 'Appearance excludes report setting' );
admin_assert( strpos( admin_test_render( 'tracking' ), '[order_tracking]' ) !== false, 'Tracking fields included' );
$GLOBALS['fixture_session_reads'] = 0;
$overview = admin_test_render( 'overview' );
admin_assert( 1 === $GLOBALS['fixture_session_reads'], 'Overview counters share one session read' );
admin_assert( strpos( $overview, '1 دستگاه · مشاهده' ) !== false, 'Device counter comes from active sessions' );
admin_assert( substr_count( $overview, 'class="fandoogh-guide-panel"' ) === 3 && ! preg_match( '/<div class="fandoogh-guide-panel"[^>]*\shidden/', $overview ), 'All guide panels are readable before JS enhancement' );
admin_assert( strpos( $overview, 'غیرفعال؛ حالت محدود' ) !== false, 'No WooCommerce means a limited status rather than a success' );
admin_assert( strpos( $overview, '<details class="fandoogh-admin-card fandoogh-admin-technical"' ) !== false, 'Technical details use native disclosure' );
admin_assert( strpos( $overview, '۵۰۰ نشست اخیر' ) !== false, 'Bounded session count is disclosed' );
$GLOBALS['fixture_ssl'] = false;
$GLOBALS['fixture_session_rows'] = array();
$empty_overview = admin_test_render( 'overview' );
admin_assert( strpos( $empty_overview, 'HTTPS لازم است' ) !== false && strpos( $empty_overview, 'HTTPS فعال' ) === false, 'Insecure site shows an actionable HTTPS warning' );
admin_assert( strpos( $empty_overview, '0 دستگاه · مشاهده' ) !== false, 'No device shows zero rather than sample data' );
unset( $GLOBALS['fixture_session_rows'], $GLOBALS['fixture_ssl'] );
define( 'WC_VERSION', 'fixture' );
admin_assert( strpos( admin_test_render( 'overview' ), 'فعال و آماده' ) !== false, 'WooCommerce status follows current availability' );
$connection = admin_test_render( 'connection' );
admin_assert( strpos( $connection, 'value="https://example.test/manager/"' ) !== false, 'App address keeps canonical route after alias changes' );
admin_assert( strpos( $connection, 'fandoogh_manager_pairing_nonce' ) !== false, 'Pairing retains its independent nonce' );
admin_assert( strpos( $connection, 'id="fandoogh-admin-guide"' ) === false, 'Other tabs link back to one overview guide' );
foreach ( array( 'toplevel_page_fandoogh-manager', 'fandoogh-manager_page_fandoogh-manager-settings' ) as $hook ) {
    $GLOBALS['fixture_styles'] = $GLOBALS['fixture_scripts'] = array();
    $_GET['tab'] = 'overview';
    \Fandoogh_Manager\enqueue_admin_assets( $hook );
    admin_assert( 3 === count( $GLOBALS['fixture_styles'] ) && 1 === count( $GLOBALS['fixture_scripts'] ), 'Both plugin admin pages receive font and guide assets' );
    admin_assert( $GLOBALS['fixture_styles'][2][2] === array( 'fandoogh-manager-admin' ), 'Friendly stylesheet follows existing styles' );
    admin_assert( $GLOBALS['fixture_scripts'][0][3] === \Fandoogh_Manager\VERSION, 'Guide cache version follows plugin version' );
}
$GLOBALS['fixture_styles'] = $GLOBALS['fixture_scripts'] = array();
\Fandoogh_Manager\enqueue_admin_assets( 'dashboard' );
admin_assert( empty( $GLOBALS['fixture_styles'] ) && empty( $GLOBALS['fixture_scripts'] ), 'Other WordPress screens remain unaffected' );
$GLOBALS['fixture_authorized'] = false;
$denied = false;
try { \Fandoogh_Manager\render_admin_page(); } catch ( RuntimeException $error ) { $denied = true; }
admin_assert( $denied, 'Non-admin cannot render panel or perform its actions' );
\Fandoogh_Manager\deactivate();
admin_assert( $GLOBALS['fixture_unscheduled_hooks'] === array( \Fandoogh_Manager\OPERATIONS_PUSH_HOOK, \Fandoogh_Manager\OPERATIONS_BATCH_HOOK, \Fandoogh_Manager\OPERATIONS_FLUSH_HOOK, \Fandoogh_Manager\SESSION_ALERT_CRON_HOOK ), 'Deactivation clears all alert argument variants' );
echo "Admin panel: $checks contract checks passed.\n";
