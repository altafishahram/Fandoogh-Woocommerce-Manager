<?php
/**
 * Order tracking integration for Fandoogh Manager.
 *
 * This module deliberately reuses the controlled shipment snapshot from
 * shipping.php. It does not create a second order-meta schema or depend on a
 * carrier API, which keeps the feature compatible with WooCommerce HPOS.
 *
 * @package Fandoogh_Manager
 */

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ORDER_TRACKING_PROVIDER_ID_MAX_LENGTH = 32;
const ORDER_TRACKING_TITLE_MAX_LENGTH       = 100;
const ORDER_TRACKING_SUBTITLE_MAX_LENGTH    = 180;
const ORDER_TRACKING_BUTTON_MAX_LENGTH      = 60;
const ORDER_TRACKING_LABEL_MAX_LENGTH       = 80;

/**
 * Return the three providers requested for the initial release. Keeping this
 * list explicit makes the public data contract small and avoids a generic,
 * unreviewed carrier integration surface.
 *
 * @return array<string, array<string, string>>
 */
function order_tracking_default_providers() {
	return array(
		'post'   => array(
			'id'           => 'post',
			'label'        => __( 'پست ایران', 'fandoogh-manager' ),
			'tracking_url' => 'https://tracking.post.ir/',
		),
		'chapar' => array(
			'id'           => 'chapar',
			'label'        => __( 'چاپار', 'fandoogh-manager' ),
			'tracking_url' => 'https://www.chaparnet.com/track',
		),
		'tipax'  => array(
			'id'           => 'tipax',
			'label'        => __( 'تیپاکس', 'fandoogh-manager' ),
			'tracking_url' => 'https://tipaxco.com/tracking',
		),
	);
}

/**
 * @return array<string, mixed>
 */
function order_tracking_default_settings() {
	return array(
		'title'        => __( 'پیگیری مرسوله شما', 'fandoogh-manager' ),
		'subtitle'     => __( 'کد رهگیری را کپی کنید یا صفحهٔ شرکت حمل را باز کنید.', 'fandoogh-manager' ),
		'button_label' => __( 'پیگیری مرسوله', 'fandoogh-manager' ),
		'providers'    => order_tracking_default_providers(),
	);
}

/**
 * Keep short display strings safe before they reach either wp-admin or a
 * customer-facing order page.
 *
 * @param mixed $value Candidate string.
 * @param int   $maximum_length Maximum character length.
 * @return string
 */
function order_tracking_sanitize_text( $value, $maximum_length ) {
	$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $value, 0, $maximum_length );
	}

	return substr( $value, 0, $maximum_length );
}

/**
 * Validate a stable provider identifier. Labels are editable by an
 * administrator, but these identifiers remain internal contract keys.
 *
 * @param mixed $value Candidate identifier.
 * @return string
 */
function order_tracking_sanitize_provider_id( $value ) {
	$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
	if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $value ) ) {
		return '';
	}

	return $value;
}

/**
 * Sanitize an HTTPS landing page or URL template. `{tracking_code}` is the
 * only supported substitution token and is URL-encoded when used.
 *
 * @param mixed $value Candidate URL template.
 * @return string
 */
function order_tracking_sanitize_url_template( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	if ( '' === $value || substr_count( $value, '{tracking_code}' ) > 1 ) {
		return '';
	}

	$placeholder = '__FANDOOGH_TRACKING_CODE__';
	if ( false !== strpos( $value, $placeholder ) ) {
		return '';
	}

	$tokenized = str_replace( '{tracking_code}', $placeholder, $value );
	$clean     = esc_url_raw( $tokenized, array( 'https' ) );
	$parts     = function_exists( 'wp_parse_url' ) ? wp_parse_url( $clean ) : parse_url( $clean );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return '';
	}

	return str_replace( $placeholder, '{tracking_code}', $clean );
}

/**
 * Sanitize the tracking subset of the unified Fandoogh settings option.
 *
 * @param mixed $raw Candidate settings.
 * @return array<string, mixed>
 */
