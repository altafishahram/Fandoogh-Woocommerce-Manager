<?php
/** Administrator navigation and isolated Settings API forms. No public routes. */
namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Each editable tab owns an explicit set of option keys and registered sections. */
function admin_tabs() {
	return array(
		'overview' => array( 'label' => __( 'پیشخوان', 'fandoogh-manager' ), 'icon' => 'dashboard' ),
		'connection' => array( 'label' => __( 'اتصال گوشی', 'fandoogh-manager' ), 'icon' => 'smartphone', 'keys' => array( 'slug' ), 'sections' => array( 'connection' ) ),
		'access' => array( 'label' => __( 'دسترسی کاربران', 'fandoogh-manager' ), 'icon' => 'groups' ),
		'appearance' => array( 'label' => __( 'ظاهر وب‌اپ', 'fandoogh-manager' ), 'icon' => 'art', 'keys' => array( 'colors' ), 'sections' => array( 'theme' ) ),
		'media' => array( 'label' => __( 'رسانه و فونت', 'fandoogh-manager' ), 'icon' => 'format-image', 'keys' => array( 'image_max_width', 'image_max_height', 'image_quality', 'image_max_bytes', 'keep_original', 'local_fonts' ), 'sections' => array( 'image', 'fonts' ) ),
		'analytics' => array( 'label' => __( 'گزارش مالی', 'fandoogh-manager' ), 'icon' => 'chart-bar', 'keys' => array( 'analytics_enabled' ), 'sections' => array( 'analytics' ) ),
		'sessions' => array( 'label' => __( 'دستگاه‌ها و امنیت', 'fandoogh-manager' ), 'icon' => 'shield', 'keys' => array( 'session_alerts_enabled', 'session_alert_email' ), 'sections' => array( 'session_alerts' ) ),
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
	echo '<header class="fandoogh-admin-hero"><div class="fandoogh-admin-brand"><img src="' . esc_url( plugins_url( 'assets/brand/fandoogh-mark.svg', FANDOOGH_MANAGER_FILE ) ) . '" alt="" width="56" height="56"><div><p class="fandoogh-eyebrow">FANDOOGH MANAGER <span class="fandoogh-admin-version">' . esc_html( 'v' . VERSION ) . '</span></p><h1>' . esc_html__( 'مدیریت فندق', 'fandoogh-manager' ) . '</h1><p class="fandoogh-admin-lead">' . esc_html__( 'راه‌اندازی ساده، مدیریت آسان‌تر فروشگاه.', 'fandoogh-manager' ) . '</p></div></div><a class="button button-primary fandoogh-admin-button" href="' . esc_url( app_base_url() ) . '" target="_blank" rel="noopener noreferrer">' . admin_icon( 'smartphone' ) . esc_html__( 'باز کردن وب‌اپ', 'fandoogh-manager' ) . admin_icon( 'arrow' ) . '</a></header>';
	echo '<nav class="fandoogh-admin-tabs" aria-label="' . esc_attr__( 'بخش‌های مدیریت افزونه', 'fandoogh-manager' ) . '">';
	foreach ( $tabs as $key => $definition ) {
		echo '<a class="fandoogh-admin-tab" href="' . esc_url( admin_tab_url( $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . admin_icon( $definition['icon'] ) . esc_html( $definition['label'] ) . '</a>';
	}
	echo '</nav><div class="fandoogh-admin-content" data-admin-tab="' . esc_attr( $tab ) . '">';
	render_admin_tab_intro( $tab );
	settings_errors( OPTION_KEY );
	if ( 'overview' === $tab ) {
		$connections = admin_device_summary();
		render_admin_feature_overview( $connections );
		render_admin_guide();
		render_admin_quick_links();
		render_admin_overview( $connections );
	}
	if ( 'analytics' === $tab ) {
		render_admin_analytics_help();
	}
	if ( 'connection' === $tab ) {
		echo '<div class="fandoogh-admin-two-column">';
		render_pairing_admin_section( $pairing );
		render_admin_connection_help();
		echo '</div>';
	}
	if ( ! empty( $tabs[ $tab ]['sections'] ) ) {
		render_admin_settings_form( $tab );
	}
	if ( 'media' === $tab ) { render_font_admin_section( $font ); }
	if ( 'connection' === $tab ) {
		render_admin_app_address();
	}
	if ( in_array( $tab, array( 'access', 'sessions' ), true ) ) { render_access_admin_section( $access, $tab ); }
	echo '</div><footer class="fandoogh-admin-footer"><span>' . esc_html__( 'فندق · مدیریت فروشگاه در دست شما', 'fandoogh-manager' ) . '</span><a class="fandoogh-admin-text-link" href="' . esc_url( admin_tab_url( 'overview' ) . '#fandoogh-admin-guide' ) . '">' . esc_html__( 'راهنمای راه‌اندازی و استفاده', 'fandoogh-manager' ) . admin_icon( 'book' ) . '</a></footer></div>';
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

function render_admin_feature_overview( $connections = null ) {
	$connections = null === $connections ? admin_device_summary() : $connections;
	$woocommerce = is_woocommerce_active();
	$secure = is_ssl();
	$cards = array(
		array( 'overview', 'box', __( 'ووکامرس', 'fandoogh-manager' ), $woocommerce ? __( 'فعال و آماده', 'fandoogh-manager' ) : __( 'غیرفعال؛ حالت محدود', 'fandoogh-manager' ), $woocommerce ),
		array( 'connection', 'shield', __( 'اتصال امن', 'fandoogh-manager' ), $secure ? __( 'HTTPS فعال', 'fandoogh-manager' ) : __( 'HTTPS لازم است', 'fandoogh-manager' ), $secure ),
		array( 'sessions', 'smartphone', __( 'دستگاه‌های متصل', 'fandoogh-manager' ), number_format_i18n( $connections['active'] ) . ' ' . __( 'دستگاه · مشاهده', 'fandoogh-manager' ), true ),
	);
	echo '<div class="fandoogh-admin-summary-grid">';
	foreach ( $cards as $card ) {
		$url = admin_tab_url( $card[0] ) . ( 'overview' === $card[0] ? '#fandoogh-admin-health' : '' );
		echo '<a class="fandoogh-admin-summary' . ( $card[4] ? '' : ' fandoogh-admin-summary--warning' ) . '" href="' . esc_url( $url ) . '"><span class="fandoogh-admin-icon-tile">' . admin_icon( $card[1] ) . '</span><div><span>' . esc_html( $card[2] ) . '</span><strong>' . esc_html( $card[3] ) . '</strong></div>' . admin_icon( 'arrow' ) . '</a>';
	}
	echo '</div>';
}

/** Local SVG geometry only; never accepts caller-provided markup. */
function admin_icon( $name ) {
	$paths = array(
		'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
		'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4M11 18h2"/>',
		'groups' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 4a3 3 0 0 1 0 6M19 14a5 5 0 0 1 2 4v3"/>',
		'shield' => '<path d="m12 2 8 4v6c0 5-5 8-8 10-3-2-8-5-8-10V6l8-4Z"/><path d="m8 12 3 3 5-6"/>',
		'art' => '<path d="M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 1-4 2 2 0 0 1 0-4h3a3 3 0 0 0 3-3c0-4-4-7-9-7Z"/><path d="M7 9h.01M10 6h.01M15 7h.01M6 14h.01"/>',
		'format-image' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m21 16-6-6L3 21"/>',
		'chart-bar' => '<path d="M3 3v18h18M8 16v-5M13 16V6M18 16V9"/>',
		'location' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5ZM3 7l9 5 9-5M12 12v10M7.5 4.5l9 5"/>',
		'box' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5ZM3 7l9 5 9-5M12 12v10M7.5 4.5l9 5"/>',
		'arrow' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
		'book' => '<path d="M12 5v16M12 5C8 2 4 3 2 4v15c4-1 7-1 10 2 3-3 6-3 10-2V4c-2-1-6-2-10 1Z"/>',
		'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
		'scan' => '<path d="M3 8V3h5M16 3h5v5M21 16v5h-5M8 21H3v-5M7 8v8M10 8v8M14 8v8M17 8v8"/>',
		'mic' => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5M8 22h8"/>',
		'list' => '<path d="m3 6 2 2 3-4M11 6h10M3 14l2 2 3-4M11 14h10M11 21h10"/>',
		'chevron' => '<path d="m6 9 6 6 6-6"/>',
	);
	return '<svg class="fandoogh-admin-icon" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? $paths['dashboard'] ) . '</svg>';
}

/** One read supplies both overview counters; values are from up to 500 recent sessions. */
function admin_device_summary() {
	$rows = admin_security_session_rows();
	$active = 0;
	$users = array();
	foreach ( $rows as $row ) {
		if ( empty( $row['connected'] ) ) { continue; }
		$active++;
		$users[ absint( $row['user_id'] ) ] = true;
	}
	return array( 'active' => $active, 'users' => count( $users ), 'total' => count( $rows ) );
}

function render_admin_tab_intro( $tab ) {
	$copy = array(
		'overview' => array( __( 'همه‌چیز از اینجا شروع می‌شود', 'fandoogh-manager' ), __( 'وضعیت اتصال را ببینید و قدم بعدی را انتخاب کنید.', 'fandoogh-manager' ) ),
		'connection' => array( __( 'اتصال گوشی یا رایانه', 'fandoogh-manager' ), __( 'برای هر کاربر، اتصال مخصوص حساب خودش بسازید.', 'fandoogh-manager' ) ),
		'access' => array( __( 'دسترسی کاربران', 'fandoogh-manager' ), __( 'مشخص کنید هر عضو فروشگاه چه کارهایی انجام دهد.', 'fandoogh-manager' ) ),
		'appearance' => array( __( 'ظاهر وب‌اپ', 'fandoogh-manager' ), __( 'رنگ‌های وب‌اپ را با هویت فروشگاه هماهنگ کنید.', 'fandoogh-manager' ) ),
		'media' => array( __( 'رسانه و فونت', 'fandoogh-manager' ), __( 'کیفیت تصویرها و فونت وب‌اپ را تنظیم کنید.', 'fandoogh-manager' ) ),
		'analytics' => array( __( 'گزارش مالی', 'fandoogh-manager' ), __( 'نمایش گزارش‌ها را فعال کنید و مجوز کاربر را بررسی کنید.', 'fandoogh-manager' ) ),
		'sessions' => array( __( 'دستگاه‌ها و امنیت', 'fandoogh-manager' ), __( 'دستگاه‌های متصل را بشناسید و اتصال‌های غیرضروری را قطع کنید.', 'fandoogh-manager' ) ),
		'tracking' => array( __( 'رهگیری سفارش', 'fandoogh-manager' ), __( 'اطلاعات و لینک‌های رهگیری مرسوله‌ها را تنظیم کنید.', 'fandoogh-manager' ) ),
	);
	$text = $copy[ $tab ] ?? $copy['overview'];
	echo '<div class="fandoogh-admin-intro"><div><h2 class="fandoogh-admin-page-title">' . esc_html( $text[0] ) . '</h2><p>' . esc_html( $text[1] ) . '</p></div>';
	if ( 'overview' !== $tab ) {
		echo '<a class="fandoogh-admin-text-link" href="' . esc_url( admin_tab_url( 'overview' ) . '#fandoogh-admin-guide' ) . '">' . esc_html__( 'راهنمای راه‌اندازی', 'fandoogh-manager' ) . admin_icon( 'book' ) . '</a>';
	}
	echo '</div>';
}

/** All guide panels are readable without JS; the local script enhances them into tabs. */
function render_admin_guide() {
	$tabs = array( 'setup' => __( 'راه‌اندازی اولیه', 'fandoogh-manager' ), 'daily' => __( 'استفاده روزانه', 'fandoogh-manager' ), 'help' => __( 'رفع مشکل', 'fandoogh-manager' ) );
	echo '<section class="fandoogh-admin-card fandoogh-admin-guide" id="fandoogh-admin-guide" aria-labelledby="fandoogh-admin-guide-title"><div class="fandoogh-guide-heading"><div class="fandoogh-guide-heading-main"><span class="fandoogh-admin-icon-tile">' . admin_icon( 'book' ) . '</span><div><p class="fandoogh-eyebrow">' . esc_html__( 'راهنمای همراه شما', 'fandoogh-manager' ) . '</p><h2 id="fandoogh-admin-guide-title">' . esc_html__( 'راه‌اندازی و عملکرد افزونه', 'fandoogh-manager' ) . '</h2></div></div><span class="fandoogh-guide-pill">' . esc_html__( 'از اینجا شروع کنید', 'fandoogh-manager' ) . '</span></div>';
	echo '<nav class="fandoogh-guide-tabs" aria-label="' . esc_attr__( 'راهنمای افزونه', 'fandoogh-manager' ) . '">';
	foreach ( $tabs as $key => $label ) {
		echo '<a id="fandoogh-guide-tab-' . esc_attr( $key ) . '" href="#fandoogh-guide-' . esc_attr( $key ) . '" data-guide-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav><div class="fandoogh-guide-panel" id="fandoogh-guide-setup" aria-labelledby="fandoogh-guide-tab-setup"><h3 class="fandoogh-guide-fallback-title">' . esc_html( $tabs['setup'] ) . '</h3><p class="fandoogh-guide-lead">' . esc_html__( 'برای شروع، این سه قدم را به‌ترتیب انجام دهید.', 'fandoogh-manager' ) . '</p><ol class="fandoogh-setup-grid">';
	$steps = array(
		array( __( 'کاربر را آماده کنید', 'fandoogh-manager' ), __( 'کاربر فروشگاه را انتخاب کنید و مشخص کنید به سفارش‌ها، محصولات یا گزارش‌ها دسترسی داشته باشد.', 'fandoogh-manager' ), __( 'تنظیم دسترسی کاربران', 'fandoogh-manager' ), admin_tab_url( 'access' ) ),
		array( __( 'گوشی را وصل کنید', 'fandoogh-manager' ), __( 'برای همان کاربر کد اتصال بسازید. وب‌اپ را روی گوشی باز کنید و با حساب خودش و کد وارد شوید.', 'fandoogh-manager' ), __( 'ساخت کد اتصال', 'fandoogh-manager' ), admin_tab_url( 'connection' ) ),
		array( __( 'امکانات گوشی را فعال کنید', 'fandoogh-manager' ), __( 'در وب‌اپ، «هشدارهای این گوشی» را فعال کنید. هنگام اسکن و جستجوی صوتی، اجازهٔ دوربین و میکروفون بدهید.', 'fandoogh-manager' ), __( 'راهنمای استفاده از امکانات', 'fandoogh-manager' ), '#fandoogh-guide-daily' ),
	);
	foreach ( $steps as $index => $step ) {
		echo '<li><span class="fandoogh-step-number" aria-hidden="true">' . esc_html( array( '۱', '۲', '۳' )[ $index ] ) . '</span><h3>' . esc_html( $step[0] ) . '</h3><p>' . esc_html( $step[1] ) . '</p><a class="fandoogh-admin-text-link" href="' . esc_url( $step[3] ) . '">' . esc_html( $step[2] ) . admin_icon( 'arrow' ) . '</a></li>';
	}
	echo '</ol><p class="fandoogh-guide-tip">' . admin_icon( 'smartphone' ) . '<span>' . esc_html__( 'روی آیفون، وب‌اپ را از Safari به صفحهٔ اصلی اضافه کنید و برای فعال‌کردن اعلان از همان‌جا باز کنید.', 'fandoogh-manager' ) . '</span></p></div>';
	echo '<div class="fandoogh-guide-panel" id="fandoogh-guide-daily" aria-labelledby="fandoogh-guide-tab-daily"><h3 class="fandoogh-guide-fallback-title">' . esc_html( $tabs['daily'] ) . '</h3><p class="fandoogh-guide-lead">' . esc_html__( 'کارهای فروشگاه را در وب‌اپ، روی گوشی یا رایانه انجام دهید.', 'fandoogh-manager' ) . '</p><div class="fandoogh-daily-grid">';
	$features = array(
		array( 'list', __( 'کارهای امروز', 'fandoogh-manager' ), __( 'از پیشخوان وب‌اپ، پرداخت‌های معطل، سفارش‌های آمادهٔ ارسال و کالاهای کم‌موجودی را پیگیری کنید.', 'fandoogh-manager' ) ),
		array( 'bell', __( 'هشدارهای گوشی', 'fandoogh-manager' ), __( 'نوع هشدار و ساعت سکوت را انتخاب کنید و «ارسال آزمایشی» را بزنید تا دریافت اعلان را بررسی کنید.', 'fandoogh-manager' ) ),
		array( 'scan', __( 'اسکن بارکد', 'fandoogh-manager' ), __( 'دکمهٔ اسکن کنار جستجوی کالا را بزنید. بارکد باید در SKU یا شناسهٔ جهانی همان کالا ثبت شده باشد.', 'fandoogh-manager' ) ),
		array( 'mic', __( 'جستجوی صوتی', 'fandoogh-manager' ), __( 'میکروفون کنار کادر جستجو را بزنید و عبارت فارسی را بگویید. این امکان به پشتیبانی مرورگر نیاز دارد.', 'fandoogh-manager' ) ),
		array( 'list', __( 'فروش حضوری و چاپ فاکتور', 'fandoogh-manager' ), __( 'در صندوق، کالا و تنوع را انتخاب، قیمت همین فروش و تخفیف را اصلاح و مشتری را معرفی کنید؛ پس از دریافت نقد یا رسید کارت‌خوان، فروش را ثبت کنید. چاپ A4، A5 و فیش ۸۰ میلی‌متری برای فروش حضوری و سفارش‌ها در دسترس است. گزارش مالی منبع فروش را جدا می‌کند.', 'fandoogh-manager' ) ),
	);
	foreach ( $features as $feature ) {
		echo '<article>' . admin_icon( $feature[0] ) . '<div><h3>' . esc_html( $feature[1] ) . '</h3><p>' . esc_html( $feature[2] ) . '</p></div></article>';
	}
	echo '</div><p class="fandoogh-guide-tip">' . admin_icon( 'shield' ) . '<span>' . esc_html__( 'هر کاربر فقط امکاناتی را می‌بیند که برای حساب او مجاز شده است.', 'fandoogh-manager' ) . '</span></p></div>';
	echo '<div class="fandoogh-guide-panel" id="fandoogh-guide-help" aria-labelledby="fandoogh-guide-tab-help"><h3 class="fandoogh-guide-fallback-title">' . esc_html( $tabs['help'] ) . '</h3><p class="fandoogh-guide-lead">' . esc_html__( 'مشکل خود را انتخاب کنید تا قدم بعدی مشخص شود.', 'fandoogh-manager' ) . '</p><div class="fandoogh-guide-faq">';
	$questions = array(
		array( __( 'کد اتصال کار نمی‌کند', 'fandoogh-manager' ), __( 'کد یک‌بارمصرف است و زمان اعتبار محدودی دارد. برای همان کاربر کد جدید بسازید و با نام کاربری و رمز خودش وارد شوید.', 'fandoogh-manager' ), 'connection', __( 'ساخت کد جدید', 'fandoogh-manager' ) ),
		array( __( 'اعلان روی گوشی نمی‌آید', 'fandoogh-manager' ), __( 'در وب‌اپ، فعال‌بودن «هشدارهای این گوشی»، اجازهٔ اعلان مرورگر و ساعت سکوت را بررسی کنید. سپس اعلان آزمایشی بفرستید. روی آیفون، وب‌اپ را از صفحهٔ اصلی باز کنید. ارسال اعلان به اجرای زمان‌بندی سایت نیز وابسته است.', 'fandoogh-manager' ), '', '' ),
		array( __( 'دوربین یا میکروفون باز نمی‌شود', 'fandoogh-manager' ), __( 'سایت را با HTTPS باز کنید و اجازهٔ دوربین یا میکروفون را در تنظیمات مرورگر بدهید. اگر مرورگر پشتیبانی نمی‌کند، از ورود دستی بارکد یا تایپ عبارت استفاده کنید.', 'fandoogh-manager' ), '', '' ),
		array( __( 'گزارش مالی را نمی‌بینم', 'fandoogh-manager' ), __( 'گزارش مالی را در تنظیمات فعال کنید و مجوز کاربر را بررسی کنید. اگر مجوز یا تنظیم گزارش تغییر کرده، کد اتصال جدید بسازید و دوباره وارد وب‌اپ شوید.', 'fandoogh-manager' ), 'analytics', __( 'بررسی گزارش مالی', 'fandoogh-manager' ) ),
	);
	foreach ( $questions as $question ) {
		echo '<details><summary>' . esc_html( $question[0] ) . admin_icon( 'chevron' ) . '</summary><p>' . esc_html( $question[1] ) . '</p>';
		if ( $question[2] ) { echo '<a class="fandoogh-admin-text-link" href="' . esc_url( admin_tab_url( $question[2] ) ) . '">' . esc_html( $question[3] ) . admin_icon( 'arrow' ) . '</a>'; }
		echo '</details>';
	}
	echo '</div></div></section>';
}

function render_admin_quick_links() {
	$links = array(
		array( 'connection', 'smartphone', __( 'اتصال دستگاه جدید', 'fandoogh-manager' ), __( 'ساخت کد برای گوشی یا رایانه', 'fandoogh-manager' ) ),
		array( 'access', 'groups', __( 'مدیریت کاربران', 'fandoogh-manager' ), __( 'انتخاب مجوز هر عضو فروشگاه', 'fandoogh-manager' ) ),
		array( 'appearance', 'art', __( 'شخصی‌سازی ظاهر', 'fandoogh-manager' ), __( 'رنگ‌بندی هماهنگ با فروشگاه', 'fandoogh-manager' ) ),
		array( 'sessions', 'shield', __( 'بررسی دستگاه‌ها', 'fandoogh-manager' ), __( 'مشاهده و قطع اتصال دستگاه‌ها', 'fandoogh-manager' ) ),
	);
	echo '<section aria-labelledby="fandoogh-quick-title"><div class="fandoogh-admin-intro"><div><h2 id="fandoogh-quick-title">' . esc_html__( 'تنظیمات پرکاربرد', 'fandoogh-manager' ) . '</h2><p>' . esc_html__( 'مستقیم به کاری بروید که می‌خواهید انجام دهید.', 'fandoogh-manager' ) . '</p></div></div><div class="fandoogh-admin-quick-grid">';
	foreach ( $links as $link ) {
		echo '<a href="' . esc_url( admin_tab_url( $link[0] ) ) . '"><span class="fandoogh-admin-icon-tile">' . admin_icon( $link[1] ) . '</span><h3>' . esc_html( $link[2] ) . '</h3><p>' . esc_html( $link[3] ) . '</p>' . admin_icon( 'arrow' ) . '</a>';
	}
	echo '</div></section>';
}

function render_admin_connection_help() {
	echo '<aside class="fandoogh-admin-card fandoogh-context-help"><h3>' . esc_html__( 'بعد از ساخت کد چه کنم؟', 'fandoogh-manager' ) . '</h3><ol class="fandoogh-admin-checklist"><li>' . esc_html__( 'وب‌اپ فروشگاه را روی گوشی باز کنید.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'نام کاربری، رمز و کد همان کاربر را وارد کنید.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'وب‌اپ را به صفحهٔ اصلی گوشی اضافه کنید.', 'fandoogh-manager' ) . '</li></ol><p class="fandoogh-guide-tip">' . esc_html__( 'کد را فقط به همان کاربر بدهید؛ هر کد یک‌بار مصرف می‌شود.', 'fandoogh-manager' ) . '</p></aside>';
}

function render_admin_app_address() {
	echo '<section class="fandoogh-admin-card fandoogh-app-address"><h3>' . esc_html__( 'نشانی وب‌اپ', 'fandoogh-manager' ) . '</h3><p>' . esc_html__( 'این مسیر را روی گوشی باز کنید.', 'fandoogh-manager' ) . '</p><label class="screen-reader-text" for="fandoogh-app-address">' . esc_html__( 'نشانی وب‌اپ', 'fandoogh-manager' ) . '</label><input id="fandoogh-app-address" type="text" readonly dir="ltr" value="' . esc_attr( app_base_url() ) . '"><div class="fandoogh-app-address-actions"><button type="button" class="button" data-copy-app-address hidden data-copy-success="' . esc_attr__( 'نشانی وب‌اپ کپی شد.', 'fandoogh-manager' ) . '" data-copy-error="' . esc_attr__( 'کپی خودکار ممکن نشد؛ نشانی انتخاب‌شده را دستی کپی کنید.', 'fandoogh-manager' ) . '">' . esc_html__( 'کپی نشانی', 'fandoogh-manager' ) . '</button><a class="button" href="' . esc_url( app_base_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'باز کردن وب‌اپ', 'fandoogh-manager' ) . '</a></div><p role="status" aria-live="polite" data-copy-status></p><p class="fandoogh-guide-tip">' . esc_html__( 'دکمهٔ نصب در مرورگرهای سازگار نمایش داده می‌شود؛ در آیفون از Safari گزینهٔ افزودن به صفحهٔ اصلی را انتخاب کنید.', 'fandoogh-manager' ) . '</p></section>';
}

function render_admin_analytics_help() {
	echo '<section class="fandoogh-admin-card"><h3>' . esc_html__( 'شرایط نمایش گزارش مالی', 'fandoogh-manager' ) . '</h3><ol class="fandoogh-admin-checklist"><li>' . esc_html__( 'WooCommerce باید فعال باشد و گزینهٔ تحلیل فروش در همین تب ذخیره شود.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'در تب دسترسی کاربران، «نمایش گردش مالی و تحلیل‌ها» برای کاربر هدف مجاز باشد؛ حساب باید مجوز مدیریت ووکامرس یا مدیریت تنظیمات وردپرس را نیز داشته باشد.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'اگر اتصال قبلی هنگام خاموش‌بودن گزارش مالی ساخته شده، در تب اتصال و وب‌اپ کد جدید بسازید؛ سپس از وب‌اپ خارج شوید و با کد جدید دوباره وارد شوید. تازه‌سازی صفحه به‌تنهایی مجوز نشست قدیمی را افزایش نمی‌دهد.', 'fandoogh-manager' ) . '</li><li>' . esc_html__( 'تغییر مجوزهای کاربر نشست‌های قبلی او را باطل می‌کند. پس از ذخیرهٔ تنظیمات، صفحهٔ وب‌اپ را تازه‌سازی کنید.', 'fandoogh-manager' ) . '</li></ol><a class="button fandoogh-admin-button" href="' . esc_url( admin_tab_url( 'access' ) ) . '">' . esc_html__( 'بررسی دسترسی کاربران', 'fandoogh-manager' ) . '</a> <a class="button fandoogh-admin-button" href="' . esc_url( admin_tab_url( 'connection' ) ) . '">' . esc_html__( 'ساخت کد اتصال جدید', 'fandoogh-manager' ) . '</a></section>';
}
