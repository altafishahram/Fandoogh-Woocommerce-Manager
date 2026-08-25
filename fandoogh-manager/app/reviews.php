<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product review moderation through the WordPress comment APIs. Reviews are
 * comments with type `review`; raw comment email, IP and user-agent data stay
 * server-side.
 */
function register_review_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/reviews',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_reviews',
			'permission_callback' => __NAMESPACE__ . '\\reviews_read_permission',
		)
	);

	register_rest_route(
		REST_NAMESPACE,
		'/reviews/(?P<id>\\d+)',
		array(
			'methods'             => 'PUT, PATCH',
			'callback'            => __NAMESPACE__ . '\\moderate_review',
			'permission_callback' => __NAMESPACE__ . '\\reviews_write_permission',
		)
	);
}

function reviews_headers() {
	return array(
		'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                 => 'no-cache',
		'X-Content-Type-Options' => 'nosniff',
	);
}

function reviews_error( $code, $message, $status = 422 ) {
	return new \WP_Error( $code, $message, array( 'status' => absint( $status ), 'headers' => reviews_headers() ) );
}

function reviews_response( $payload ) {
	$response = rest_ensure_response( $payload );
	foreach ( reviews_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}

function reviews_read_permission( $request ) {
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'reviews.read', $session['scopes'] ) ) {
		return reviews_error( 'fandoogh_reviews_forbidden', __( 'نشست فعلی مجوز مشاهدهٔ دیدگاه‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return reviews_error( 'fandoogh_reviews_forbidden', __( 'کاربر WordPress مجوز مشاهدهٔ دیدگاه‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function reviews_write_permission( $request ) {
	$csrf = csrf_permission( $request );
	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}
	$session = get_session_context();
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( ! session_has_scope( 'reviews.write', $session['scopes'] ) ) {
		return reviews_error( 'fandoogh_reviews_write_scope', __( 'نشست فعلی مجوز مدیریت دیدگاه‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	if ( ! session_has_major_changes_access( $session ) ) {
		return reviews_error( 'fandoogh_major_changes_forbidden', __( 'این کاربر مجوز تغییرات اساسی و مدیریت دیدگاه‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	apply_session_user_context( $session );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return reviews_error( 'fandoogh_reviews_forbidden', __( 'کاربر WordPress مجوز مدیریت دیدگاه‌ها را ندارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function review_clean_text( $value, $max = 500 ) {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return '';
	}
	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
}

function serialize_review( $comment ) {
	$comment_id = absint( $comment->comment_ID );
	$product_id = absint( $comment->comment_post_ID );
	$rating     = absint( get_comment_meta( $comment_id, 'rating', true ) );
	$verified   = get_comment_meta( $comment_id, 'verified', true );
	$raw_status = (string) $comment->comment_approved;
	$status     = '1' === $raw_status ? 'approve' : ( '0' === $raw_status ? 'hold' : sanitize_key( $raw_status ) );
	return array(
		'id'          => $comment_id,
		'product_id'  => $product_id,
		'product_name'=> review_clean_text( get_the_title( $product_id ), 160 ),
		'author'      => review_clean_text( $comment->comment_author, 120 ),
		'content'     => review_clean_text( $comment->comment_content, 1000 ),
		'rating'      => max( 0, min( 5, $rating ) ),
		'verified'    => ! empty( $verified ),
		'status'      => $status,
		'date'        => review_clean_text( $comment->comment_date_gmt, 40 ),
		'parent'      => absint( $comment->comment_parent ),
	);
}

function list_reviews( $request ) {
	$page = max( 1, min( 100000, absint( $request->get_param( 'page' ) ?: 1 ) ) );
	$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
	$status = sanitize_key( (string) $request->get_param( 'status' ) );
	$allowed_statuses = array( 'all', 'hold', 'approve', 'spam', 'trash' );
	if ( '' === $status ) {
		$status = 'all';
	}
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		return reviews_error( 'fandoogh_review_invalid_status', __( 'فیلتر وضعیت دیدگاه معتبر نیست.', 'fandoogh-manager' ) );
	}
	$args = array(
		'type'       => 'review',
		'status'     => $status,
		'number'     => $per_page,
		'paged'      => $page,
		'orderby'    => 'comment_date_gmt',
		'order'      => 'DESC',
		'count'      => false,
		'post_type'  => 'product',
	);
	$product_id = absint( $request->get_param( 'product_id' ) );
	if ( $product_id > 0 ) {
		$args['post_id'] = $product_id;
	}
	$search = review_clean_text( $request->get_param( 'search' ), 80 );
	if ( '' !== $search ) {
		$args['search'] = $search;
	}
	try {
		$comments = get_comments( $args );
		$count_args = $args;
		$count_args['number'] = 0;
		$count_args['count'] = true;
		$total = absint( get_comments( $count_args ) );
	} catch ( \Throwable $exception ) {
		return reviews_error( 'fandoogh_reviews_query_failed', __( 'خواندن دیدگاه‌ها انجام نشد.', 'fandoogh-manager' ), 500 );
	}
	$items = array();
	foreach ( (array) $comments as $comment ) {
		if ( $comment instanceof \WP_Comment ) {
			$items[] = serialize_review( $comment );
		}
	}
	return reviews_response( array( 'data' => $items, 'meta' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max( 1, (int) ceil( $total / $per_page ) ) ) ) );
}

function review_request_body( $request ) {
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( false === strpos( $content_type, 'application/json' ) ) {
		return reviews_error( 'fandoogh_review_json_required', __( 'بدنهٔ درخواست دیدگاه باید JSON باشد.', 'fandoogh-manager' ), 415 );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! empty( array_diff( array_keys( $body ), array( 'status', 'reply' ) ) ) ) {
		return reviews_error( 'fandoogh_review_invalid_body', __( 'بدنهٔ درخواست دیدگاه معتبر نیست.', 'fandoogh-manager' ) );
	}
	if ( isset( $body['status'] ) && ! in_array( sanitize_key( (string) $body['status'] ), array( 'hold', 'approve', 'spam', 'trash' ), true ) ) {
		return reviews_error( 'fandoogh_review_invalid_status', __( 'وضعیت دیدگاه معتبر نیست.', 'fandoogh-manager' ) );
	}
	if ( isset( $body['reply'] ) && ( ! is_scalar( $body['reply'] ) || strlen( (string) $body['reply'] ) > 1000 || wp_strip_all_tags( (string) $body['reply'] ) !== (string) $body['reply'] ) ) {
		return reviews_error( 'fandoogh_review_invalid_reply', __( 'پاسخ دیدگاه معتبر نیست.', 'fandoogh-manager' ) );
	}
	return $body;
}

function moderate_review( $request ) {
	$comment_id = absint( $request->get_param( 'id' ) );
	$comment = $comment_id ? get_comment( $comment_id ) : false;
	if ( ! $comment || 'review' !== (string) $comment->comment_type ) {
		return reviews_error( 'fandoogh_review_not_found', __( 'دیدگاه پیدا نشد.', 'fandoogh-manager' ), 404 );
	}
	$body = review_request_body( $request );
	if ( is_wp_error( $body ) || empty( $body ) ) {
		return is_wp_error( $body ) ? $body : reviews_error( 'fandoogh_review_empty_update', __( 'وضعیت یا پاسخ دیدگاه را ارسال کنید.', 'fandoogh-manager' ) );
	}
	if ( isset( $body['status'] ) ) {
		$status = sanitize_key( (string) $body['status'] );
		if ( ! wp_set_comment_status( $comment_id, $status ) ) {
			return reviews_error( 'fandoogh_review_moderation_failed', __( 'تغییر وضعیت دیدگاه انجام نشد.', 'fandoogh-manager' ), 500 );
		}
	}
	if ( isset( $body['reply'] ) && '' !== trim( (string) $body['reply'] ) ) {
		$session = get_session_context();
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		apply_session_user_context( $session );
		$reply_id = wp_insert_comment(
			array(
				'comment_post_ID'  => absint( $comment->comment_post_ID ),
				'comment_parent'   => $comment_id,
				'comment_content'  => sanitize_textarea_field( (string) $body['reply'] ),
				'comment_type'     => 'review',
				'user_id'          => get_current_user_id(),
				'comment_author'   => wp_get_current_user()->display_name,
				'comment_approved' => 1,
			)
		);
		if ( ! $reply_id ) {
			return reviews_error( 'fandoogh_review_reply_failed', __( 'ثبت پاسخ دیدگاه انجام نشد.', 'fandoogh-manager' ), 500 );
		}
		update_comment_meta( $reply_id, 'fandoogh_manager_reply', 1 );
		record_audit_event( 'review_replied', $session['user']->ID, $session['id'], $session['device_label'], 'review', $comment_id, array( 'review_id' => $comment_id ) );
	}
	$session = isset( $session ) && is_array( $session ) ? $session : get_session_context();
	if ( is_array( $session ) ) {
		record_audit_event( 'review_moderated', $session['user']->ID, $session['id'], $session['device_label'], 'review', $comment_id, array( 'review_id' => $comment_id, 'status' => isset( $body['status'] ) ? $body['status'] : 'reply' ) );
	}
	$updated = get_comment( $comment_id );
	return reviews_response( array( 'data' => serialize_review( $updated ) ) );
}