function order_tracking_sanitize_settings( $raw ) {
	$defaults = order_tracking_default_settings();
	$raw      = is_array( $raw ) ? $raw : array();
	$settings = $defaults;

	foreach ( array( 'title' => ORDER_TRACKING_TITLE_MAX_LENGTH, 'subtitle' => ORDER_TRACKING_SUBTITLE_MAX_LENGTH, 'button_label' => ORDER_TRACKING_BUTTON_MAX_LENGTH ) as $field => $maximum_length ) {
		if ( array_key_exists( $field, $raw ) ) {
			$value = order_tracking_sanitize_text( $raw[ $field ], $maximum_length );
			if ( '' !== $value ) {
				$settings[ $field ] = $value;
			}
		}
	}

	$raw_providers = isset( $raw['providers'] ) && is_array( $raw['providers'] ) ? $raw['providers'] : array();
	foreach ( $defaults['providers'] as $provider_id => $provider ) {
		$input = isset( $raw_providers[ $provider_id ] ) && is_array( $raw_providers[ $provider_id ] ) ? $raw_providers[ $provider_id ] : array();
		$label = array_key_exists( 'label', $input ) ? order_tracking_sanitize_text( $input['label'], ORDER_TRACKING_LABEL_MAX_LENGTH ) : '';
		$url   = array_key_exists( 'tracking_url', $input ) ? order_tracking_sanitize_url_template( $input['tracking_url'] ) : '';

		if ( '' !== $label ) {
			$settings['providers'][ $provider_id ]['label'] = $label;
		}
		if ( array_key_exists( 'tracking_url', $input ) ) {
			$settings['providers'][ $provider_id ]['tracking_url'] = $url;
		}
	}

	return $settings;
}

/**
 * @return array<string, mixed>
 */
function order_tracking_settings() {
	$settings = function_exists( __NAMESPACE__ . '\\get_settings' ) ? get_settings() : array();
	$tracking = isset( $settings['order_tracking'] ) ? $settings['order_tracking'] : array();
	return order_tracking_sanitize_settings( $tracking );
}

/**
 * @return array<string, array<string, string>>
 */
function order_tracking_providers() {
	$settings  = order_tracking_settings();
	$providers = isset( $settings['providers'] ) && is_array( $settings['providers'] ) ? $settings['providers'] : array();
	$result    = array();

	foreach ( $providers as $provider_id => $provider ) {
		$provider_id = order_tracking_sanitize_provider_id( $provider_id );
		if ( '' === $provider_id || ! is_array( $provider ) ) {
			continue;
		}

		$label = isset( $provider['label'] ) ? order_tracking_sanitize_text( $provider['label'], ORDER_TRACKING_LABEL_MAX_LENGTH ) : '';
		$url   = isset( $provider['tracking_url'] ) ? order_tracking_sanitize_url_template( $provider['tracking_url'] ) : '';
		if ( '' === $label ) {
			continue;
		}

		$result[ $provider_id ] = array(
			'id'           => $provider_id,
			'label'        => $label,
			'tracking_url' => $url,
		);
	}

	return $result;
}

/**
 * @param mixed $provider_id Provider identifier.
 * @return array<string, string>|null
 */
function order_tracking_get_provider( $provider_id ) {
	$provider_id = order_tracking_sanitize_provider_id( $provider_id );
	$providers   = order_tracking_providers();
	return '' !== $provider_id && isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : null;
}

/**
 * Match a legacy carrier label to a configured provider without changing the
 * stored snapshot until an administrator saves it again. This lets older
 * records use the new selector and customer link safely.
 *
 * @param mixed $label Carrier label.
 * @return array<string, string>|null
 */
function order_tracking_get_provider_by_label( $label ) {
	$label = order_tracking_sanitize_text( $label, ORDER_TRACKING_LABEL_MAX_LENGTH );
	if ( '' === $label ) {
		return null;
	}

	foreach ( order_tracking_providers() as $provider ) {
		if ( isset( $provider['label'] ) && $label === $provider['label'] ) {
			return $provider;
		}
	}

	return null;
}

