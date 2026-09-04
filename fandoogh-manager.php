<?php
/**
 * Plugin Name: Fandoogh Manager
 * Description: A dependency-free WordPress/WooCommerce management PWA foundation.
 * Version: 1.3.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Fandoogh
 * Text Domain: fandoogh-manager
 */

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.3.2';
const REST_NAMESPACE = 'fandoogh-manager/v1';
const OPTION_KEY = 'fandoogh_manager_settings';
const OPTION_GROUP = 'fandoogh_manager_settings_group';
const APP_QUERY_VAR = 'fandoogh_manager_app';
const APP_ASSET_QUERY_VAR = 'fandoogh_manager_asset';
const DEFAULT_APP_SLUG = 'manager';

if ( ! defined( 'FANDOOGH_MANAGER_VERSION' ) ) {
	define( 'FANDOOGH_MANAGER_VERSION', VERSION );
}

if ( ! defined( 'FANDOOGH_MANAGER_FILE' ) ) {
	define( 'FANDOOGH_MANAGER_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . 'app/security.php';
require_once plugin_dir_path( __FILE__ ) . 'app/products.php';
require_once plugin_dir_path( __FILE__ ) . 'app/bulk-pricing.php';
require_once plugin_dir_path( __FILE__ ) . 'app/attributes.php';
require_once plugin_dir_path( __FILE__ ) . 'app/taxonomy.php';
require_once plugin_dir_path( __FILE__ ) . 'app/variations.php';
require_once plugin_dir_path( __FILE__ ) . 'app/customers.php';
require_once plugin_dir_path( __FILE__ ) . 'app/orders.php';
require_once plugin_dir_path( __FILE__ ) . 'app/shipping.php';
require_once plugin_dir_path( __FILE__ ) . 'app/order-tracking.php';
require_once plugin_dir_path( __FILE__ ) . 'app/analytics.php';
require_once plugin_dir_path( __FILE__ ) . 'app/media.php';
require_once plugin_dir_path( __FILE__ ) . 'app/fonts.php';
require_once plugin_dir_path( __FILE__ ) . 'app/coupons.php';
require_once plugin_dir_path( __FILE__ ) . 'app/reviews.php';
require_once plugin_dir_path( __FILE__ ) . 'app/inventory.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

add_action( 'init', __NAMESPACE__ . '\\load_plugin_textdomain', 0 );
add_action( 'init', __NAMESPACE__ . '\\maybe_ensure_security_schema', 1 );
add_action( 'init', __NAMESPACE__ . '\\maybe_schedule_security_cleanup', 2 );
add_action( 'init', __NAMESPACE__ . '\\register_rewrite_rules', 20 );
add_action( 'init', __NAMESPACE__ . '\\register_shortcodes', 20 );
add_action( SECURITY_CLEANUP_HOOK, __NAMESPACE__ . '\\cleanup_security_data' );

/**
 * Load the plugin's translations from the /languages folder. Strings are
 * extracted into languages/fandoogh-manager.pot; a translator can compile a
 * e.g. fa_IR.mo next to it to override the built-in Persian source strings.
 *
 * @return void
 */
function load_plugin_textdomain() {
	\load_plugin_textdomain( 'fandoogh-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_filter( 'query_vars', __NAMESPACE__ . '\\register_query_vars' );
add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_serve_app_asset', 1 );
add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_render_app_shell' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\add_private_api_headers', 10, 3 );
add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );

add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_menu' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets' );
add_action( 'admin_notices', __NAMESPACE__ . '\\maybe_render_woocommerce_notice' );
add_filter( 'option_page_capability_' . OPTION_GROUP, __NAMESPACE__ . '\\settings_capability' );
add_action( 'update_option_' . OPTION_KEY, __NAMESPACE__ . '\\maybe_flush_rewrite_rules_on_settings_update', 10, 3 );

/**
 * Return the default option values. This is intentionally a function so the
 * defaults remain easy to inspect and do not get exposed as a public option.
 *
 * @return array<string, mixed>
 */
function default_settings() {
	return array(
		'colors'           => array(
			'primary'    => '#0F766E',
			'secondary'  => '#115E59',
			'accent'     => '#F59E0B',
			'background' => '#FFFFFF',
			'text'       => '#1F2937',
		),
		'slug'             => DEFAULT_APP_SLUG,
		'image_max_width'  => 2048,
		'image_max_height' => 2048,
		'image_quality'    => 82,
		'image_max_bytes'  => 5 * 1024 * 1024,
		'keep_original'    => true,
		'session_alerts_enabled' => true,
		'session_alert_email'    => '',
		'analytics_enabled' => false,
		'local_fonts'      => array(),
		'order_tracking'   => order_tracking_default_settings(),
	);
}

/**
 * Add the option on activation without overwriting an existing installation.
 * Rewrite rules are flushed only during lifecycle events or when the slug
 * actually changes.
 *
 * @return void
 */
function activate() {
	if ( false === get_option( OPTION_KEY, false ) ) {
		add_option( OPTION_KEY, default_settings() );
	}

	ensure_security_schema();
	if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( SECURITY_CLEANUP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SECURITY_CLEANUP_HOOK );
	}
	register_rewrite_rules();
	flush_rewrite_rules();
}

/**
 * @return void
 */
function deactivate() {
	wp_clear_scheduled_hook( SECURITY_CLEANUP_HOOK );
	flush_rewrite_rules();
}

/**
 * Normalize a configured app slug and keep it away from common WordPress
 * reserved paths. The stable /manager/ route is always registered as an
 * alias, even if the configured slug changes.
 *
 * @param mixed $value Candidate slug.
 * @return string
 */
function sanitize_app_slug( $value ) {
	$slug = is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
	$reserved = array(
		'wp-admin',
		'wp-login',
		'wp-login.php',
		'wp-json',
		'feed',
		'embed',
		'author',
		'search',
		'404',
	);

	if ( '' === $slug || strlen( $slug ) > 50 || in_array( $slug, $reserved, true ) ) {
		return DEFAULT_APP_SLUG;
	}

	return $slug;
}

/**
 * Sanitize managed local-font paths. Only flat files beneath the dedicated
 * uploads/fandoogh-manager/fonts directory are accepted.
 *
 * @param mixed $value Raw allowlist value.
 * @return array<int, string>
 */
function sanitize_local_font_allowlist( $value ) {
	if ( is_string( $value ) ) {
		$value = preg_split( '/\r\n|\r|\n/', $value );
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$allowed = array();

	foreach ( $value as $font_path ) {
		if ( ! is_scalar( $font_path ) ) {
			continue;
		}

		$font_path = trim( str_replace( '\\', '/', (string) $font_path ) );
		$font_path = ltrim( $font_path, '/' );

		if ( '' === $font_path || strlen( $font_path ) > 200 || false !== strpos( $font_path, "\0" ) ) {
			continue;
		}

		$segments = explode( '/', $font_path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				$font_path = '';
				break;
			}
		}

		if ( '' === $font_path || false !== strpos( $font_path, '..' ) || false !== strpos( $font_path, '://' ) ) {
			continue;
		}

		if ( ! preg_match( '#^fandoogh-manager/fonts/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:woff2?|ttf|otf)$#i', $font_path ) ) {
			continue;
		}

		$allowed[] = $font_path;
	}

	return array_values( array_unique( $allowed ) );
}

/**
 * Build the contrast report rows shown on the settings page. Mirrors the
 * pairs checked by validate_colors_contrast() so admins see the same data.
 *
 * @param array<string, string> $colors Current palette.
 * @return array<int, array{first: string, second: string, ratio: float, minimum: float, label: string}>
 */
function theme_contrast_report( $colors ) {
	$colors = is_array( $colors ) ? $colors : array();
	$defaults = default_settings()['colors'];
	foreach ( array_keys( $defaults ) as $color_key ) {
		if ( ! isset( $colors[ $color_key ] ) || ! is_string( $colors[ $color_key ] ) || '' === $colors[ $color_key ] ) {
			$colors[ $color_key ] = $defaults[ $color_key ];
		}
	}

	return array(
		array(
			'first' => $colors['text'],
			'second' => $colors['background'],
			'ratio' => color_contrast_ratio( $colors['text'], $colors['background'] ),
			'minimum' => 4.5,
			'label' => __( 'متن روی پس‌زمینه', 'fandoogh-manager' ),
		),
		array(
			'first' => '#FFFFFF',
			'second' => $colors['primary'],
			'ratio' => color_contrast_ratio( '#FFFFFF', $colors['primary'] ),
			'minimum' => 4.5,
			'label' => __( 'متن سفید روی رنگ اصلی', 'fandoogh-manager' ),
		),
		array(
			'first' => '#FFFFFF',
			'second' => $colors['secondary'],
			'ratio' => color_contrast_ratio( '#FFFFFF', $colors['secondary'] ),
			'minimum' => 4.5,
			'label' => __( 'متن سفید روی رنگ دوم', 'fandoogh-manager' ),
		),
		array(
			'first' => $colors['primary'],
			'second' => $colors['background'],
			'ratio' => color_contrast_ratio( $colors['primary'], $colors['background'] ),
			'minimum' => 3.0,
			'label' => __( 'رنگ اصلی روی پس‌زمینه', 'fandoogh-manager' ),
		),
		array(
			'first' => $colors['text'],
			'second' => $colors['accent'],
			'ratio' => color_contrast_ratio( $colors['text'], $colors['accent'] ),
			'minimum' => 3.0,
			'label' => __( 'متن روی رنگ تأکیدی', 'fandoogh-manager' ),
		),
	);
}

/**
 * WCAG 2.x relative luminance of a hex color.
 *
 * @param string $hex_color Six-digit hex color, e.g. #0F766E.
 * @return float Luminance between 0 and 1.
 */
