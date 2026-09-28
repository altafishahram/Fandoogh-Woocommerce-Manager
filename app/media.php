<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/composition/media.php';

const MEDIA_UPLOAD_HARD_LIMIT = 20971520;
const MEDIA_RATE_LIMIT = 10;
const MEDIA_RATE_WINDOW = 60;

/**
 * @return void
 */
function register_media_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/media',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\upload_media',
			'permission_callback' => __NAMESPACE__ . '\\media_upload_permission',
		)
	);
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function media_upload_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}

	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! session_has_scope( 'media.upload', $session['scopes'] ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز آپلود رسانه را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	if ( ! user_can( absint( $session['user']->ID ), 'upload_files' ) ) {
		return new \WP_Error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز آپلود فایل ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
	}

	apply_session_user_context( $session );
	return true;
}

/**
 * @param int $session_id Session ID.
 * @return bool
 */
function media_rate_limit_allowed( $session_id ) {
	$key   = 'fandoogh_media_rate_' . absint( $session_id );
	$count = absint( get_transient( $key ) );
	if ( $count >= MEDIA_RATE_LIMIT ) {
		return false;
	}

	set_transient( $key, $count + 1, MEDIA_RATE_WINDOW );
	return true;
}

/**
 * @return array<string, string>
 */
function media_allowed_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	);
}

/**
 * @param array<string, mixed> $file Uploaded file.
 * @param int                  $max_bytes Maximum input bytes.
 * @return array<string, mixed>|\WP_Error
 */