/**
 * Build a customer-facing provider link. If a provider only offers a landing
 * page, no tracking code is appended; this avoids guessing undocumented query
 * parameter formats for carrier websites.
 *
 * @param array<string, string>|null $provider Provider configuration.
 * @param string                     $tracking_code Tracking code.
 * @return string
 */
function order_tracking_build_url( $provider, $tracking_code ) {
	if ( ! is_array( $provider ) || empty( $provider['tracking_url'] ) ) {
		return '';
	}

	$template = order_tracking_sanitize_url_template( $provider['tracking_url'] );
	if ( '' === $template ) {
		return '';
	}

	if ( false !== strpos( $template, '{tracking_code}' ) ) {
		$template = str_replace( '{tracking_code}', rawurlencode( (string) $tracking_code ), $template );
	}

	return esc_url_raw( $template, array( 'https' ) );
}

/**
 * The small non-secret configuration used by the manager app to build a
 * carrier selector. URLs are intentionally returned only with a private order
 * shipment response after the server has combined them with an order code.
 *
 * @return array<string, mixed>
 */
function order_tracking_public_config() {
	$providers = array();
	foreach ( order_tracking_providers() as $provider ) {
		$providers[] = array(
			'id'    => $provider['id'],
			'label' => $provider['label'],
		);
	}

	return array( 'providers' => $providers );
}

/**
 * Register the tracking settings within the existing Fandoogh Manager page,
 * rather than adding an unrelated WooCommerce submenu.
 *
 * @return void
 */