function color_relative_luminance( $hex_color ) {
	$hex = ltrim( (string) $hex_color, '#' );
	if ( 6 !== strlen( $hex ) ) {
		return 0.0;
	}

	$channels = array();
	for ( $offset = 0; $offset < 6; $offset += 2 ) {
		$value = hexdec( substr( $hex, $offset, 2 ) ) / 255;
		$channels[] = ( $value <= 0.03928 ) ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}

	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * WCAG 2.x contrast ratio between two hex colors (1.0 – 21.0).
 *
 * @param string $first_color  First hex color.
 * @param string $second_color Second hex color.
 * @return float
 */
function color_contrast_ratio( $first_color, $second_color ) {
	$first_luminance  = color_relative_luminance( $first_color );
	$second_luminance = color_relative_luminance( $second_color );
	$lighter = max( $first_luminance, $second_luminance );
	$darker  = min( $first_luminance, $second_luminance );

	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * Validate the saved palette against WCAG contrast thresholds. White is used
 * for the "on-primary/secondary" checks because the app and admin surfaces
 * render white text on brand-colored chrome.
 *
 * Thresholds: WCAG AA 4.5:1 for body text, 3:1 for large text/UI boundaries.
 *
 * @param array<string, string> $colors Palette with primary, secondary, accent, background, text keys.
 * @return true|array<string, array{ratio: float, message: string}> True when readable, otherwise problem rows.
 */
function validate_colors_contrast( $colors ) {
	$colors = is_array( $colors ) ? $colors : array();
	$defaults = default_settings()['colors'];
	foreach ( array_keys( $defaults ) as $color_key ) {
		if ( ! isset( $colors[ $color_key ] ) || ! is_string( $colors[ $color_key ] ) || '' === $colors[ $color_key ] ) {
			$colors[ $color_key ] = $defaults[ $color_key ];
		}
	}

	$minimum_text = 4.5;
	$minimum_ui   = 3.0;
	$checks = array(
		array( 'keys' => array( 'text', 'background' ), 'minimum' => $minimum_text, 'label' => __( 'متن روی پس‌زمینه', 'fandoogh-manager' ) ),
		array( 'keys' => array( '#FFFFFF', 'primary' ), 'minimum' => $minimum_text, 'label' => __( 'متن سفید روی رنگ اصلی', 'fandoogh-manager' ) ),
		array( 'keys' => array( '#FFFFFF', 'secondary' ), 'minimum' => $minimum_text, 'label' => __( 'متن سفید روی رنگ دوم', 'fandoogh-manager' ) ),
		array( 'keys' => array( 'primary', 'background' ), 'minimum' => $minimum_ui, 'label' => __( 'رنگ اصلی روی پس‌زمینه', 'fandoogh-manager' ) ),
		array( 'keys' => array( 'text', 'accent' ), 'minimum' => $minimum_ui, 'label' => __( 'متن روی رنگ تأکیدی', 'fandoogh-manager' ) ),
	);

	$problems = array();
	foreach ( $checks as $check ) {
		$keys = $check['keys'];
		$first  = 0 === strpos( $keys[0], '#' ) ? $keys[0] : $colors[ $keys[0] ];
		$second = 0 === strpos( $keys[1], '#' ) ? $keys[1] : $colors[ $keys[1] ];
		$ratio = color_contrast_ratio( $first, $second );
		if ( $ratio + 0.005 < $check['minimum'] ) {
			$problems[] = array(
				'ratio' => $ratio,
				'message' => sprintf(
					/* translators: 1: pair label, 2: ratio, 3: required minimum */
					__( 'ترکیب «%1$s» ناخوانا است: نسبت کنتراست %2$s است و حداقل %3$s لازم است. رنگ‌های قبلی حفظ شدند.', 'fandoogh-manager' ),
					$check['label'],
					number_format_i18n( $ratio, 2 ),
					number_format_i18n( $check['minimum'], 1 )
				),
			);
		}
	}

	return empty( $problems ) ? true : $problems;
}

/**
 * Sanitize the complete settings option. Values are bounded before they can
 * be used by any future image-processing integration.
 *
 * @param mixed $raw_settings Raw option value.
 * @return array<string, mixed>
 */
function sanitize_settings( $raw_settings ) {
	$defaults = default_settings();

	if ( ! is_array( $raw_settings ) ) {
		return $defaults;
	}

	$settings = $defaults;
	$previous = get_option( OPTION_KEY, array() );
	$previous_colors = ( isset( $previous['colors'] ) && is_array( $previous['colors'] ) ) ? $previous['colors'] : $defaults['colors'];

	if ( isset( $raw_settings['colors'] ) && is_array( $raw_settings['colors'] ) ) {
		foreach ( $defaults['colors'] as $color_key => $default_color ) {
			if ( ! isset( $raw_settings['colors'][ $color_key ] ) || ! is_scalar( $raw_settings['colors'][ $color_key ] ) ) {
				continue;
			}

			$color = sanitize_hex_color( (string) $raw_settings['colors'][ $color_key ] );
			if ( $color ) {
				$settings['colors'][ $color_key ] = $color;
			}
		}
	}

	// Reject palettes that fail WCAG contrast so an unreadable theme can never go live.
	$contrast = validate_colors_contrast( $settings['colors'] );
	if ( true !== $contrast ) {
		$settings['colors'] = is_array( $previous_colors ) ? $previous_colors : $defaults['colors'];
		if ( function_exists( 'add_settings_error' ) ) {
			foreach ( $contrast as $problem ) {
				add_settings_error(
					OPTION_KEY,
					'fandoogh_contrast_' . md5( (string) $problem['message'] ),
					$problem['message'],
					'error'
				);
			}
		}
	}

	$settings['slug'] = isset( $raw_settings['slug'] ) ? sanitize_app_slug( $raw_settings['slug'] ) : DEFAULT_APP_SLUG;

	if ( isset( $raw_settings['image_max_width'] ) ) {
		$settings['image_max_width'] = max( 1, min( 10000, absint( $raw_settings['image_max_width'] ) ) );
	}

	if ( isset( $raw_settings['image_max_height'] ) ) {
		$settings['image_max_height'] = max( 1, min( 10000, absint( $raw_settings['image_max_height'] ) ) );
	}

	if ( isset( $raw_settings['image_quality'] ) ) {
		$settings['image_quality'] = max( 1, min( 100, absint( $raw_settings['image_quality'] ) ) );
	}

	if ( isset( $raw_settings['image_max_bytes'] ) ) {
		$settings['image_max_bytes'] = max( 1024, min( 100 * 1024 * 1024, absint( $raw_settings['image_max_bytes'] ) ) );
	}

	$settings['keep_original'] = ! empty( $raw_settings['keep_original'] );
	$settings['session_alerts_enabled'] = ! empty( $raw_settings['session_alerts_enabled'] );
	$settings['session_alert_email'] = isset( $raw_settings['session_alert_email'] ) ? sanitize_email( (string) $raw_settings['session_alert_email'] ) : '';
	$settings['analytics_enabled'] = ! empty( $raw_settings['analytics_enabled'] );
	$settings['local_fonts'] = isset( $raw_settings['local_fonts'] ) ? sanitize_local_font_allowlist( $raw_settings['local_fonts'] ) : array();
	$settings['order_tracking'] = isset( $raw_settings['order_tracking'] ) ? order_tracking_sanitize_settings( $raw_settings['order_tracking'] ) : order_tracking_default_settings();

	return $settings;
}

/**
 * Read settings through the same sanitizer used by Settings API. This keeps
 * legacy or manually-added option values from reaching public responses.
 *
 * @return array<string, mixed>
 */
function get_settings() {
	return sanitize_settings( get_option( OPTION_KEY, array() ) );
}

/**
 * @return array<int, string>
 */
function get_app_slugs() {
	$settings = get_settings();
	$slugs = array( DEFAULT_APP_SLUG, sanitize_app_slug( $settings['slug'] ) );

	return array_values( array_unique( $slugs ) );
}

/**
 * @return void
 */
function register_rewrite_rules() {
	foreach ( get_app_slugs() as $slug ) {
		add_rewrite_rule(
			'^' . preg_quote( $slug, '#' ) . '/?$',
			'index.php?' . APP_QUERY_VAR . '=1',
			'top'
		);

		add_rewrite_rule(
			'^' . preg_quote( $slug, '#' ) . '/(manifest\\.webmanifest|sw\\.js|app\\.js|styles\\.css|fonts\\.css|ui\\.css)$',
			'index.php?' . APP_ASSET_QUERY_VAR . '=$matches[1]',
			'top'
		);
	}
}

/**
 * @param array<int, string> $query_vars Existing query vars.
 * @return array<int, string>
 */
function register_query_vars( $query_vars ) {
	if ( ! in_array( APP_QUERY_VAR, $query_vars, true ) ) {
		$query_vars[] = APP_QUERY_VAR;
	}

	if ( ! in_array( APP_ASSET_QUERY_VAR, $query_vars, true ) ) {
		$query_vars[] = APP_ASSET_QUERY_VAR;
	}

	return $query_vars;
}

/**
 * Register the optional public launcher. The shortcode renders a link to the
 * same-origin PWA; it never embeds wp-admin or creates an iframe session.
 *
 * @return void
 */
function register_shortcodes() {
	add_shortcode( 'fandoogh_manager', __NAMESPACE__ . '\\render_launcher_shortcode' );
}

/**
 * Render a safe launcher link for a page, menu, or block that supports
 * shortcodes. The PWA itself remains at the stable /manager/ route.
 *
 * @param array<string, mixed> $atts Shortcode attributes.
 * @return string
 */
function render_launcher_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'label'   => __( 'باز کردن مدیریت', 'fandoogh-manager' ),
			'new_tab' => '0',
		),
		$atts,
		'fandoogh_manager'
	);

	$label = sanitize_text_field( (string) $atts['label'] );
	if ( '' === $label ) {
		$label = __( 'باز کردن مدیریت', 'fandoogh-manager' );
	}

	$new_tab = in_array( strtolower( (string) $atts['new_tab'] ), array( '1', 'true', 'yes' ), true );
	$target  = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

	return '<a class="fandoogh-manager-launcher" href="' . esc_url( app_base_url() ) . '"' . $target . '>' . esc_html( $label ) . '</a>';
}