function validate_media_upload( $file, $max_bytes ) {
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'], $file['size'], $file['name'] ) ) {
		return new \WP_Error( 'fandoogh_media_missing', __( 'فایل تصویر ارسال نشده است.', 'fandoogh-manager' ), array( 'status' => 400 ) );
	}

	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		return new \WP_Error( 'fandoogh_media_upload_failed', __( 'آپلود تصویر ناموفق بود.', 'fandoogh-manager' ), array( 'status' => 400 ) );
	}

	$size = absint( $file['size'] );
	if ( $size < 1 || $size > min( MEDIA_UPLOAD_HARD_LIMIT, $max_bytes ) ) {
		return new \WP_Error( 'fandoogh_media_too_large', __( 'حجم تصویر از حد مجاز بیشتر است.', 'fandoogh-manager' ), array( 'status' => 413 ) );
	}

	$original_name = (string) $file['name'];
	$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	$allowed_extensions = array( 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'gif' );
	if ( ! in_array( $extension, $allowed_extensions, true ) ) {
		return new \WP_Error( 'fandoogh_media_type', __( 'فرمت تصویر مجاز نیست.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	$filename = sanitize_file_name( $original_name );
	if ( '' === $filename || strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== $extension ) {
		$filename = 'fandoogh-image.' . $extension;
	}

	$image_info = @getimagesize( $file['tmp_name'] );
	if ( ! is_array( $image_info ) || empty( $image_info[0] ) || empty( $image_info[1] ) || empty( $image_info['mime'] ) ) {
		return new \WP_Error( 'fandoogh_media_invalid_image', __( 'محتوای فایل تصویر معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	if ( (int) $image_info[0] > 12000 || (int) $image_info[1] > 12000 || (int) $image_info[0] * (int) $image_info[1] > 40000000 ) {
		return new \WP_Error( 'fandoogh_media_dimensions', __( 'ابعاد تصویر بیش از حد مجاز است.', 'fandoogh-manager' ), array( 'status' => 422 ) );
	}

	$allowed_mimes = media_allowed_mimes();
	$file_type    = wp_check_filetype_and_ext( $file['tmp_name'], $filename, $allowed_mimes );
	$mime         = ! empty( $file_type['type'] ) ? $file_type['type'] : (string) $image_info['mime'];
	if ( ! in_array( $mime, array_values( $allowed_mimes ), true ) ) {
		return new \WP_Error( 'fandoogh_media_type', __( 'نوع واقعی تصویر مجاز نیست.', 'fandoogh-manager' ), array( 'status' => 415 ) );
	}

	return array(
		'name' => $filename,
		'size' => $size,
		'mime' => $mime,
		'width' => absint( $image_info[0] ),
		'height' => absint( $image_info[1] ),
	);
}

/**
 * @param string $source_path Source file.
 * @param string $source_name Source filename.
 * @param array  $settings Image settings.
 * @return string|\WP_Error WebP path.
 */
function convert_media_to_webp( $source_path, $source_name, $settings ) {
	$storage = compose_media_storage();
	$editor = wp_get_image_editor( $source_path );
	if ( is_wp_error( $editor ) ) {
		return new \WP_Error( 'fandoogh_webp_editor', __( 'ویرایشگر تصویر روی سرور در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	$max_width  = absint( $settings['image_max_width'] );
	$max_height = absint( $settings['image_max_height'] );
	$quality    = absint( $settings['image_quality'] );
	$max_bytes  = absint( $settings['image_max_bytes'] );
	$size       = $editor->get_size();

	if ( is_array( $size ) && ( $size['width'] > $max_width || $size['height'] > $max_height ) ) {
		$resized = $editor->resize( $max_width, $max_height, false );
		if ( is_wp_error( $resized ) ) {
			return new \WP_Error( 'fandoogh_webp_resize', __( 'تغییر ابعاد تصویر انجام نشد.', 'fandoogh-manager' ), array( 'status' => 422 ) );
		}
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['path'] ) ) {
		return new \WP_Error( 'fandoogh_media_storage', __( 'مسیر ذخیره‌سازی رسانه در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	$base_name = sanitize_file_name( pathinfo( $source_name, PATHINFO_FILENAME ) );
	$base_name = '' !== $base_name ? $base_name : 'fandoogh-image';
	$webp_name = wp_unique_filename( $upload_dir['path'], $base_name . '.webp' );
	$webp_path = trailingslashit( $upload_dir['path'] ) . $webp_name;
	$qualities = array_values( array_unique( array( $quality, max( 10, $quality - 10 ), max( 10, $quality - 20 ), 10 ) ) );
	$save_failed = false;

	foreach ( $qualities as $candidate_quality ) {
		$editor->set_quality( $candidate_quality );
		$saved = $editor->save( $webp_path, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			$save_failed = true;
			continue;
		}
		if ( ! is_readable( $webp_path ) ) {
			continue;
		}

		if ( filesize( $webp_path ) <= $max_bytes ) {
			return $webp_path;
		}

		$storage->deleteFile( $webp_path );
	}

	if ( $save_failed ) {
		return new \WP_Error( 'fandoogh_webp_save', __( 'تبدیل تصویر به WebP روی سرور انجام نشد؛ پشتیبانی WebP در GD یا Imagick و دسترسی نوشتن uploads را بررسی کنید.', 'fandoogh-manager' ), array( 'status' => 503 ) );
	}

	return new \WP_Error( 'fandoogh_webp_size', __( 'تصویر WebP حتی با کیفیت پایین‌تر از سقف حجم تنظیم‌شده بزرگ‌تر است.', 'fandoogh-manager' ), array( 'status' => 422 ) );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function upload_media( $request ) {
	$storage = compose_media_storage();
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! media_rate_limit_allowed( $session['id'] ) ) {
		return new \WP_Error( 'fandoogh_rate_limited', __( 'تعداد آپلودها زیاد است؛ بعداً دوباره امتحان کنید.', 'fandoogh-manager' ), array( 'status' => 429 ) );
	}

	$settings = get_settings();
	$file     = $request->get_file_params();
	$input    = isset( $file['file'] ) ? $file['file'] : null;
	$valid    = validate_media_upload( $input, MEDIA_UPLOAD_HARD_LIMIT );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$handled = $storage->handleSideload(
		array_merge(
			$input,
			array(
				'name' => $valid['name'],
			)
		),
		array(
			'test_form' => false,
			'mimes'     => media_allowed_mimes(),
		)
	);
	if ( isset( $handled['error'] ) || empty( $handled['file'] ) ) {
		return new \WP_Error( 'fandoogh_media_storage', __( 'ذخیرهٔ فایل تصویر انجام نشد؛ دسترسی نوشتن پوشهٔ uploads و تنظیمات فرمت فایل را بررسی کنید.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	$source_path = $handled['file'];
	$webp_path   = convert_media_to_webp( $source_path, $valid['name'], $settings );
	if ( is_wp_error( $webp_path ) ) {
		if ( empty( $settings['keep_original'] ) ) {
			$storage->deleteFile( $source_path );
		}
		return $webp_path;
	}

	$attachment_id = $storage->createAttachment(
		array(
			'post_mime_type' => 'image/webp',
			'post_title'     => sanitize_text_field( pathinfo( $valid['name'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$webp_path
	);
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		$storage->deleteFile( $webp_path );
		if ( empty( $settings['keep_original'] ) ) {
			$storage->deleteFile( $source_path );
		}
		return new \WP_Error( 'fandoogh_media_attachment', __( 'ساخت رکورد رسانه انجام نشد.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	$metadata = $storage->generateMetadata( $attachment_id, $webp_path );
	if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
		$storage->updateMetadata( $attachment_id, $metadata );
	}

	if ( empty( $settings['keep_original'] ) ) {
		$storage->deleteFile( $source_path );
	}

	$url = public_asset_url( wp_get_attachment_url( $attachment_id ) );
	if ( ! $url ) {
		$storage->deleteAttachment( $attachment_id, true );
		return new \WP_Error( 'fandoogh_media_origin', __( 'رسانهٔ خروجی روی مبدأ مجاز سایت قرار نگرفت.', 'fandoogh-manager' ), array( 'status' => 500 ) );
	}

	record_audit_event( 'media_uploaded', $session['user']->ID, $session['id'], $session['device_label'], 'media', $attachment_id, array( 'attachment_id' => $attachment_id, 'file_type' => 'image/webp' ) );
	$response = rest_ensure_response(
		array(
			'data' => array(
				'id'              => absint( $attachment_id ),
				'url'             => $url,
				'mime_type'       => 'image/webp',
				'width'           => isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0,
				'height'          => isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0,
				'original_kept'   => ! empty( $settings['keep_original'] ),
				'output_bytes'    => is_readable( $webp_path ) ? absint( filesize( $webp_path ) ) : 0,
			),
		)
	);
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	return $response;
}