function order_tracking_register_settings() {
	add_settings_section(
		'fandoogh_manager_order_tracking_section',
		__( 'رهگیری مرسوله', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\order_tracking_render_settings_section',
		'fandoogh-manager-settings'
	);

	$fields = array(
		'title'        => __( 'عنوان کارت مشتری', 'fandoogh-manager' ),
		'subtitle'     => __( 'زیرعنوان کارت مشتری', 'fandoogh-manager' ),
		'button_label' => __( 'متن دکمهٔ پیگیری', 'fandoogh-manager' ),
	);
	foreach ( $fields as $field => $label ) {
		add_settings_field(
			'fandoogh_manager_tracking_' . $field,
			$label,
			__NAMESPACE__ . '\\order_tracking_render_settings_field',
			'fandoogh-manager-settings',
			'fandoogh_manager_order_tracking_section',
			array( 'field' => $field )
		);
	}

	foreach ( order_tracking_default_providers() as $provider_id => $provider ) {
		add_settings_field(
			'fandoogh_manager_tracking_' . $provider_id . '_label',
			sprintf( __( 'نام شرکت حمل: %s', 'fandoogh-manager' ), $provider['label'] ),
			__NAMESPACE__ . '\\order_tracking_render_settings_field',
			'fandoogh-manager-settings',
			'fandoogh_manager_order_tracking_section',
			array( 'field' => 'label', 'provider_id' => $provider_id )
		);
		add_settings_field(
			'fandoogh_manager_tracking_' . $provider_id . '_url',
			sprintf( __( 'نشانی پیگیری: %s', 'fandoogh-manager' ), $provider['label'] ),
			__NAMESPACE__ . '\\order_tracking_render_settings_field',
			'fandoogh-manager-settings',
			'fandoogh_manager_order_tracking_section',
			array( 'field' => 'tracking_url', 'provider_id' => $provider_id )
		);
	}
}

/**
 * @return void
 */
function order_tracking_render_settings_section() {
	echo '<p>' . esc_html__( 'کد رهگیری در همان رکورد ارسال افزونه ذخیره می‌شود و در ویرایش سفارش WooCommerce، وب‌اپ و حساب مشتری یکسان است. آدرس‌ها فقط HTTPS هستند؛ برای ارسال خودکار کد در URL از {tracking_code} استفاده کنید.', 'fandoogh-manager' ) . '</p>';
}

/**
 * @param array<string, string> $args Field configuration.
 * @return void
 */
function order_tracking_render_settings_field( $args ) {
	$args        = is_array( $args ) ? $args : array();
	$field       = isset( $args['field'] ) ? sanitize_key( $args['field'] ) : '';
	$provider_id = isset( $args['provider_id'] ) ? order_tracking_sanitize_provider_id( $args['provider_id'] ) : '';
	$settings    = order_tracking_settings();
	$name        = OPTION_KEY . '[order_tracking]';
	$value       = '';
	$type        = 'text';

	if ( '' !== $provider_id && isset( $settings['providers'][ $provider_id ] ) && is_array( $settings['providers'][ $provider_id ] ) ) {
		$value = isset( $settings['providers'][ $provider_id ][ $field ] ) ? (string) $settings['providers'][ $provider_id ][ $field ] : '';
		$name .= '[providers][' . $provider_id . '][' . $field . ']';
		$type  = 'tracking_url' === $field ? 'url' : 'text';
	} elseif ( in_array( $field, array( 'title', 'subtitle', 'button_label' ), true ) ) {
		$value = isset( $settings[ $field ] ) ? (string) $settings[ $field ] : '';
		$name .= '[' . $field . ']';
	}

	if ( 'url' === $type ) {
		echo '<input type="url" class="regular-text code" dir="ltr" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="https://example.com/track/{tracking_code}">';
		echo '<p class="description">' . esc_html__( 'اختیاری: اگر سایت شرکت حمل امکانش را دارد، {tracking_code} با کد واقعی و URL-encoded جایگزین می‌شود؛ در غیر این صورت فقط صفحهٔ پیگیری باز می‌شود.', 'fandoogh-manager' ) . '</p>';
		return;
	}

	$maximum_length = 'subtitle' === $field ? ORDER_TRACKING_SUBTITLE_MAX_LENGTH : ( 'button_label' === $field ? ORDER_TRACKING_BUTTON_MAX_LENGTH : ( '' !== $provider_id ? ORDER_TRACKING_LABEL_MAX_LENGTH : ORDER_TRACKING_TITLE_MAX_LENGTH ) );
	echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="' . esc_attr( (string) $maximum_length ) . '">';
}

/**
 * @return bool
 */
function order_tracking_hpos_enabled() {
	$controller = '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';
	if ( class_exists( $controller ) && function_exists( 'wc_get_container' ) ) {
		try {
			$service = wc_get_container()->get( $controller );
			if ( is_object( $service ) && method_exists( $service, 'custom_orders_table_usage_is_enabled' ) ) {
				return (bool) $service->custom_orders_table_usage_is_enabled();
			}
		} catch ( \Throwable $exception ) {
			// Older WooCommerce versions can fall through to OrderUtil below.
		}
	}

	$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	return class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) && (bool) $order_util::custom_orders_table_usage_is_enabled();
}

/**
 * @return void
 */
function order_tracking_register_meta_box() {
	if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
		return;
	}

	$screen = order_tracking_hpos_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	add_meta_box(
		'fandoogh-order-tracking',
		__( 'رهگیری مرسوله', 'fandoogh-manager' ),
		__NAMESPACE__ . '\\order_tracking_render_meta_box',
		$screen,
		'side',
		'high'
	);
}

/**
 * @param mixed $post_or_order A legacy post or a WooCommerce order object.
 * @return \WC_Order|null
 */
function order_tracking_resolve_order( $post_or_order ) {
	if ( $post_or_order instanceof \WP_Post ) {
		$post_or_order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_or_order->ID ) : null;
	}

	return $post_or_order instanceof \WC_Order ? $post_or_order : null;
}

/**
 * @param mixed $post_or_order A legacy post or a WooCommerce order object.
 * @return void
 */
