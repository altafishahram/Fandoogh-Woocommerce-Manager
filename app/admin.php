<?php
/** Administrator navigation and isolated Settings API forms. No public routes. */
namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Each editable tab owns an explicit set of option keys and registered sections. */
function admin_tabs() {
	return array(
		'overview' => array( 'label' => __( 'پیشخوان', 'fandoogh-manager' ), 'icon' => 'dashboard' ),
		'appearance' => array( 'label' => __( 'ظاهر', 'fandoogh-manager' ), 'icon' => 'art', 'keys' => array( 'colors' ), 'sections' => array( 'theme' ) ),
		'media' => array( 'label' => __( 'رسانه و فونت', 'fandoogh-manager' ), 'icon' => 'format-image', 'keys' => array( 'image_max_width', 'image_max_height', 'image_quality', 'image_max_bytes', 'keep_original', 'local_fonts' ), 'sections' => array( 'image', 'fonts' ) ),
		'analytics' => array( 'label' => __( 'گزارش مالی', 'fandoogh-manager' ), 'icon' => 'chart-bar', 'keys' => array( 'analytics_enabled' ), 'sections' => array( 'analytics' ) ),
		'access' => array( 'label' => __( 'دسترسی کاربران', 'fandoogh-manager' ), 'icon' => 'groups' ),
		'sessions' => array( 'label' => __( 'نشست‌ها و امنیت', 'fandoogh-manager' ), 'icon' => 'shield', 'keys' => array( 'session_alerts_enabled', 'session_alert_email' ), 'sections' => array( 'session_alerts' ) ),
		'connection' => array( 'label' => __( 'اتصال و وب‌اپ', 'fandoogh-manager' ), 'icon' => 'smartphone', 'keys' => array( 'slug' ), 'sections' => array( 'connection' ) ),
		'tracking' => array( 'label' => __( 'رهگیری سفارش', 'fandoogh-manager' ), 'icon' => 'location', 'keys' => array( 'order_tracking' ), 'sections' => array( 'order_tracking' ) ),
	);
}

/** Merge only the submitted tab into the latest stored settings before validation.
 * options.php remains responsible for capability and nonce checks. The marker
 * is never stored. Non-admin programmatic updates retain complete-option semantics.
 */
function sanitize_admin_settings( $raw ) {
	if ( ! is_array( $raw ) || ! array_key_exists( '_tab', $raw ) ) {
		return sanitize_settings( $raw );
	}
	$tabs = admin_tabs();
	$tab = is_string( $raw['_tab'] ) ? $raw['_tab'] : '';
	$previous = get_settings();
	if ( empty( $tabs[ $tab ]['keys'] ) ) {
		add_settings_error( OPTION_KEY, 'fandoogh_invalid_tab', __( 'بخش تنظیمات معتبر نیست؛ هیچ تغییری ذخیره نشد.', 'fandoogh-manager' ), 'error' );
		return $previous;
	}
	$checkboxes = array( 'analytics_enabled', 'keep_original', 'session_alerts_enabled' );
	foreach ( $tabs[ $tab ]['keys'] as $key ) {
		if ( array_key_exists( $key, $raw ) ) {
			$previous[ $key ] = $raw[ $key ];
		} elseif ( in_array( $key, $checkboxes, true ) ) {
			$previous[ $key ] = false;
		}
	}
	return sanitize_settings( $previous );
}

function admin_active_tab( $fallback = 'overview' ) {
	$tabs = admin_tabs();
	$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $fallback;
	return isset( $tabs[ $tab ] ) ? $tab : 'overview';
}

function admin_tab_url( $tab ) {
	return add_query_arg( array( 'page' => 'fandoogh-manager', 'tab' => $tab ), admin_url( 'admin.php' ) );
}

