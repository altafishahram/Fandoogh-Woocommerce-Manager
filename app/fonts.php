<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/settings.php';
require_once __DIR__ . '/composition/media.php';

const LOCAL_FONT_MAX_BYTES = 10485760;

/**
 * @param mixed $font_path Relative managed path.
 * @return string
 */
function sanitize_managed_font_path( $font_path ) {
	$paths = sanitize_local_font_allowlist( array( $font_path ) );
	return isset( $paths[0] ) ? $paths[0] : '';
}

/**
 * @param string $font_path Relative managed path.
 * @return string
 */
function managed_font_absolute_path( $font_path ) {
	$font_path = sanitize_managed_font_path( $font_path );
	if ( '' === $font_path ) {
		return '';
	}

	$prefix = 'fandoogh-manager/fonts/';
	$filename = substr( $font_path, strlen( $prefix ) );
	if ( false === $filename || '' === $filename ) {
		return '';
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	return trailingslashit( $uploads['basedir'] ) . $prefix . $filename;
}

/**
 * @param string $font_path Relative managed path.
 * @return string|null
 */
function managed_font_url( $font_path ) {
	$font_path = sanitize_managed_font_path( $font_path );
	if ( '' === $font_path ) {
		return null;
	}

	$prefix = 'fandoogh-manager/fonts/';
	$filename = substr( $font_path, strlen( $prefix ) );
	$uploads = wp_upload_dir();
	if ( false === $filename || empty( $uploads['baseurl'] ) || ! empty( $uploads['error'] ) ) {
		return null;
	}

	return public_asset_url( trailingslashit( $uploads['baseurl'] ) . $prefix . rawurlencode( $filename ) );
}

/**
 * @param string $font_path Relative managed path.
 * @return string
 */
function managed_font_format( $font_path ) {
	$extension = strtolower( pathinfo( (string) $font_path, PATHINFO_EXTENSION ) );
	$formats   = array(
		'woff2' => 'woff2',
		'woff'  => 'woff',
		'ttf'   => 'truetype',
		'otf'   => 'opentype',
	);

	return isset( $formats[ $extension ] ) ? $formats[ $extension ] : '';
}

/**
 * @return array<int, array<string, string>>
 */
function get_public_fonts() {
	$fonts = array();

	foreach ( get_settings()['local_fonts'] as $font_path ) {
		$file_path = managed_font_absolute_path( $font_path );
		$url       = managed_font_url( $font_path );
		$format    = managed_font_format( $font_path );

		if ( '' === $file_path || ! is_readable( $file_path ) || ! $url || '' === $format ) {
			continue;
		}

		$fonts[] = array(
			'path'   => $font_path,
			'url'    => $url,
			'format' => $format,
		);
	}

	return $fonts;
}

/**
 * Serve generated CSS with same-origin font URLs. No user-provided CSS is
 * accepted or emitted.
 *
 * @return void
 */
function serve_fonts_css() {
	$fonts = get_public_fonts();
	$css   = '/* Fandoogh Manager local font sheet */' . "\n";

	if ( ! empty( $fonts ) ) {
		$src = array();
		foreach ( $fonts as $font ) {
			$src[] = 'url(' . wp_json_encode( $font['url'] ) . ') format("' . esc_attr( $font['format'] ) . '")';
		}

		$css .= '@font-face{font-family:"FandooghLocal";font-style:normal;font-weight:normal;font-display:swap;src:' . implode( ',', $src ) . ';}' . "\n";
	}

	nocache_headers();
	header( 'Content-Type: text/css; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	echo $css;
	exit;
}

/**
 * @param array<string, mixed> $file Uploaded font.
 * @return string|\WP_Error Managed relative path.
 */
function upload_local_font( $file ) {
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['name'], $file['size'], $file['error'] ) ) {
		return new \WP_Error( 'fandoogh_font_missing', __( 'فایل فونت انتخاب نشده است.', 'fandoogh-manager' ) );
	}

	if ( UPLOAD_ERR_OK !== (int) $file['error'] || absint( $file['size'] ) < 1 || absint( $file['size'] ) > LOCAL_FONT_MAX_BYTES ) {
		return new \WP_Error( 'fandoogh_font_size', __( 'حجم فونت باید حداکثر ۱۰ مگابایت باشد.', 'fandoogh-manager' ) );
	}

	$name      = sanitize_file_name( (string) $file['name'] );
	$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$allowed   = array( 'woff', 'woff2', 'ttf', 'otf' );
	if ( ! in_array( $extension, $allowed, true ) ) {
		return new \WP_Error( 'fandoogh_font_type', __( 'فرمت فونت مجاز نیست.', 'fandoogh-manager' ) );
	}

	$handle = fopen( $file['tmp_name'], 'rb' );
	$magic  = $handle ? fread( $handle, 4 ) : false;
	if ( $handle ) {
		fclose( $handle );
	}

	$signatures = array(
		'woff'  => array( 'wOFF' ),
		'woff2' => array( 'wOF2' ),
		'ttf'   => array( "\x00\x01\x00\x00", 'true', 'ttcf' ),
		'otf'   => array( 'OTTO' ),
	);
	if ( false === $magic || ! in_array( $magic, $signatures[ $extension ], true ) ) {
		return new \WP_Error( 'fandoogh_font_signature', __( 'محتوای فایل با فرمت فونت همخوانی ندارد.', 'fandoogh-manager' ) );
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return new \WP_Error( 'fandoogh_font_storage', __( 'مسیر ذخیره‌سازی فونت در دسترس نیست.', 'fandoogh-manager' ) );
	}

	$font_dir = trailingslashit( $uploads['basedir'] ) . 'fandoogh-manager/fonts';
	if ( ! wp_mkdir_p( $font_dir ) ) {
		return new \WP_Error( 'fandoogh_font_storage', __( 'ساخت مسیر فونت انجام نشد.', 'fandoogh-manager' ) );
	}

	$filename = wp_unique_filename( $font_dir, $name );
	$target   = trailingslashit( $font_dir ) . $filename;
	if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
		return new \WP_Error( 'fandoogh_font_storage', __( 'ذخیرهٔ فونت انجام نشد.', 'fandoogh-manager' ) );
	}

	return 'fandoogh-manager/fonts/' . $filename;
}