function order_tracking_render_meta_box( $post_or_order ) {
	$order = order_tracking_resolve_order( $post_or_order );
	if ( ! $order || ! function_exists( __NAMESPACE__ . '\\shipping_read_snapshot' ) ) {
		return;
	}

	$snapshot      = shipping_read_snapshot( $order );
	$provider_id   = isset( $snapshot['carrier_id'] ) ? order_tracking_sanitize_provider_id( $snapshot['carrier_id'] ) : '';
	$carrier_label = isset( $snapshot['carrier_label'] ) ? (string) $snapshot['carrier_label'] : '';
	$provider      = order_tracking_get_provider( $provider_id );
	if ( ! $provider ) {
		$provider = order_tracking_get_provider_by_label( $carrier_label );
		if ( $provider ) {
			$provider_id = $provider['id'];
		}
	}
	$tracking_code = isset( $snapshot['tracking_code'] ) ? (string) $snapshot['tracking_code'] : '';

	wp_nonce_field( 'fandoogh_order_tracking_meta_box', 'fandoogh_order_tracking_nonce' );
	echo '<div class="fandoogh-order-tracking-meta-box">';
	echo '<p><label for="fandoogh-order-tracking-provider"><strong>' . esc_html__( 'شرکت حمل', 'fandoogh-manager' ) . '</strong></label><select id="fandoogh-order-tracking-provider" name="fandoogh_order_tracking_provider" class="widefat">';
	echo '<option value="">' . esc_html__( 'شرکت دیگر یا انتخاب‌نشده', 'fandoogh-manager' ) . '</option>';
	foreach ( order_tracking_providers() as $provider ) {
		echo '<option value="' . esc_attr( $provider['id'] ) . '" ' . selected( $provider_id, $provider['id'], false ) . '>' . esc_html( $provider['label'] ) . '</option>';
	}
	echo '</select></p>';
	echo '<p><label for="fandoogh-order-tracking-carrier"><strong>' . esc_html__( 'نام شرکت حمل (برای حالت دیگر)', 'fandoogh-manager' ) . '</strong></label><input type="text" id="fandoogh-order-tracking-carrier" name="fandoogh_order_tracking_carrier" class="widefat" maxlength="' . esc_attr( (string) SHIPPING_CARRIER_MAX_LENGTH ) . '" value="' . esc_attr( $carrier_label ) . '"></p>';
	echo '<p><label for="fandoogh-order-tracking-code"><strong>' . esc_html__( 'کد رهگیری', 'fandoogh-manager' ) . '</strong></label><input type="text" id="fandoogh-order-tracking-code" name="fandoogh_order_tracking_code" class="widefat" dir="ltr" maxlength="' . esc_attr( (string) SHIPPING_TRACKING_MAX_LENGTH ) . '" value="' . esc_attr( $tracking_code ) . '" autocomplete="off"></p>';
	echo '<p class="description">' . esc_html__( 'این داده در وب‌اپ و صفحهٔ مشاهدهٔ سفارش مشتری هم نمایش داده می‌شود. وضعیت سفارش یا روش پرداخت را تغییر نمی‌دهد.', 'fandoogh-manager' ) . '</p>';
	echo '</div>';
}

/**
 * @param mixed $value Candidate input value.
 * @param int   $maximum_length Maximum character length.
 * @return string|\\WP_Error
 */
function order_tracking_admin_text( $value, $maximum_length ) {
	$value = is_scalar( $value ) ? wp_unslash( $value ) : '';
	if ( function_exists( __NAMESPACE__ . '\\shipping_validate_text' ) ) {
		$validated = shipping_validate_text( $value, $maximum_length, 'tracking' );
		return $validated;
	}

	return order_tracking_sanitize_text( $value, $maximum_length );
}

/**
 * Persist wp-admin tracking input through the same WC_Order CRUD path used by
 * the manager app. WooCommerce fires this hook for both classic and HPOS
 * order-edit screens.
 *
 * @param int $order_id WooCommerce order ID.
 * @return void
 */