/** Native link navigation works without JavaScript; every destination is allowlisted. */
function render_admin_page( $fallback = 'overview' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'شما اجازهٔ مشاهدهٔ این صفحه را ندارید.', 'fandoogh-manager' ) );
		return;
	}
	$tabs = admin_tabs();
	$tab = admin_active_tab( $fallback );
	$pairing = 'connection' === $tab ? maybe_handle_pairing_admin_post() : '';
	$font = 'media' === $tab ? maybe_handle_font_admin_post() : '';
	$access = in_array( $tab, array( 'access', 'sessions' ), true ) ? maybe_handle_access_admin_post() : '';
	echo '<div class="wrap fandoogh-manager-admin-page fandoogh-manager-settings-page" dir="rtl">';
	echo '<header class="fandoogh-admin-hero"><div><p class="fandoogh-eyebrow">' . esc_html__( 'مرکز مدیریت فروشگاه', 'fandoogh-manager' ) . '</p><h1>Fandoogh Manager</h1><p class="fandoogh-admin-lead">' . esc_html__( 'تنظیمات، دسترسی‌ها و قابلیت‌های وب‌اپ؛ همه در یک پیشخوان.', 'fandoogh-manager' ) . '</p><span class="fandoogh-admin-version">' . esc_html( 'v' . VERSION ) . '</span></div><a class="button button-primary fandoogh-admin-button" href="' . esc_url( app_base_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'باز کردن وب‌اپ ↗', 'fandoogh-manager' ) . '</a></header>';
	echo '<nav class="fandoogh-admin-tabs" aria-label="' . esc_attr__( 'بخش‌های مدیریت افزونه', 'fandoogh-manager' ) . '">';
	foreach ( $tabs as $key => $definition ) {
		echo '<a class="fandoogh-admin-tab" href="' . esc_url( admin_tab_url( $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '><span class="dashicons dashicons-' . esc_attr( $definition['icon'] ) . '" aria-hidden="true"></span>' . esc_html( $definition['label'] ) . '</a>';
	}
	echo '</nav><div class="fandoogh-admin-content" data-admin-tab="' . esc_attr( $tab ) . '"><h2 class="fandoogh-admin-page-title">' . esc_html( $tabs[ $tab ]['label'] ) . '</h2>';
	settings_errors( OPTION_KEY );
	if ( 'overview' === $tab ) {
		render_admin_feature_overview();
		render_admin_overview();
	}
	if ( 'analytics' === $tab ) {
		render_admin_analytics_help();
	}
	if ( ! empty( $tabs[ $tab ]['sections'] ) ) {
		render_admin_settings_form( $tab );
	}
	if ( 'media' === $tab ) { render_font_admin_section( $font ); }
	if ( 'connection' === $tab ) {
		render_pairing_admin_section( $pairing );
		echo '<section class="fandoogh-admin-card"><h2>' . esc_html__( 'نصب و قابلیت‌های وب‌اپ', 'fandoogh-manager' ) . '</h2><p>' . esc_html__( 'دکمهٔ نصب در مرورگرهای سازگار نمایش داده می‌شود؛ در iOS راهنمای افزودن به صفحهٔ اصلی در دسترس است.', 'fandoogh-manager' ) . '</p><p class="fandoogh-admin-alert fandoogh-admin-alert--info">' . esc_html__( 'چاپ و اشتراک‌گذاری تصویر / PDF: به‌زودی. این قابلیت‌ها هنوز فعال نیستند.', 'fandoogh-manager' ) . '</p></section>';
	}
	if ( in_array( $tab, array( 'access', 'sessions' ), true ) ) { render_access_admin_section( $access, $tab ); }
	echo '</div></div>';
}

/** Keep Settings API callbacks/escaping; render only this tab's registered fields. */
function render_admin_settings_form( $tab ) {
	global $wp_settings_sections;
	$definition = admin_tabs()[ $tab ];
	echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="fandoogh-admin-settings-form">';
	settings_fields( OPTION_GROUP );
	echo '<input type="hidden" name="' . esc_attr( OPTION_KEY ) . '[_tab]" value="' . esc_attr( $tab ) . '">';
	foreach ( $definition['sections'] as $name ) {
		$id = 'fandoogh_manager_' . $name . '_section';
		$section = isset( $wp_settings_sections['fandoogh-manager-settings'][ $id ] ) ? $wp_settings_sections['fandoogh-manager-settings'][ $id ] : null;
		if ( ! $section ) { continue; }
		echo '<section class="fandoogh-admin-card fandoogh-settings-card"><h3>' . esc_html( $section['title'] ) . '</h3>';
		if ( is_callable( $section['callback'] ) ) { call_user_func( $section['callback'], $section ); }
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'fandoogh-manager-settings', $id );
		echo '</table></section>';
	}
	echo '<div class="fandoogh-admin-savebar"><p>' . esc_html__( 'فقط تنظیمات همین تب ذخیره می‌شود.', 'fandoogh-manager' ) . '</p>';
	submit_button( __( 'ذخیرهٔ تغییرات', 'fandoogh-manager' ), 'primary fandoogh-admin-button', 'submit', false );
	echo '</div></form>';
}