/**
 * Flush rules only when the configurable alias changes.
 *
 * @param mixed  $old_value Previous option value.
 * @param mixed  $value     New option value.
 * @param string $option    Option name.
 * @return void
 */
function maybe_flush_rewrite_rules_on_settings_update( $old_value, $value, $option ) {
	if ( OPTION_KEY !== $option ) {
		return;
	}

	$old_slug = is_array( $old_value ) && isset( $old_value['slug'] ) ? sanitize_app_slug( $old_value['slug'] ) : DEFAULT_APP_SLUG;
	$new_slug = is_array( $value ) && isset( $value['slug'] ) ? sanitize_app_slug( $value['slug'] ) : DEFAULT_APP_SLUG;

	if ( $old_slug !== $new_slug ) {
		register_rewrite_rules();
		flush_rewrite_rules( false );
	}
}

/**
 * Return the stable, same-origin PWA base URL. The configurable slug is an
 * alias; keeping one stable base prevents an installed PWA from becoming a
 * different application when an administrator changes the display slug.
 *
 * @return string
 */
function app_base_url() {
	return trailingslashit( home_url( '/' . DEFAULT_APP_SLUG . '/' ) );
}

/**
 * Build a content fingerprint for the shell, public assets, and plugin code.
 * The value changes whenever an installed plugin file changes, so browsers and
 * service workers do not need a manually-maintained cache-buster number.
 *
 * @return string
 */
function app_asset_version() {
	$files = array(
		__FILE__,
		plugin_dir_path( __FILE__ ) . 'app/index.html',
		plugin_dir_path( __FILE__ ) . 'app/app.js',
		plugin_dir_path( __FILE__ ) . 'app/styles.css',
		plugin_dir_path( __FILE__ ) . 'app/fonts.css',
		plugin_dir_path( __FILE__ ) . 'app/ui.css',
		plugin_dir_path( __FILE__ ) . 'app/sw.js',
	);

	$php_files = glob( plugin_dir_path( __FILE__ ) . 'app/*.php' );
	if ( is_array( $php_files ) ) {
		$files = array_merge( $files, $php_files );
	}

	$icon_files = glob( plugin_dir_path( __FILE__ ) . 'assets/icon/*.svg' );
	if ( is_array( $icon_files ) ) {
		$files = array_merge( $files, $icon_files );
	}

	$brand_files = glob( plugin_dir_path( __FILE__ ) . 'assets/brand/*.svg' );
	if ( is_array( $brand_files ) ) {
		$files = array_merge( $files, $brand_files );
	}

	$parts = array( FANDOOGH_MANAGER_VERSION );
	foreach ( array_unique( $files ) as $file ) {
		if ( ! is_readable( $file ) ) {
			continue;
		}

		$hash = md5_file( $file );
		$parts[] = is_string( $hash ) ? $hash : (string) filemtime( $file );
	}

	return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 24 );
}

/**
 * Return the public asset URLs used by the shell.
 *
 * @return array<string, string>
 */
function app_asset_urls() {
	$base    = app_base_url();
	$version = app_asset_version();

	return array(
		'app'       => add_query_arg( 'ver', $version, $base . 'app.js' ),
		'styles'    => add_query_arg( 'ver', $version, $base . 'styles.css' ),
		'sw'        => add_query_arg( 'ver', $version, $base . 'sw.js' ),
		'manifest'  => add_query_arg( 'ver', $version, $base . 'manifest.webmanifest' ),
		'fonts'     => add_query_arg( 'ver', $version, $base . 'fonts.css' ),
		'ui'        => add_query_arg( 'ver', $version, $base . 'ui.css' ),
		'config'    => rest_url( REST_NAMESPACE . '/config' ),
		'version'   => $version,
	);
}

/**
 * Return the local vector icons used by navigation and dashboard actions.
 * Keys intentionally use the name of the UI element, while values retain the
 * exact local source file so the mapping stays auditable without a CDN.
 *
 * @return array<string, string>
 */
function get_public_icon_urls() {
	$icon_files = array(
		'dashboard'     => 'fandoogh-dashboard.svg',
		'orders'        => 'fandoogh-orders.svg',
		'products'      => 'fandoogh-products.svg',
		'inventory'     => 'fandoogh-inventory.svg',
		'customers'     => 'fandoogh-customers.svg',
		'coupons'       => 'fandoogh-coupons.svg',
		'reviews'       => 'fandoogh-reviews.svg',
		'analytics'     => 'fandoogh-analytics.svg',
		'categories'    => 'fandoogh-categories.svg',
		'security'      => 'fandoogh-security.svg',
		'more'          => 'fandoogh-more.svg',
		'new-product'   => 'fandoogh-new-product.svg',
		'filter'        => 'fandoogh-filter.svg',
		'notifications' => 'fandoogh-notifications.svg',
	);
	$version = app_asset_version();
	$icons  = array();

	foreach ( $icon_files as $element_name => $filename ) {
		$file = plugin_dir_path( __FILE__ ) . 'assets/icon/' . $filename;
		if ( ! is_readable( $file ) ) {
			continue;
		}

		$url = add_query_arg( 'ver', $version, plugins_url( 'assets/icon/' . $filename, __FILE__ ) );
		$url = public_asset_url( $url );
		if ( $url ) {
			$icons[ $element_name ] = $url;
		}
	}

	return $icons;
}

/**
 * Serve the small, local PWA asset allowlist through WordPress. The plugin is
 * normally installed below wp-content/plugins, so these files cannot be
 * assumed to be reachable at /manager/ by the web server directly.
 *
 * @return void
 */
function maybe_serve_app_asset() {
	if ( is_admin() ) {
		return;
	}

	$asset = get_query_var( APP_ASSET_QUERY_VAR );
	if ( ! is_string( $asset ) || '' === $asset ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		status_header( 405 );
		header( 'Allow: GET, HEAD' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'این روش برای این نشانی مجاز نیست.', 'fandoogh-manager' );
		exit;
	}

	$asset = sanitize_file_name( $asset );
	if ( 'manifest.webmanifest' === $asset ) {
		serve_manifest();
		exit;
	}

	if ( 'fonts.css' === $asset ) {
		serve_fonts_css();
		exit;
	}

	$asset_map = array(
		'app.js'     => array( 'file' => 'app/app.js', 'type' => 'application/javascript; charset=utf-8' ),
		'styles.css' => array( 'file' => 'app/styles.css', 'type' => 'text/css; charset=utf-8' ),
		'ui.css'     => array( 'file' => 'app/ui.css', 'type' => 'text/css; charset=utf-8' ),
		'sw.js'      => array( 'file' => 'app/sw.js', 'type' => 'application/javascript; charset=utf-8' ),
	);

	if ( ! isset( $asset_map[ $asset ] ) ) {
		status_header( 404 );
		exit;
	}

	$file = plugin_dir_path( __FILE__ ) . $asset_map[ $asset ]['file'];
	if ( ! is_readable( $file ) ) {
		status_header( 404 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Type: ' . $asset_map[ $asset ]['type'] );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: no-cache, must-revalidate' );

	if ( 'HEAD' !== $method ) {
		readfile( $file );
	}

	exit;
}

/**
 * Serve a manifest whose URLs are derived from the current WordPress site.
 * This keeps subdirectory installs and local branding on the same origin.
 *
 * @return void
 */
function serve_manifest() {
	$branding = get_public_branding();
	$settings = get_settings();
	$urls     = app_asset_urls();
	$manifest = array(
		'id'                          => app_base_url(),
		'name'                        => ! empty( $branding['site_name'] ) ? $branding['site_name'] . ' — مدیریت' : 'مدیریت فروشگاه',
		'short_name'                  => ! empty( $branding['site_name'] ) ? $branding['site_name'] : 'مدیریت فروشگاه',
		'description'                 => 'وب‌اپ مدیریت فروشگاه WooCommerce',
		'lang'                        => 'fa-IR',
		'dir'                         => 'rtl',
		'start_url'                   => app_base_url(),
		'scope'                       => app_base_url(),
		'display'                     => 'standalone',
		'display_override'            => array( 'standalone' ),
		'orientation'                 => 'any',
		'theme_color'                 => $settings['colors']['primary'],
		'background_color'            => $settings['colors']['background'],
		'prefer_related_applications' => false,
		'launch_handler'              => array( 'client_mode' => 'navigate-existing' ),
	);

	$icons = array();
	foreach ( array( $branding['site_icon_url'], $branding['logo_url'] ) as $icon_url ) {
		if ( is_string( $icon_url ) && '' !== $icon_url ) {
			$icons[] = array(
				'src'   => $icon_url,
				'sizes' => '512x512',
				'type'  => 'image/png',
				'purpose' => 'any maskable',
			);
			break;
		}
	}

	$bundled_icon_path = plugin_dir_path( __FILE__ ) . 'assets/brand/fandoogh-mark.svg';
	if ( is_readable( $bundled_icon_path ) ) {
		$bundled_icon_url = public_asset_url( add_query_arg( 'ver', $urls['version'], plugins_url( 'assets/brand/fandoogh-mark.svg', __FILE__ ) ) );
		if ( $bundled_icon_url ) {
			$icons[] = array(
				'src'    => $bundled_icon_url,
				'sizes'  => 'any',
				'type'   => 'image/svg+xml',
				'purpose' => 'any maskable',
			);
		}
	}

	if ( ! empty( $icons ) ) {
		$manifest['icons'] = $icons;
	}

	nocache_headers();
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	echo wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * @return void
 */
function register_rest_routes() {
	register_security_routes();
	register_product_routes();
	register_bulk_pricing_routes();
	register_product_attribute_routes();
	register_taxonomy_routes();
	register_variation_routes();
	register_customer_routes();
	register_order_routes();
	register_shipping_routes();
	register_analytics_routes();
	register_media_routes();
	register_coupon_routes();
	register_review_routes();
	register_inventory_routes();

	register_rest_route(
		REST_NAMESPACE,
		'/health',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_health_response',
			'permission_callback' => __NAMESPACE__ . '\\public_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/config',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_config_response',
			'permission_callback' => __NAMESPACE__ . '\\public_permission',
		)
	);
}

/**
 * Public endpoints intentionally do not require an account. They return only
 * the explicitly selected public fields below.
 *
 * @return bool
 */
function public_permission() {
	return true;
}

/**
 * Prevent browsers, proxies, and service workers from retaining any response
 * that could contain session state or product-management data, including
 * authentication errors.
 *
 * @param mixed            $response REST response.
 * @param WP_REST_Server   $server REST server.
 * @param WP_REST_Request  $request REST request.
 * @return mixed
 */
function add_private_api_headers( $response, $server, $request ) {
	$route = (string) $request->get_route();
	if ( 0 === strpos( $route, '/' . REST_NAMESPACE . '/auth/' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/products' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/product-attributes' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/product-categories' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/product-shipping-classes' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/customers' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/orders' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/media' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/coupons' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/reviews' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/inventory' ) || 0 === strpos( $route, '/' . REST_NAMESPACE . '/analytics' ) ) {
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
	}

	return $response;
}

/**
 * Tell WooCommerce that this plugin uses the supported CRUD/query boundaries
 * and does not depend on the legacy order tables. The hook is intentionally
 * guarded so the plugin remains usable when WooCommerce is inactive.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	$features_util = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';

	if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
		$features_util::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}

/**
 * Detect WooCommerce without making it a hard dependency. All backend routes
 * remain available when WooCommerce is absent or deactivated.
 *
 * @return bool
 */
function is_woocommerce_active() {
	return class_exists( '\\WooCommerce' ) || defined( 'WC_VERSION' ) || function_exists( 'WC' );
}

/**
 * Expose only major/minor WordPress version information.
 *
 * @return string
 */
function public_wordpress_version() {
	$version = (string) get_bloginfo( 'version' );

	if ( preg_match( '/^(\d+\.\d+)/', $version, $matches ) ) {
		return $matches[1];
	}

	return '';
}

/**
 * Convert a site URL to its origin only. Paths, query strings, fragments,
 * usernames, and passwords never reach the public health response.
 *
 * @param string $url URL to normalize.
 * @return string
 */
function origin_safe_url( $url ) {
	$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return '';
	}

	$host = strtolower( (string) $parts['host'] );
	if ( ! preg_match( '/^[A-Za-z0-9.\-:\[\]]+$/', $host ) ) {
		return '';
	}

	$origin = $scheme . '://';
	if ( false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ) {
		$origin .= '[' . $host . ']';
	} else {
		$origin .= $host;
	}

	if ( isset( $parts['port'] ) && absint( $parts['port'] ) > 0 && absint( $parts['port'] ) <= 65535 ) {
		$origin .= ':' . absint( $parts['port'] );
	}

	return $origin;
}