function order_tracking_save_meta_box( $order_id ) {
	static $saved_order_ids = array();
	$order_id = absint( $order_id );
	if ( ! $order_id || isset( $saved_order_ids[ $order_id ] ) || ! isset( $_POST['fandoogh_order_tracking_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['fandoogh_order_tracking_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'fandoogh_order_tracking_meta_box' ) || ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'edit_post', $order_id ) ) ) {
		return;
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof \WC_Order || ! function_exists( __NAMESPACE__ . '\\shipping_read_snapshot' ) || ! function_exists( __NAMESPACE__ . '\\shipping_save_snapshot' ) ) {
		return;
	}

	$provider_id = isset( $_POST['fandoogh_order_tracking_provider'] ) ? order_tracking_sanitize_provider_id( wp_unslash( $_POST['fandoogh_order_tracking_provider'] ) ) : '';
	$provider    = order_tracking_get_provider( $provider_id );
	if ( '' !== $provider_id && ! $provider ) {
		return;
	}

	$tracking_code = isset( $_POST['fandoogh_order_tracking_code'] ) ? order_tracking_admin_text( $_POST['fandoogh_order_tracking_code'], SHIPPING_TRACKING_MAX_LENGTH ) : '';
	$carrier_label = $provider ? $provider['label'] : ( isset( $_POST['fandoogh_order_tracking_carrier'] ) ? order_tracking_admin_text( $_POST['fandoogh_order_tracking_carrier'], SHIPPING_CARRIER_MAX_LENGTH ) : '' );
	if ( is_wp_error( $tracking_code ) || is_wp_error( $carrier_label ) ) {
		return;
	}
	$existing      = shipping_read_snapshot( $order );
	if ( (string) $existing['tracking_code'] === $tracking_code && (string) $existing['carrier_label'] === $carrier_label && (string) ( isset( $existing['carrier_id'] ) ? $existing['carrier_id'] : '' ) === ( $provider ? $provider['id'] : '' ) ) {
		return;
	}

	$saved_order_ids[ $order_id ] = true;
	$snapshot = shipping_save_snapshot(
		$order,
		array(
			'carrier_id'    => $provider ? $provider['id'] : '',
			'carrier_label' => $carrier_label,
			'tracking_code' => $tracking_code,
		),
		get_current_user_id()
	);

	if ( ! is_wp_error( $snapshot ) && function_exists( __NAMESPACE__ . '\\record_audit_event' ) ) {
		record_audit_event(
			'order_tracking_updated',
			get_current_user_id(),
			0,
			'wp-admin',
			'order',
			$order_id,
			array(
				'outcome'     => 'saved',
				'provider_id' => $provider ? $provider['id'] : '',
				'has_code'    => '' !== $tracking_code,
			)
		);
	}
}

/**
 * Enqueue small, local assets only for WooCommerce account/order pages.
 *
 * @return void
 */
function order_tracking_enqueue_customer_assets() {
	if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) ) {
		return;
	}

	wp_enqueue_style(
		'fandoogh-order-tracking',
		plugins_url( 'assets/css/order-tracking.css', FANDOOGH_MANAGER_FILE ),
		array(),
		FANDOOGH_MANAGER_VERSION
	);
	$colors = get_settings();
	$colors = isset( $colors['colors'] ) && is_array( $colors['colors'] ) ? $colors['colors'] : array();
	wp_add_inline_style(
		'fandoogh-order-tracking',
		':root{--fandoogh-tracking-primary:' . esc_attr( isset( $colors['primary'] ) ? $colors['primary'] : '#0f766e' ) . ';--fandoogh-tracking-accent:' . esc_attr( isset( $colors['accent'] ) ? $colors['accent'] : '#f59e0b' ) . ';}'
	);
	wp_enqueue_script(
		'fandoogh-order-tracking',
		plugins_url( 'assets/js/order-tracking.js', FANDOOGH_MANAGER_FILE ),
		array(),
		FANDOOGH_MANAGER_VERSION,
		true
	);
}