function render_admin_feature_overview() {
	$settings = get_settings();
	$cards = array(
		array( 'analytics', ! empty( $settings['analytics_enabled'] ) ? __( 'فعال', 'fandoogh-manager' ) : __( 'غیرفعال', 'fandoogh-manager' ), __( 'نمایش مالی علاوه بر این تنظیم، به مجوز کاربر هم نیاز دارد.', 'fandoogh-manager' ) ),
		array( 'sessions', ! empty( $settings['session_alerts_enabled'] ) ? __( 'هشدار روشن', 'fandoogh-manager' ) : __( 'هشدار خاموش', 'fandoogh-manager' ), __( 'کنترل دستگاه‌ها، قطع نشست و تنظیم ایمیل امنیتی.', 'fandoogh-manager' ) ),
		array( 'connection', is_ssl() ? __( 'HTTPS', 'fandoogh-manager' ) : __( 'HTTPS لازم است', 'fandoogh-manager' ), __( 'ساخت کد اتصال یک‌بارمصرف برای حساب واجد شرایط.', 'fandoogh-manager' ) ),
	);
	$tabs = admin_tabs();
	echo '<div class="fandoogh-admin-summary-grid">';
	foreach ( $cards as $card ) {
		echo '<a class="fandoogh-admin-summary" href="' . esc_url( admin_tab_url( $card[0] ) ) . '"><span>' . esc_html( $tabs[ $card[0] ]['label'] ) . '</span><strong>' . esc_html( $card[1] ) . '</strong><p>' . esc_html( $card[2] ) . '</p></a>';
	}
	echo '</div>';
}

function render_admin_analytics_help() {
	echo '<section class="fandoogh-admin-card"><h3>' . esc_html__( 'شرایط نمایش گزارش مالی', 'fandoogh-manager' ) . '</h3><ol class="fandoogh-admin-checklist"><li>' . esc_html__( 'WooCommerce باید فعال باشد و گزینهٔ تحلیل فروش در همین تب ذخیره شود.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'در تب دسترسی کاربران، «نمایش گردش مالی و تحلیل‌ها» برای کاربر هدف مجاز باشد؛ حساب باید مجوز مدیریت ووکامرس یا مدیریت تنظیمات وردپرس را نیز داشته باشد.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'اگر اتصال قبلی هنگام خاموش‌بودن گزارش مالی ساخته شده، در تب اتصال و وب‌اپ کد جدید بسازید؛ سپس از وب‌اپ خارج شوید و با کد جدید دوباره وارد شوید. تازه‌سازی صفحه به‌تنهایی مجوز نشست قدیمی را افزایش نمی‌دهد.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'تغییر مجوزهای کاربر نشست‌های قبلی او را باطل می‌کند. پس از ذخیرهٔ تنظیمات، صفحهٔ وب‌اپ را تازه‌سازی کنید.', 'fandoogh-manager' ) . '</li></ol><a class="button fandoogh-admin-button" href="' . esc_url( admin_tab_url( 'access' ) ) . '">' . esc_html__( 'بررسی دسترسی کاربران', 'fandoogh-manager' ) . '</a> <a class="button fandoogh-admin-button" href="' . esc_url( admin_tab_url( 'connection' ) ) . '">' . esc_html__( 'ساخت کد اتصال جدید', 'fandoogh-manager' ) . '</a></section>';
}