/**
 * Allow only public HTTP(S) URLs for branding assets.
 *
 * @param mixed $url Candidate URL.
 * @return string|null
 */
function public_asset_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return null;
	}

	$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return null;
	}

	if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return null;
	}

	$site_origin  = origin_safe_url( home_url( '/' ) );
	$asset_origin = origin_safe_url( $url );
	if ( ! $site_origin || ! $asset_origin || untrailingslashit( $site_origin ) !== untrailingslashit( $asset_origin ) ) {
		return null;
	}

	$clean_url = esc_url_raw( $url, array( 'http', 'https' ) );
	return $clean_url ? $clean_url : null;
}

/**
 * Detect WebP support without exposing the image-engine details.
 *
 * @return bool
 */
function webp_support() {
	if ( function_exists( 'wp_image_editor_supports' ) ) {
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return true;
		}
	}

	if ( function_exists( 'gd_info' ) ) {
		$gd_info = gd_info();
		if ( is_array( $gd_info ) && ! empty( $gd_info['WebP Support'] ) ) {
			return true;
		}
	}

	if ( class_exists( '\\Imagick' ) ) {
		try {
			$image_magick = new \Imagick();
			$formats = $image_magick->queryFormats( 'WEBP' );
			if ( is_array( $formats ) && ! empty( $formats ) ) {
				return true;
			}
		} catch ( \Throwable $exception ) {
			// Image support is informational; a broken optional engine is not fatal.
		}
	}

	return false;
}

/**
 * @return array<string, mixed>
 */
function get_health_response() {
	$response = rest_ensure_response(
		array(
			'plugin_version'     => FANDOOGH_MANAGER_VERSION,
			'wordpress_version'  => public_wordpress_version(),
			'woocommerce_active' => is_woocommerce_active(),
			'webp_support'       => webp_support(),
			'site_url'            => origin_safe_url( home_url( '/' ) ),
		)
	);
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	return $response;
}

/**
 * @return array<string, mixed>
 */