/**
 * Render a customer-safe tracking card after WooCommerce's order table.
 * WooCommerce owns authorization for this screen; this renderer never fetches
 * an order by ID from the request and never exposes internal shipment notes.
 *
 * @param mixed $order WooCommerce order object.
 * @return void
 */
function order_tracking_render_customer_card( $order ) {
	if ( ! $order instanceof \WC_Order || ! function_exists( __NAMESPACE__ . '\\shipping_read_snapshot' ) ) {
		return;
	}

	$snapshot      = shipping_read_snapshot( $order );
	$tracking_code = isset( $snapshot['tracking_code'] ) ? (string) $snapshot['tracking_code'] : '';
	if ( '' === $tracking_code ) {
		return;
	}

	$provider      = order_tracking_get_provider( isset( $snapshot['carrier_id'] ) ? $snapshot['carrier_id'] : '' );
	if ( ! $provider ) {
		$provider = order_tracking_get_provider_by_label( isset( $snapshot['carrier_label'] ) ? $snapshot['carrier_label'] : '' );
	}
	$carrier_label = $provider ? $provider['label'] : ( isset( $snapshot['carrier_label'] ) ? (string) $snapshot['carrier_label'] : '' );
	$carrier_label = '' !== $carrier_label ? $carrier_label : __( 'شرکت حمل', 'fandoogh-manager' );
	$tracking_url  = order_tracking_build_url( $provider, $tracking_code );
	$settings      = order_tracking_settings();
	$code_id       = 'fandoogh-tracking-code-' . absint( $order->get_id() );

	echo '<section class="fandoogh-tracking-card" dir="rtl" aria-labelledby="fandoogh-tracking-title-' . esc_attr( (string) absint( $order->get_id() ) ) . '">';
	echo '<div class="fandoogh-tracking-card__stripe" aria-hidden="true"></div>';
	echo '<div class="fandoogh-tracking-card__body">';
	echo '<div class="fandoogh-tracking-card__header"><span class="fandoogh-tracking-card__mark" aria-hidden="true">↗</span><div><p class="fandoogh-tracking-card__eyebrow">' . esc_html( $carrier_label ) . '</p><h2 id="fandoogh-tracking-title-' . esc_attr( (string) absint( $order->get_id() ) ) . '">' . esc_html( $settings['title'] ) . '</h2><p>' . esc_html( $settings['subtitle'] ) . '</p></div></div>';
	echo '<div class="fandoogh-tracking-card__code"><div><span>' . esc_html__( 'کد رهگیری', 'fandoogh-manager' ) . '</span><code id="' . esc_attr( $code_id ) . '" dir="ltr">' . esc_html( $tracking_code ) . '</code></div><button type="button" class="fandoogh-tracking-card__copy" data-fandoogh-copy="' . esc_attr( $code_id ) . '" aria-label="' . esc_attr__( 'کپی کد رهگیری', 'fandoogh-manager' ) . '"><span data-fandoogh-copy-label>' . esc_html__( 'کپی', 'fandoogh-manager' ) . '</span></button></div>';
	if ( '' !== $tracking_url ) {
		echo '<a class="fandoogh-tracking-card__action" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer"><span>' . esc_html( $settings['button_label'] ) . '</span><span aria-hidden="true">←</span></a>';
	}
	echo '</div></section>';
}

add_action( 'admin_init', __NAMESPACE__ . '\\order_tracking_register_settings' );
add_action( 'add_meta_boxes', __NAMESPACE__ . '\\order_tracking_register_meta_box' );
add_action( 'woocommerce_process_shop_order_meta', __NAMESPACE__ . '\\order_tracking_save_meta_box', 40, 1 );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\order_tracking_enqueue_customer_assets' );
add_action( 'woocommerce_order_details_after_order_table', __NAMESPACE__ . '\\order_tracking_render_customer_card', 12 );