/**
 * @param string $font_path Relative managed path.
 * @return bool
 */
function delete_local_font( $font_path ) {
	$font_path = sanitize_managed_font_path( $font_path );
	if ( '' === $font_path ) {
		return false;
	}

	$file_path = managed_font_absolute_path( $font_path );
	if ( '' !== $file_path && file_exists( $file_path ) ) {
		compose_media_storage()->deleteFile( $file_path );
	}

	$settings = get_settings();
	$settings['local_fonts'] = array_values( array_diff( $settings['local_fonts'], array( $font_path ) ) );
	compose_settings_store()->write( $settings );
	return true;
}

/**
 * @return string|\WP_Error
 */
function maybe_handle_font_admin_post() {
	if ( 'POST' !== strtoupper( (string) ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) || empty( $_POST['fandoogh_manager_font_action'] ) ) {
		return '';
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'fandoogh_font_forbidden', __( 'شما اجازهٔ مدیریت فونت را ندارید.', 'fandoogh-manager' ) );
	}

	$action = sanitize_key( (string) $_POST['fandoogh_manager_font_action'] );
	if ( 'upload' === $action ) {
		check_admin_referer( 'fandoogh_manager_font_upload', 'fandoogh_manager_font_nonce' );
		$file = isset( $_FILES['fandoogh_font_file'] ) ? $_FILES['fandoogh_font_file'] : null;
		$path = upload_local_font( $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$settings = get_settings();
		$settings['local_fonts'][] = $path;
		compose_settings_store()->write( $settings );
		record_audit_event( 'font_uploaded', get_current_user_id(), 0, 'wp-admin', 'font', 0, array( 'file_type' => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) );
		return __( 'فونت محلی با موفقیت اضافه شد.', 'fandoogh-manager' );
	}

	if ( 'delete' === $action ) {
		check_admin_referer( 'fandoogh_manager_font_delete', 'fandoogh_manager_font_nonce' );
		$path = isset( $_POST['font_path'] ) ? (string) wp_unslash( $_POST['font_path'] ) : '';
		$deleted = delete_local_font( $path );
		if ( $deleted ) {
			record_audit_event( 'font_deleted', get_current_user_id(), 0, 'wp-admin', 'font', 0, array( 'file_type' => strtolower( pathinfo( sanitize_managed_font_path( $path ), PATHINFO_EXTENSION ) ) ) );
			return __( 'فونت حذف شد.', 'fandoogh-manager' );
		}
		return new \WP_Error( 'fandoogh_font_invalid', __( 'مسیر فونت معتبر نیست.', 'fandoogh-manager' ) );
	}

	return new \WP_Error( 'fandoogh_font_action', __( 'عملیات فونت معتبر نیست.', 'fandoogh-manager' ) );
}

/**
 * @param string|\WP_Error $result Admin action result.
 * @return void
 */
function render_font_admin_section( $result = '' ) {
	echo '<section class="fandoogh-admin-card fandoogh-admin-card--fonts"><p class="fandoogh-eyebrow">' . esc_html__( 'فونت محلی', 'fandoogh-manager' ) . '</p><h2>' . esc_html__( 'بارگذاری و مدیریت فونت', 'fandoogh-manager' ) . '</h2>';

	if ( is_wp_error( $result ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
	} elseif ( is_string( $result ) && '' !== $result ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html( $result ) . '</p></div>';
	}

	echo '<form method="post" action="" enctype="multipart/form-data">';
	wp_nonce_field( 'fandoogh_manager_font_upload', 'fandoogh_manager_font_nonce' );
	echo '<input type="hidden" name="fandoogh_manager_font_action" value="upload">';
	echo '<label for="fandoogh-font-file">' . esc_html__( 'فایل فونت', 'fandoogh-manager' ) . '</label> <input id="fandoogh-font-file" type="file" name="fandoogh_font_file" accept=".woff,.woff2,.ttf,.otf" required>';
	echo '<p class="description">' . esc_html__( 'حداکثر ۱۰ مگابایت؛ فایل باید امضای واقعی فونت داشته باشد.', 'fandoogh-manager' ) . '</p>';
	submit_button( __( 'بارگذاری فونت', 'fandoogh-manager' ), 'secondary fandoogh-admin-button', 'submit', false );
	echo '</form>';

	$fonts = get_settings()['local_fonts'];
	if ( empty( $fonts ) ) {
		echo '<p class="description">' . esc_html__( 'هنوز فونت محلی اضافه نشده است.', 'fandoogh-manager' ) . '</p></section>';
		return;
	}

	echo '<ul>';
	foreach ( $fonts as $font_path ) {
		echo '<li><code>' . esc_html( basename( $font_path ) ) . '</code> ';
		echo '<form method="post" action="" style="display:inline">';
		wp_nonce_field( 'fandoogh_manager_font_delete', 'fandoogh_manager_font_nonce' );
		echo '<input type="hidden" name="fandoogh_manager_font_action" value="delete">';
		echo '<input type="hidden" name="font_path" value="' . esc_attr( $font_path ) . '">';
		submit_button( __( 'Delete', 'fandoogh-manager' ), 'delete small', 'submit', false );
		echo '</form></li>';
	}
	echo '</ul>';
	echo '</section>';
}