function get_public_branding() {
	$logo_id = absint( get_theme_mod( 'custom_logo' ) );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	$icon_url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';

	return array(
		'site_name'     => sanitize_text_field( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
		'logo_url'      => public_asset_url( $logo_url ),
		'site_icon_url' => public_asset_url( $icon_url ),
	);
}

/**
 * Return the small, public currency projection needed by the PWA. The
 * underlying WooCommerce currency code remains unchanged; IRT is presented
 * as تومان in the management UI.
 *
 * @return array<string, string>
 */
function get_public_currency() {
	$code = function_exists( 'get_woocommerce_currency' ) ? strtoupper( sanitize_key( (string) get_woocommerce_currency() ) ) : '';
	$label = '';

	if ( 'IRT' === $code ) {
		$label = 'تومان';
	} elseif ( function_exists( 'get_woocommerce_currency_symbol' ) && '' !== $code ) {
		$label = sanitize_text_field( (string) get_woocommerce_currency_symbol( $code ) );
	}

	if ( '' === $label ) {
		$label = '' !== $code ? $code : 'تومان';
	}

	return array(
		'code'  => $code,
		'label' => $label,
	);
}

/**
 * Return only sanitized theme values and public branding. Image-processing
 * limits remain private server settings and are deliberately omitted.
 *
 * @return array<string, mixed>
 */
function get_config_response() {
	$settings = get_settings();
	$urls     = app_asset_urls();

	$response = rest_ensure_response(
		array(
			'app_url'      => app_base_url(),
			'app_version'  => $urls['version'],
			'site_url'     => origin_safe_url( home_url( '/' ) ),
			'manifest_url' => $urls['manifest'],
			'api'         => array(
				'pair'     => rest_url( REST_NAMESPACE . '/auth/pair' ),
				'me'       => rest_url( REST_NAMESPACE . '/auth/me' ),
				'csrf'     => rest_url( REST_NAMESPACE . '/auth/csrf' ),
				'logout'   => rest_url( REST_NAMESPACE . '/auth/logout' ),
				'devices'  => rest_url( REST_NAMESPACE . '/auth/devices' ),
				'acknowledge' => rest_url( REST_NAMESPACE . '/auth/acknowledge-new-sessions' ),
				'audit'    => rest_url( REST_NAMESPACE . '/auth/audit' ),
				'products' => rest_url( REST_NAMESPACE . '/products' ),
				'product_attributes' => rest_url( REST_NAMESPACE . '/product-attributes' ),
				'shipping_classes' => rest_url( REST_NAMESPACE . '/product-shipping-classes' ),
				'categories' => rest_url( REST_NAMESPACE . '/product-categories' ),
				'customers' => rest_url( REST_NAMESPACE . '/customers' ),
				'orders'   => rest_url( REST_NAMESPACE . '/orders' ),
				'analytics' => rest_url( REST_NAMESPACE . '/analytics/summary' ),
				'media'    => rest_url( REST_NAMESPACE . '/media' ),
				'coupons'  => rest_url( REST_NAMESPACE . '/coupons' ),
				'reviews'  => rest_url( REST_NAMESPACE . '/reviews' ),
			'inventory' => rest_url( REST_NAMESPACE . '/inventory' ),
			),
			'analytics' => array(
				'enabled' => ! empty( $settings['analytics_enabled'] ),
			),
			'tracking' => order_tracking_public_config(),
			'currency' => get_public_currency(),
			'icons'    => get_public_icon_urls(),
			'theme_config' => array(
				'slug'   => $settings['slug'],
				'colors' => $settings['colors'],
				'fonts'  => array(
					'local_fonts'    => get_public_fonts(),
					'upload_enabled' => true,
				),
			),
			'branding' => get_public_branding(),
		)
	);
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	return $response;
}

/**
 * Render a deliberately small fallback shell. It does not include executable
 * inline code, arbitrary file contents, or user-configurable markup.
 *
 * @return void
 */
function maybe_render_app_shell() {
	if ( is_admin() || ! get_query_var( APP_QUERY_VAR ) ) {
		return;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'GET' !== $request_method ) {
		status_header( 405 );
		header( 'Allow: GET' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'این روش برای این نشانی مجاز نیست.', 'fandoogh-manager' );
		exit;
	}

	$file = plugin_dir_path( __FILE__ ) . 'app/index.html';
	if ( ! is_readable( $file ) ) {
		status_header( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'پوستهٔ برنامه در دسترس نیست.', 'fandoogh-manager' );
		exit;
	}

	$html = file_get_contents( $file );
	if ( false === $html ) {
		status_header( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'پوستهٔ برنامه در دسترس نیست.', 'fandoogh-manager' );
		exit;
	}

	$urls = app_asset_urls();
	$meta = '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">';
	$meta .= '<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">';
	$meta .= '<meta name="fandoogh-config-url" content="' . esc_attr( $urls['config'] ) . '">';
	$meta .= '<meta name="fandoogh-service-worker-url" content="' . esc_attr( $urls['sw'] ) . '">';
	$meta .= '<meta name="fandoogh-app-scope" content="' . esc_attr( app_base_url() ) . '">';
	$meta .= '<meta name="fandoogh-app-version" content="' . esc_attr( $urls['version'] ) . '">';

	$branding = get_public_branding();
	$apple_icon = ! empty( $branding['site_icon_url'] ) ? $branding['site_icon_url'] : $branding['logo_url'];
	if ( is_string( $apple_icon ) && '' !== $apple_icon ) {
		$meta .= '<link rel="apple-touch-icon" href="' . esc_url( $apple_icon ) . '">';
	}

	$bundled_icon_path = plugin_dir_path( __FILE__ ) . 'assets/brand/fandoogh-mark.svg';
	$bundled_icon_url  = '';
	if ( is_readable( $bundled_icon_path ) ) {
		$bundled_icon_url = public_asset_url( add_query_arg( 'ver', $urls['version'], plugins_url( 'assets/brand/fandoogh-mark.svg', __FILE__ ) ) );
	}

	$html = str_replace(
		array(
			'href="/manager/manifest.webmanifest"',
			'href="./styles.css"',
			'href="./fonts.css"',
			'href="./ui.css"',
			'href="/assets/brand/fandoogh-mark.svg"',
			'src="/assets/brand/fandoogh-mark.svg"',
			'data-default-logo="/assets/brand/fandoogh-mark.svg"',
			'src="./app.js"',
		),
		array(
			'href="' . esc_url( $urls['manifest'] ) . '"',
			'href="' . esc_url( $urls['styles'] ) . '"',
			'href="' . esc_url( $urls['fonts'] ) . '"',
			'href="' . esc_url( $urls['ui'] ) . '"',
			'href="' . esc_url( $bundled_icon_url ) . '"',
			'src="' . esc_url( $bundled_icon_url ) . '"',
			'data-default-logo="' . esc_url( $bundled_icon_url ) . '"',
			'src="' . esc_url( $urls['app'] ) . '"',
		),
		$html
	);

	$html = str_replace( '</head>', $meta . '</head>', $html );

	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; font-src \'self\'; img-src \'self\' data: blob:; connect-src \'self\'; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
	echo $html;
	exit;
}

/**
 * @return void
 */
function register_settings() {
	register_setting(
		OPTION_GROUP,
		OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => default_settings(),
			'show_in_rest'      => false,
		)
	);

	add_settings_section(
		'fandoogh_manager_theme_section',
		__( 'پیکربندی رنگ و ظاهر', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_theme_section',
		'fandoogh-manager-settings'
	);

	$color_labels = array(
		'primary'    => __( 'رنگ اصلی', 'fandoogh-manager' ),
		'secondary'  => __( 'رنگ ثانویه', 'fandoogh-manager' ),
		'accent'     => __( 'رنگ تأکید', 'fandoogh-manager' ),
		'background' => __( 'رنگ پس‌زمینه', 'fandoogh-manager' ),
		'text'       => __( 'رنگ متن', 'fandoogh-manager' ),
	);

	foreach ( default_settings()['colors'] as $color_key => $unused_default ) {
		add_settings_field(
			'fandoogh_manager_color_' . $color_key,
			isset( $color_labels[ $color_key ] ) ? $color_labels[ $color_key ] : sprintf( __( 'رنگ %s', 'fandoogh-manager' ), ucwords( str_replace( '_', ' ', $color_key ) ) ),
			__NAMESPACE__ . '\\render_color_field',
			'fandoogh-manager-settings',
			'fandoogh_manager_theme_section',
			array( 'key' => $color_key )
		);
	}

	add_settings_field(
		'fandoogh_manager_slug',
		__( 'نامک وب‌اپ', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_slug_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_theme_section'
	);

	add_settings_section(
		'fandoogh_manager_analytics_section',
		__( 'تحلیل فروش WooCommerce', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_analytics_section',
		'fandoogh-manager-settings'
	);

	add_settings_field(
		'fandoogh_manager_analytics_enabled',
		__( 'فعال‌سازی داشبورد تحلیل فروش', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_analytics_enabled_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_analytics_section'
	);

	add_settings_section(
		'fandoogh_manager_session_alerts_section',
		__( 'هشدار نشست‌های جدید', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_session_alerts_section',
		'fandoogh-manager-settings'
	);

	add_settings_field(
		'fandoogh_manager_session_alerts_enabled',
		__( 'فعال‌سازی هشدار نشست‌های جدید', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_session_alerts_enabled_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_session_alerts_section'
	);

	add_settings_field(
		'fandoogh_manager_session_alert_email',
		__( 'ایمیل دریافت هشدار', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_session_alert_email_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_session_alerts_section'
	);

	add_settings_section(
		'fandoogh_manager_image_section',
		__( 'محدودیت‌های پردازش تصویر', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_image_section',
		'fandoogh-manager-settings'
	);

	$image_fields = array(
		'image_max_width'  => __( 'حداکثر عرض (px)', 'fandoogh-manager' ),
		'image_max_height' => __( 'حداکثر ارتفاع (px)', 'fandoogh-manager' ),
		'image_quality'    => __( 'کیفیت (۱ تا ۱۰۰)', 'fandoogh-manager' ),
		'image_max_bytes'  => __( 'حداکثر حجم فایل (بایت)', 'fandoogh-manager' ),
	);

	foreach ( $image_fields as $field_key => $label ) {
		add_settings_field(
			'fandoogh_manager_' . $field_key,
			$label,
			__NAMESPACE__ . '\\render_number_field',
			'fandoogh-manager-settings',
			'fandoogh_manager_image_section',
			array( 'key' => $field_key )
		);
	}

	add_settings_field(
		'fandoogh_manager_keep_original',
		__( 'نگهداری نسخهٔ اصلی', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_keep_original_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_image_section'
	);

	add_settings_section(
		'fandoogh_manager_fonts_section',
		__( 'فهرست مجاز فونت‌های محلی', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_fonts_section',
		'fandoogh-manager-settings'
	);

	add_settings_field(
		'fandoogh_manager_local_fonts',
		__( 'مسیرهای مجاز فونت', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\render_fonts_field',
		'fandoogh-manager-settings',
		'fandoogh_manager_fonts_section'
	);
}

/**
 * @param string $capability Existing capability.
 * @return string
 */
function settings_capability( $capability ) {
	return 'manage_options';
}

/**
 * @return void
 */
function render_theme_section() {
	echo '<p>' . esc_html__( 'این مقادیر عمومی و پاک‌سازی‌شده از طریق مسیر /config در اختیار وب‌اپ قرار می‌گیرند.', 'fandoogh-manager' ) . '</p>';
	echo '<p>' . esc_html__( 'پالت باید حداقل نسبت کنتراست WCAG را رعایت کند؛ پالت ناخوانا در زمان ذخیره رد می‌شود و رنگ‌های قبلی حفظ می‌شوند.', 'fandoogh-manager' ) . '</p>';

	$colors = get_settings()['colors'];
	$checks = theme_contrast_report( $colors );

	echo '<table class="widefat striped fandoogh-contrast-table" style="max-width:640px">';
	echo '<thead><tr><th>' . esc_html__( 'ترکیب', 'fandoogh-manager' ) . '</th><th>' . esc_html__( 'نمونه', 'fandoogh-manager' ) . '</th><th>' . esc_html__( 'نسبت', 'fandoogh-manager' ) . '</th><th>' . esc_html__( 'حداقل', 'fandoogh-manager' ) . '</th><th>' . esc_html__( 'وضعیت', 'fandoogh-manager' ) . '</th></tr></thead><tbody>';
	foreach ( $checks as $check ) {
		$ok = $check['ratio'] + 0.005 >= $check['minimum'];
		echo '<tr data-contrast-pair data-contrast-first="' . esc_attr( $check['first'] ) . '" data-contrast-second="' . esc_attr( $check['second'] ) . '" data-contrast-minimum="' . esc_attr( $check['minimum'] ) . '">';
		echo '<td>' . esc_html( $check['label'] ) . '</td>';
		echo '<td><span class="fandoogh-contrast-swatch" style="background:' . esc_attr( $check['second'] ) . ';color:' . esc_attr( $check['first'] ) . ';padding:0.15rem 0.6rem;border-radius:6px;display:inline-block;border:1px solid #ccc">نمونه</span></td>';
		echo '<td><code data-contrast-ratio>' . esc_html( number_format_i18n( $check['ratio'], 2 ) ) . '</code></td>';
		echo '<td>' . esc_html( number_format_i18n( $check['minimum'], 1 ) ) . '</td>';
		echo '<td data-contrast-status>' . ( $ok ? '✅' : '❌' ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * @return void
 */
function render_analytics_section() {
	echo '<p>' . esc_html__( 'تحلیل فروش فقط از داده‌های داخلی WooCommerce ساخته می‌شود و هیچ سرویس یا API خارجی ندارد. برای جلوگیری از پردازش اضافه، این بخش پیش‌فرض خاموش است.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_session_alerts_section() {
	echo '<p>' . esc_html__( 'هشدار نشست جدید کدام مقصد را دارد و چه چیزی گزارش می‌شود؟ این هشدار اختیاری است و می‌توانید آن را خاموش کنید.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_session_alerts_enabled_field() {
	$settings = get_settings();
	$name     = OPTION_KEY . '[session_alerts_enabled]';

	echo '<label for="fandoogh-manager-session-alerts-enabled">';
	echo '<input type="checkbox" id="fandoogh-manager-session-alerts-enabled" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $settings['session_alerts_enabled'] ), true, false ) . '>';
	echo ' ' . esc_html__( 'ارسال ایمیل به مدیر هنگام هر جفت‌سازی موفق و ساخت نشست جدید', 'fandoogh-manager' );
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'با هر اتصال دستگاه جدید، ایمیلی شامل نام کاربر، برچسب دستگاه و زمان اتصال ارسال می‌شود. نشست‌های جدید در بخش «امنیت و دستگاه‌ها» وب‌اپ هم مشخص می‌شوند.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_session_alert_email_field() {
	$settings = get_settings();
	$name     = OPTION_KEY . '[session_alert_email]';

	echo '<input type="email" class="regular-text" id="fandoogh-manager-session-alert-email" name="' . esc_attr( $name ) . '" value="' . esc_attr( $settings['session_alert_email'] ) . '" placeholder="' . esc_attr__( 'خالی = ایمیل مدیریت سایت', 'fandoogh-manager' ) . '">';
	echo '<p class="description">' . esc_html__( 'اگر خالی بماند، هشدار به ایمیل مدیریت سایت (Administration Email Address) ارسال می‌شود.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_analytics_enabled_field() {
	$settings = get_settings();
	$name     = OPTION_KEY . '[analytics_enabled]';

	echo '<label for="fandoogh-manager-analytics-enabled">';
	echo '<input type="checkbox" id="fandoogh-manager-analytics-enabled" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $settings['analytics_enabled'] ), true, false ) . '>';
	echo ' ' . esc_html__( 'نمایش داشبورد فروش و نمودار وضعیت سفارش‌ها در وب‌اپ', 'fandoogh-manager' );
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'دسترسی همچنان با Session، scope analytics.read و capability واقعی WooCommerce کنترل می‌شود.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_image_section() {
	echo '<p>' . esc_html__( 'تصاویر مجاز از مسیر امن Media با این حدود به WebP تبدیل می‌شوند. اجرای واقعی به پشتیبانی GD یا Imagick و مجوز upload_files نیاز دارد.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @return void
 */
function render_fonts_section() {
	echo '<p>' . esc_html__( 'فونت‌های محلی فقط با فرمت‌های woff، woff2، ttf یا otf پذیرفته می‌شوند و در مسیر uploads هم‌مبدأ ذخیره خواهند شد. CSS و JavaScript قابل آپلود نیستند.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @param array<string, string> $args Field arguments.
 * @return void
 */
function render_color_field( $args ) {
	$key = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
	$settings = get_settings();
	$value = isset( $settings['colors'][ $key ] ) ? $settings['colors'][ $key ] : '#000000';
	$name = OPTION_KEY . '[colors][' . $key . ']';

	echo '<input type="color" id="fandoogh-manager-color-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	echo ' <code>' . esc_html( $value ) . '</code>';
}

/**
 * @return void
 */
function render_slug_field() {
	$settings = get_settings();
	echo '<input type="text" class="regular-text" id="fandoogh-manager-slug" name="' . esc_attr( OPTION_KEY . '[slug]' ) . '" value="' . esc_attr( $settings['slug'] ) . '" pattern="[a-z0-9-]+" maxlength="50">';
	echo '<p class="description">' . esc_html__( 'این نامک فقط یک نام مستعار است؛ مسیر پایدار /manager/ همچنان در دسترس است.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @param array<string, string> $args Field arguments.
 * @return void
 */
function render_number_field( $args ) {
	$key = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
	$settings = get_settings();
	$value = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
	$name = OPTION_KEY . '[' . $key . ']';

	echo '<input type="number" class="small-text" id="fandoogh-manager-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="1">';
}

/**
 * @return void
 */
function render_keep_original_field() {
	$settings = get_settings();
	$name = OPTION_KEY . '[keep_original]';

	echo '<label for="fandoogh-manager-keep-original">';
	echo '<input type="checkbox" id="fandoogh-manager-keep-original" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $settings['keep_original'] ), true, false ) . '>';
	echo ' ' . esc_html__( 'نسخهٔ اصلی تصویر در کنار نسخهٔ پردازش‌شده حفظ شود.', 'fandoogh-manager' );
	echo '</label>';
}

/**
 * @return void
 */
function render_fonts_field() {
	$settings = get_settings();
	$fonts = array_values( $settings['local_fonts'] );

	if ( empty( $fonts ) ) {
		echo '<p class="description">' . esc_html__( 'هنوز فونت محلی اضافه نشده است.', 'fandoogh-manager' ) . '</p>';
		return;
	}

	echo '<ul>';
	foreach ( $fonts as $font_path ) {
		echo '<input type="hidden" name="' . esc_attr( OPTION_KEY . '[local_fonts][]' ) . '" value="' . esc_attr( $font_path ) . '">';
		echo '<li><code>' . esc_html( $font_path ) . '</code></li>';
	}
	echo '</ul>';
	echo '<p class="description">' . esc_html__( 'فونت‌های فهرست‌شده با خانوادهٔ FandooghLocal در پوستهٔ PWA استفاده می‌شوند.', 'fandoogh-manager' ) . '</p>';
}

/**
 * Load the same local design language on the two plugin pages in wp-admin.
 * No remote stylesheet or icon library is used.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function enqueue_admin_assets( $hook_suffix ) {
	$allowed_hooks = array(
		'toplevel_page_fandoogh-manager',
		'fandoogh-manager_page_fandoogh-manager-settings',
	);

	if ( ! in_array( $hook_suffix, $allowed_hooks, true ) ) {
		return;
	}

	$handle = 'fandoogh-manager-admin';
	wp_enqueue_style(
		$handle,
		plugins_url( 'assets/css/admin.css', __FILE__ ),
		array(),
		FANDOOGH_MANAGER_VERSION
	);

	$colors = get_settings()['colors'];
	$inline = ':root{--fandoogh-primary:' . esc_attr( $colors['primary'] ) . ';--fandoogh-secondary:' . esc_attr( $colors['secondary'] ) . ';--fandoogh-accent:' . esc_attr( $colors['accent'] ) . ';--fandoogh-bg:' . esc_attr( $colors['background'] ) . ';--fandoogh-text:' . esc_attr( $colors['text'] ) . ';}';
	wp_add_inline_style( $handle, $inline );

	if ( 'fandoogh-manager_page_fandoogh-manager-settings' === $hook_suffix ) {
		wp_enqueue_script(
			'fandoogh-manager-contrast',
			plugins_url( 'assets/js/contrast-validator.js', __FILE__ ),
			array(),
			FANDOOGH_MANAGER_VERSION,
			true
		);
	}
}

/**
 * @return void
 */
function register_admin_menu() {
	add_menu_page(
		__( 'Fandoogh Manager', 'fandoogh-manager' ),
		__( 'Fandoogh Manager', 'fandoogh-manager' ),
		'manage_options',
		'fandoogh-manager',
		__NAMESPACE__ . '\\render_status_page',
		'dashicons-admin-generic',
		58
	);

	add_submenu_page(
		'fandoogh-manager',
		__( 'تنظیمات Fandoogh Manager', 'fandoogh-manager' ),
		__( 'تنظیمات', 'fandoogh-manager' ),
		'manage_options',
		'fandoogh-manager-settings',
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/**
 * @return void
 */
function render_status_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'شما اجازهٔ مشاهدهٔ این صفحه را ندارید.', 'fandoogh-manager' ) );
	}

	$app_shell_label = __( 'نشانی وب‌اپ', 'fandoogh-manager' );
	$health = array(
		__( 'نسخهٔ افزونه', 'fandoogh-manager' )      => FANDOOGH_MANAGER_VERSION,
		__( 'نسخهٔ وردپرس', 'fandoogh-manager' )      => public_wordpress_version(),
		__( 'وضعیت WooCommerce', 'fandoogh-manager' ) => is_woocommerce_active() ? __( 'بله', 'fandoogh-manager' ) : __( 'خیر — حالت محدود بدون ووکامرس', 'fandoogh-manager' ),
		__( 'پشتیبانی WebP', 'fandoogh-manager' )     => webp_support() ? __( 'بله', 'fandoogh-manager' ) : __( 'خیر', 'fandoogh-manager' ),
		$app_shell_label                               => app_base_url(),
	);

	echo '<div class="wrap fandoogh-manager-admin-page fandoogh-manager-status-page">';
	echo '<div class="fandoogh-admin-hero"><div><p class="fandoogh-eyebrow">' . esc_html__( 'مرکز مدیریت', 'fandoogh-manager' ) . '</p><h1>' . esc_html__( 'وضعیت Fandoogh Manager', 'fandoogh-manager' ) . '</h1><p class="fandoogh-admin-lead">' . esc_html__( 'وضعیت اتصال وب‌اپ و قابلیت‌های محلی فروشگاه را در یک نگاه بررسی کنید.', 'fandoogh-manager' ) . '</p></div><span class="fandoogh-admin-mark">ف</span></div>';
	echo '<div class="fandoogh-admin-card">';
	echo '<table class="widefat striped"><tbody>';

	foreach ( $health as $label => $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		if ( $app_shell_label === $label ) {
			echo '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
		} else {
			echo esc_html( $value );
		}
		echo '</td></tr>';
	}

	echo '</tbody></table>';		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=fandoogh-manager-settings' ) ) . '">' . esc_html__( 'باز کردن تنظیمات', 'fandoogh-manager' ) . '</a></p>';
	echo '</div>';

	$security_rows = admin_security_session_rows();
	$active_count  = 0;
	$active_users  = array();
	foreach ( $security_rows as $security_row ) {
		if ( empty( $security_row['connected'] ) ) {
			continue;
		}
		$active_count++;
		$active_users[ absint( $security_row['user_id'] ) ] = true;
	}

	echo '<section class="fandoogh-admin-card fandoogh-security-overview" aria-labelledby="fandoogh-security-overview-title">';
	echo '<div class="fandoogh-section-heading"><div><p class="fandoogh-eyebrow">' . esc_html__( 'امنیت و دسترسی', 'fandoogh-manager' ) . '</p><h2 id="fandoogh-security-overview-title">' . esc_html__( 'اتصال‌های فعلی وب‌اپ', 'fandoogh-manager' ) . '</h2></div><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=fandoogh-manager-settings#fandoogh-access-management' ) ) . '">' . esc_html__( 'مدیریت نشست‌ها و کاربران', 'fandoogh-manager' ) . '</a></div>';
	echo '<div class="fandoogh-security-metrics">';
	echo '<div class="fandoogh-security-metric"><strong>' . esc_html( number_format_i18n( $active_count ) ) . '</strong><span>' . esc_html__( 'دستگاه متصل', 'fandoogh-manager' ) . '</span></div>';
	echo '<div class="fandoogh-security-metric"><strong>' . esc_html( number_format_i18n( count( $active_users ) ) ) . '</strong><span>' . esc_html__( 'کاربر فعال', 'fandoogh-manager' ) . '</span></div>';
	echo '<div class="fandoogh-security-metric"><strong>' . esc_html( number_format_i18n( count( $security_rows ) ) ) . '</strong><span>' . esc_html__( 'نشست ثبت‌شده', 'fandoogh-manager' ) . '</span></div>';
	echo '</div>';
	if ( 0 === $active_count ) {
		echo '<p class="fandoogh-empty-state">' . esc_html__( 'در حال حاضر دستگاه متصل فعالی ثبت نشده است.', 'fandoogh-manager' ) . '</p>';
	}
	echo '</section>';
	echo '</div>';
}

/**
 * @return void
 */
function render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'شما اجازهٔ مشاهدهٔ این صفحه را ندارید.', 'fandoogh-manager' ) );
	}

	$pairing_code = maybe_handle_pairing_admin_post();
	$font_action   = maybe_handle_font_admin_post();
	$access_action = maybe_handle_access_admin_post();

	echo '<div class="wrap fandoogh-manager-admin-page fandoogh-manager-settings-page">';
	echo '<div class="fandoogh-admin-hero"><div><p class="fandoogh-eyebrow">' . esc_html__( 'تنظیمات بستر', 'fandoogh-manager' ) . '</p><h1>' . esc_html__( 'تنظیمات Fandoogh Manager', 'fandoogh-manager' ) . '</h1><p class="fandoogh-admin-lead">' . esc_html__( 'رنگ، فونت، رسانه، تحلیل و اتصال امن وب‌اپ را از همین صفحه مدیریت کنید.', 'fandoogh-manager' ) . '</p></div><span class="fandoogh-admin-mark">ف</span></div>';
	settings_errors();
	echo '<section class="fandoogh-admin-card fandoogh-settings-card"><form method="post" action="options.php">';
	settings_fields( OPTION_GROUP );
	do_settings_sections( 'fandoogh-manager-settings' );
	submit_button();
	echo '</form></section>';
	render_pairing_admin_section( $pairing_code );
	render_font_admin_section( $font_action );
	render_access_admin_section( $access_action );
	echo '</div>';
}

/**
 * Convert a UTC MySQL timestamp into a compact Persian Jalali label for the
 * server-rendered administrator view. The app itself has a richer date
 * picker; this small local formatter keeps the admin security surface free
 * from a third-party date dependency as well.
 *
 * @param mixed $value UTC MySQL timestamp.
 * @return string
 */
function admin_security_date_label( $value ) {
	$timestamp = strtotime( (string) $value . ' UTC' );
	if ( false === $timestamp ) {
		return '—';
	}

	$date = new \DateTimeImmutable( '@' . $timestamp );
	if ( function_exists( 'wp_timezone' ) ) {
		$date = $date->setTimezone( wp_timezone() );
	}

	$gregorian_month_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy = (int) $date->format( 'Y' );
	$gm = (int) $date->format( 'n' );
	$gd = (int) $date->format( 'j' );
	$jy = $gy > 1600 ? 979 : 0;
	$gy -= $gy > 1600 ? 1600 : 621;
	$gy2 = $gm > 2 ? $gy + 1 : $gy;
	$days = 365 * $gy + (int) floor( ( $gy2 + 3 ) / 4 ) - (int) floor( ( $gy2 + 99 ) / 100 ) + (int) floor( ( $gy2 + 399 ) / 400 ) - 80 + $gd + $gregorian_month_days[ $gm - 1 ];
	$jy += 33 * (int) floor( $days / 12053 );
	$days %= 12053;
	$jy += 4 * (int) floor( $days / 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy += (int) floor( ( $days - 1 ) / 365 );
		$days = ( $days - 1 ) % 365;
	}
	$jm = $days < 186 ? 1 + (int) floor( $days / 31 ) : 7 + (int) floor( ( $days - 186 ) / 30 );
	$jd = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 );
	$label = sprintf( '%04d/%02d/%02d - %s:%s', $jy, $jm, $jd, $date->format( 'H' ), $date->format( 'i' ) );

	return strtr(
		$label,
		array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' )
	);
}

/**
 * Handle the protected administrator forms for access policies and sessions.
 * Every mutation has its own nonce and is bounded to manage_options.
 *
 * @return array<string, string>|string
 */
function maybe_handle_access_admin_post() {
	if ( 'POST' !== strtoupper( (string) ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) ) {
		return '';
	}

	$action = isset( $_POST['fandoogh_manager_access_action'] ) ? sanitize_key( wp_unslash( $_POST['fandoogh_manager_access_action'] ) ) : '';
	$allowed_actions = array( 'save_policy', 'revoke_session', 'revoke_user_sessions', 'remove_access', 'restore_access' );
	if ( ! in_array( $action, $allowed_actions, true ) ) {
		return '';
	}

	check_admin_referer( 'fandoogh_manager_access_' . $action, 'fandoogh_manager_access_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return array( 'type' => 'error', 'message' => __( 'برای مدیریت نشست‌ها و دسترسی کاربران مجوز کافی ندارید.', 'fandoogh-manager' ) );
	}

	$user_id = isset( $_POST['fandoogh_manager_access_user_id'] ) ? absint( wp_unslash( $_POST['fandoogh_manager_access_user_id'] ) ) : 0;
	if ( 'revoke_session' === $action ) {
		$session_id = isset( $_POST['fandoogh_manager_access_session_id'] ) ? absint( wp_unslash( $_POST['fandoogh_manager_access_session_id'] ) ) : 0;
		$result     = admin_revoke_session( $session_id );
		if ( is_wp_error( $result ) ) {
			return array( 'type' => 'error', 'message' => $result->get_error_message() );
		}
		return array( 'type' => 'success', 'message' => false === $result ? __( 'این نشست قبلاً غیرفعال شده است.', 'fandoogh-manager' ) : __( 'نشست انتخاب‌شده با موفقیت حذف شد.', 'fandoogh-manager' ) );
	}

	if ( 'revoke_user_sessions' === $action ) {
		$result = admin_revoke_user_sessions( $user_id );
		if ( is_wp_error( $result ) ) {
			return array( 'type' => 'error', 'message' => $result->get_error_message() );
		}
		return array( 'type' => 'success', 'message' => sprintf( __( '%s نشست از دستگاه‌های این کاربر حذف شد.', 'fandoogh-manager' ), number_format_i18n( $result ) ) );
	}

	if ( 'remove_access' === $action || 'restore_access' === $action ) {
		$result = admin_set_user_access( $user_id, 'restore_access' === $action );
		if ( is_wp_error( $result ) ) {
			return array( 'type' => 'error', 'message' => $result->get_error_message() );
		}
		return array( 'type' => 'success', 'message' => 'restore_access' === $action ? __( 'دسترسی وب‌اپ کاربر دوباره فعال شد؛ برای اتصال جدید کد جفت‌سازی بسازید.', 'fandoogh-manager' ) : __( 'دسترسی وب‌اپ کاربر حذف شد و کدهای جفت‌سازی و نشست‌های او باطل شدند.', 'fandoogh-manager' ) );
	}

	$user = $user_id ? get_user_by( 'id', $user_id ) : false;
	if ( ! $user || user_access_policy_is_protected( $user_id ) ) {
		return array( 'type' => 'error', 'message' => __( 'این کاربر برای ویرایش سیاست دسترسی قابل انتخاب نیست.', 'fandoogh-manager' ) );
	}

	$policy = update_user_access_policy(
		$user_id,
		array(
			'enabled'        => true,
			'product_create' => ! empty( $_POST['fandoogh_access_product_create'] ),
			'product_edit'   => ! empty( $_POST['fandoogh_access_product_edit'] ),
			'analytics_read' => ! empty( $_POST['fandoogh_access_analytics_read'] ),
			'major_changes'  => ! empty( $_POST['fandoogh_access_major_changes'] ),
		)
	);
	$revoked_count = admin_revoke_user_sessions( $user_id, 'access_policy_updated' );
	if ( is_wp_error( $revoked_count ) ) {
		return array( 'type' => 'error', 'message' => $revoked_count->get_error_message() );
	}
	record_audit_event( 'access_policy_updated', get_current_user_id(), 0, 'wp-admin', 'user', $user_id, array( 'outcome' => 'permissions_updated', 'count' => $revoked_count, 'target_user_id' => $user_id ) );

	return array( 'type' => 'success', 'message' => sprintf( __( 'محدودیت‌های دسترسی ذخیره شد و %s نشست برای اعمال فوری تنظیمات باطل شد.', 'fandoogh-manager' ), number_format_i18n( $revoked_count ) ) );
}

/**
 * Render cross-user access policies and device sessions for administrators.
 *
 * @param array<string, string>|string $action_result Result from the POST handler.
 * @return void
 */
function render_access_admin_section( $action_result = '' ) {
	$session_rows = admin_security_session_rows();
	$users        = admin_security_users( $session_rows );
	$active_count = 0;
	foreach ( $session_rows as $session_row ) {
		if ( ! empty( $session_row['connected'] ) ) {
			$active_count++;
		}
	}

	echo '<section class="fandoogh-admin-card fandoogh-access-management" id="fandoogh-access-management" aria-labelledby="fandoogh-access-title">';
	echo '<div class="fandoogh-section-heading"><div><p class="fandoogh-eyebrow">' . esc_html__( 'کنترل دسترسی', 'fandoogh-manager' ) . '</p><h2 id="fandoogh-access-title">' . esc_html__( 'کاربران، دستگاه‌ها و نشست‌ها', 'fandoogh-manager' ) . '</h2></div><span class="fandoogh-status-pill fandoogh-status-pill--active">' . esc_html( number_format_i18n( $active_count ) . ' ' . __( 'دستگاه متصل', 'fandoogh-manager' ) ) . '</span></div>';
    echo '<p>' . esc_html__( 'در این بخش می‌توانید ببینید هر کاربر از چه دستگاه‌هایی متصل است، نشست‌ها را جداگانه یا یکجا حذف کنید و دسترسی‌های حساس وب‌اپ را محدود کنید. administrator اصلی برای جلوگیری از قفل‌شدن پنل، پروفایل محافظت‌شده دارد.', 'fandoogh-manager' ) . '</p>';

	if ( is_array( $action_result ) && ! empty( $action_result['message'] ) ) {
		$notice_class = 'error' === ( isset( $action_result['type'] ) ? $action_result['type'] : '' ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline" role="status"><p>' . esc_html( $action_result['message'] ) . '</p></div>';
	}

	echo '<div class="fandoogh-access-policy-list">';
	if ( empty( $users ) ) {
		echo '<p class="fandoogh-empty-state">' . esc_html__( 'کاربر مدیری برای اتصال به وب‌اپ پیدا نشد.', 'fandoogh-manager' ) . '</p>';
	} else {
		foreach ( $users as $user ) {
			$policy         = $user['policy'];
			$user_id        = absint( $user['id'] );
			$protected       = ! empty( $user['protected'] );
			$has_access      = ! empty( $policy['enabled'] ) && ! empty( $user['capable'] );
			$identity        = '' !== $user['user_name'] ? $user['user_name'] : $user['user_login'];
			echo '<article class="fandoogh-access-user-card">';
			echo '<div class="fandoogh-access-user-heading"><div><h3>' . esc_html( $identity ) . '</h3><span>@' . esc_html( $user['user_login'] ) . '</span></div><div class="fandoogh-access-user-stats"><span>' . esc_html( number_format_i18n( $user['connected_count'] ) ) . ' ' . esc_html__( 'متصل', 'fandoogh-manager' ) . '</span><span>' . esc_html( number_format_i18n( $user['session_count'] ) ) . ' ' . esc_html__( 'نشست', 'fandoogh-manager' ) . '</span></div></div>';
			if ( ! $user['capable'] ) {
				echo '<p class="fandoogh-access-warning">' . esc_html__( 'این حساب در WordPress مجوز مدیریت محصول/فروشگاه ندارد و فعلاً واجد شرایط جفت‌سازی نیست.', 'fandoogh-manager' ) . '</p>';
			}
			if ( $protected ) {
				echo '<p class="fandoogh-access-protected">' . esc_html__( 'دسترسی کامل administrator اصلی محافظت‌شده است.', 'fandoogh-manager' ) . '</p>';
			} else {
				echo '<form method="post" class="fandoogh-access-policy-form" onsubmit="return window.confirm(\'' . esc_js( __( 'با ذخیرهٔ محدودیت‌ها، نشست‌های فعلی این کاربر برای اعمال فوری تنظیمات حذف می‌شوند. ادامه می‌دهید؟', 'fandoogh-manager' ) ) . '\');">';
				wp_nonce_field( 'fandoogh_manager_access_save_policy', 'fandoogh_manager_access_nonce' );
				echo '<input type="hidden" name="fandoogh_manager_access_action" value="save_policy"><input type="hidden" name="fandoogh_manager_access_user_id" value="' . esc_attr( $user_id ) . '">';
				echo '<fieldset><legend>' . esc_html__( 'مجوزهای وب‌اپ', 'fandoogh-manager' ) . '</legend><label><input type="checkbox" name="fandoogh_access_product_create" value="1" ' . checked( ! empty( $policy['product_create'] ), true, false ) . '> ' . esc_html__( 'معرفی محصول', 'fandoogh-manager' ) . '</label><label><input type="checkbox" name="fandoogh_access_product_edit" value="1" ' . checked( ! empty( $policy['product_edit'] ), true, false ) . '> ' . esc_html__( 'اصلاح محصول', 'fandoogh-manager' ) . '</label><label><input type="checkbox" name="fandoogh_access_analytics_read" value="1" ' . checked( ! empty( $policy['analytics_read'] ), true, false ) . '> ' . esc_html__( 'نمایش گردش مالی و تحلیل‌ها', 'fandoogh-manager' ) . '</label><label><input type="checkbox" name="fandoogh_access_major_changes" value="1" ' . checked( ! empty( $policy['major_changes'] ), true, false ) . '> ' . esc_html__( 'تغییرات اساسی و عملیات حساس', 'fandoogh-manager' ) . '</label></fieldset>';
				echo '<div class="fandoogh-access-policy-actions"><button class="button button-primary" type="submit">' . esc_html__( 'ذخیرهٔ محدودیت‌ها', 'fandoogh-manager' ) . '</button></div></form>';
				echo '<div class="fandoogh-access-danger-actions">';
				if ( $has_access ) {
					echo '<form method="post" onsubmit="return window.confirm(\'' . esc_js( __( 'دسترسی وب‌اپ، کدهای جفت‌سازی و نشست‌های این کاربر حذف می‌شوند. ادامه می‌دهید؟', 'fandoogh-manager' ) ) . '\');">';
					wp_nonce_field( 'fandoogh_manager_access_remove_access', 'fandoogh_manager_access_nonce' );
					echo '<input type="hidden" name="fandoogh_manager_access_action" value="remove_access"><input type="hidden" name="fandoogh_manager_access_user_id" value="' . esc_attr( $user_id ) . '"><button class="button button-secondary fandoogh-danger-button" type="submit">' . esc_html__( 'حذف دسترسی وب‌اپ', 'fandoogh-manager' ) . '</button></form>';
				} else {
					echo '<form method="post">';
					wp_nonce_field( 'fandoogh_manager_access_restore_access', 'fandoogh_manager_access_nonce' );
					echo '<input type="hidden" name="fandoogh_manager_access_action" value="restore_access"><input type="hidden" name="fandoogh_manager_access_user_id" value="' . esc_attr( $user_id ) . '"><button class="button button-secondary" type="submit">' . esc_html__( 'فعال‌سازی دسترسی وب‌اپ', 'fandoogh-manager' ) . '</button></form>';
				}
				echo '<form method="post" onsubmit="return window.confirm(\'' . esc_js( __( 'همهٔ نشست‌های این کاربر از همهٔ دستگاه‌ها حذف می‌شوند. ادامه می‌دهید؟', 'fandoogh-manager' ) ) . '\');">';
				wp_nonce_field( 'fandoogh_manager_access_revoke_user_sessions', 'fandoogh_manager_access_nonce' );
				echo '<input type="hidden" name="fandoogh_manager_access_action" value="revoke_user_sessions"><input type="hidden" name="fandoogh_manager_access_user_id" value="' . esc_attr( $user_id ) . '"><button class="button button-secondary" type="submit">' . esc_html__( 'حذف همهٔ نشست‌ها', 'fandoogh-manager' ) . '</button></form></div>';
			}
			echo '</article>';
		}
	}
	echo '</div>';

	echo '<div class="fandoogh-session-list"><div class="fandoogh-section-heading"><div><h3>' . esc_html__( 'فهرست دستگاه‌های متصل و نشست‌ها', 'fandoogh-manager' ) . '</h3><p>' . esc_html__( 'نشست‌های فعال اول نمایش داده می‌شوند؛ حذف یک نشست، دسترسی همان دستگاه را فوراً قطع می‌کند.', 'fandoogh-manager' ) . '</p></div></div>';
	if ( empty( $session_rows ) ) {
		echo '<p class="fandoogh-empty-state">' . esc_html__( 'هنوز هیچ نشست مدیریتی ثبت نشده است.', 'fandoogh-manager' ) . '</p>';
	} else {
		echo '<div class="fandoogh-admin-table-wrap"><table class="widefat striped fandoogh-session-table"><caption class="screen-reader-text">' . esc_html__( 'نشست‌های کاربران و دستگاه‌های متصل', 'fandoogh-manager' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'کاربر', 'fandoogh-manager' ) . '</th><th scope="col">' . esc_html__( 'دستگاه', 'fandoogh-manager' ) . '</th><th scope="col">' . esc_html__( 'وضعیت', 'fandoogh-manager' ) . '</th><th scope="col">' . esc_html__( 'آخرین فعالیت', 'fandoogh-manager' ) . '</th><th scope="col">' . esc_html__( 'عملیات', 'fandoogh-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $session_rows as $session ) {
			$status_label = ! empty( $session['connected'] ) ? __( 'متصل', 'fandoogh-manager' ) : ( 'revoked' === $session['status'] ? __( 'حذف‌شده', 'fandoogh-manager' ) : __( 'منقضی‌شده', 'fandoogh-manager' ) );
			$status_class = ! empty( $session['connected'] ) ? 'active' : 'inactive';
			echo '<tr><td><strong>' . esc_html( '' !== $session['user_name'] ? $session['user_name'] : $session['user_login'] ) . '</strong><small>@' . esc_html( $session['user_login'] ) . '</small></td><td>' . esc_html( '' !== $session['device_label'] ? $session['device_label'] : __( 'مرورگر ناشناس', 'fandoogh-manager' ) ) . '</td><td><span class="fandoogh-status-pill fandoogh-status-pill--' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td><td><time datetime="' . esc_attr( $session['last_seen_at'] ) . '">' . esc_html( admin_security_date_label( $session['last_seen_at'] ) ) . '</time></td><td>';
			if ( ! empty( $session['connected'] ) ) {
				echo '<form method="post" onsubmit="return window.confirm(\'' . esc_js( __( 'دسترسی این دستگاه فوراً قطع می‌شود. ادامه می‌دهید؟', 'fandoogh-manager' ) ) . '\');">';
				wp_nonce_field( 'fandoogh_manager_access_revoke_session', 'fandoogh_manager_access_nonce' );
				echo '<input type="hidden" name="fandoogh_manager_access_action" value="revoke_session"><input type="hidden" name="fandoogh_manager_access_session_id" value="' . esc_attr( $session['id'] ) . '"><button class="button button-secondary fandoogh-danger-button" type="submit">' . esc_html__( 'حذف نشست', 'fandoogh-manager' ) . '</button></form>';
			} else {
				echo '<span class="description">' . esc_html__( 'بدون عملیات', 'fandoogh-manager' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</div></section>';
}

/**
 * @return void
 */
function maybe_render_woocommerce_notice() {
	if ( ! current_user_can( 'manage_options' ) || is_woocommerce_active() ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce فعال نیست؛ Fandoogh Manager در حالت محدود کار می‌کند و برای پشتیبان فعلی به API ووکامرس نیازی ندارد.', 'fandoogh-manager' ) . '</p></div>';
}
