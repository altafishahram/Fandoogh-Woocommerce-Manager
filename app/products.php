<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/composition/products.php';

/**
 * Register the first authenticated WooCommerce use case. All reads go through
 * the WooCommerce data abstraction; this module contains no SQL or postmeta
 * access.
 *
 * @return void
 */
function register_product_routes() {
    register_rest_route(
        REST_NAMESPACE,
        '/products',
        array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => __NAMESPACE__ . '\\list_products',
                'permission_callback' => __NAMESPACE__ . '\\products_read_permission',
            ),
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => __NAMESPACE__ . '\\create_product',
                'permission_callback' => __NAMESPACE__ . '\\products_write_permission',
            ),
        )
    );

    register_rest_route(
        REST_NAMESPACE,
        '/products/(?P<id>\\d+)',
        array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => __NAMESPACE__ . '\\get_product_detail',
                'permission_callback' => __NAMESPACE__ . '\\products_read_permission',
            ),
            array(
                'methods'             => 'PUT, PATCH',
                'callback'            => __NAMESPACE__ . '\\update_product',
                'permission_callback' => __NAMESPACE__ . '\\products_write_permission',
            ),
        )
    );

    register_rest_route(
        REST_NAMESPACE,
        '/product-shipping-classes',
        array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\list_product_shipping_classes',
            'permission_callback' => __NAMESPACE__ . '\\products_read_permission',
        )
    );

}

/**
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function products_read_permission( $request ) {
    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! session_has_scope( 'products.read', $session['scopes'] ) ) {
        return new \WP_Error( 'fandoogh_forbidden', __( 'نشست فعلی مجوز خواندن محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    apply_session_user_context( $session );
    $user_id = absint( $session['user']->ID );
    if ( ! user_can( $user_id, 'edit_products' ) && ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
        return new \WP_Error( 'fandoogh_forbidden', __( 'کاربر WordPress مجوز خواندن محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    return true;
}

/**
 * Protect product writes with the PWA session, CSRF, a write scope, and the
 * same WordPress capabilities used by the administrative product workflow.
 *
 * The scope is intentionally not granted here. It must be granted by the
 * pairing/session policy in security.php so an existing read-only session
 * cannot silently become a write session.
 *
 * @param \WP_REST_Request $request REST request.
 * @return true|\WP_Error
 */
function products_write_permission( $request ) {
    $csrf_result = csrf_permission( $request );
    if ( is_wp_error( $csrf_result ) ) {
        return $csrf_result;
    }

    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! session_has_scope( 'products.write', $session['scopes'] ) ) {
        return new \WP_Error( 'fandoogh_products_write_scope', __( 'نشست فعلی مجوز نوشتن محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    $route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
    if ( false !== strpos( $route, '/products/bulk-price' ) ) {
        if ( ! session_has_scope( 'products.update', $session['scopes'] ) || ! session_has_major_changes_access( $session ) ) {
            return new \WP_Error( 'fandoogh_major_changes_forbidden', __( 'نشست فعلی مجوز افزایش گروهی و تغییرات اساسی محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
        }
    } elseif ( preg_match( '#/products/\d+#', $route ) ) {
        if ( ! session_has_scope( 'products.update', $session['scopes'] ) ) {
            return new \WP_Error( 'fandoogh_product_edit_forbidden', __( 'نشست فعلی مجوز اصلاح محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
        }
    } elseif ( ! session_has_scope( 'products.create', $session['scopes'] ) ) {
        return new \WP_Error( 'fandoogh_product_create_forbidden', __( 'نشست فعلی مجوز معرفی محصول را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    apply_session_user_context( $session );
    $user_id = absint( $session['user']->ID );
    if ( ! product_user_has_write_capability( $user_id ) ) {
        return new \WP_Error( 'fandoogh_products_write_forbidden', __( 'کاربر WordPress مجوز نوشتن محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    return true;
}

/**
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function product_user_has_write_capability( $user_id ) {
    return user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
}

/**
 * @param int $user_id WordPress user ID.
 * @param int $product_id Product ID.
 * @return bool
 */
function product_user_can_edit_existing( $user_id, $product_id ) {
    if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    return user_can( $user_id, 'edit_products' ) && ( user_can( $user_id, 'edit_product', $product_id ) || user_can( $user_id, 'edit_post', $product_id ) );
}

/**
 * @return bool
 */
function products_available() {
    return compose_product_repository()->isAvailable();
}

/**
 * @param int $user_id WordPress user ID.
 * @return array<int, string>
 */
function readable_product_statuses( $user_id ) {
    $statuses = array( 'publish' );

    if ( user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
        $statuses[] = 'draft';
        $statuses[] = 'pending';
    }

    if ( user_can( $user_id, 'read_private_products' ) || user_can( $user_id, 'edit_private_products' ) || user_can( $user_id, 'manage_options' ) ) {
        $statuses[] = 'private';
    }

    return array_values( array_unique( $statuses ) );
}

/**
 * @param int    $user_id WordPress user ID.
 * @param string $status Product status.
 * @return bool
 */
function product_status_is_readable( $user_id, $status ) {
    return in_array( (string) $status, readable_product_statuses( $user_id ), true );
}

/**
 * @param mixed $value Candidate integer.
 * @param int   $default Default value.
 * @param int   $min Minimum value.
 * @param int   $max Maximum value.
 * @return int
 */
function product_query_integer( $value, $default, $min, $max ) {
    if ( ! is_scalar( $value ) || '' === (string) $value ) {
        return $default;
    }

    return max( $min, min( $max, absint( $value ) ) );
}

/**
 * @param \WP_REST_Response $response REST response.
 * @return \WP_REST_Response
 */
function product_no_store_response( $response ) {
    $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
    $response->header( 'Pragma', 'no-cache' );
    $response->header( 'X-Content-Type-Options', 'nosniff' );
    return $response;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function list_products( $request ) {
    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! products_available() ) {
        return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API محصولات در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
    }

    apply_session_user_context( $session );
    $user_id  = absint( $session['user']->ID );
    $page     = product_query_integer( $request->get_param( 'page' ), 1, 1, 100000 );
    $per_page = product_query_integer( $request->get_param( 'per_page' ), 20, 1, 50 );
    $search   = isset( $request['search'] ) ? sanitize_text_field( (string) $request['search'] ) : '';
    $search   = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
    $status   = isset( $request['status'] ) ? sanitize_key( (string) $request['status'] ) : 'any';
    $allowed_statuses = readable_product_statuses( $user_id );

    if ( 'any' === $status ) {
        $query_status = $allowed_statuses;
    } elseif ( ! in_array( $status, $allowed_statuses, true ) ) {
        return new \WP_Error( 'fandoogh_invalid_status', __( 'فیلتر وضعیت محصول معتبر نیست.', 'fandoogh-manager' ), array( 'status' => 422 ) );
    } else {
        $query_status = array( $status );
    }

    $args = array(
        'limit'   => $per_page,
        'page'    => $page,
        'paginate' => true,
        'return'  => 'objects',
        'status'  => $query_status,
        'orderby' => 'modified',
        'order'   => 'DESC',
    );

    if ( '' !== $search ) {
        // The official WooCommerce query layer passes this bounded search to
        // the product data store without direct SQL in this plugin.
        $args['s'] = $search;
    }

    $category_id = product_query_integer( $request->get_param( 'category_id' ), 0, 0, PHP_INT_MAX );
    if ( $category_id > 0 ) {
        $args['product_category_id'] = array( $category_id );
    }

    $results = compose_product_repository()->query( $args );
    if ( ! is_object( $results ) || ! isset( $results->products ) ) {
        $results = (object) array(
            'products'      => is_array( $results ) ? $results : array(),
            'total'         => is_array( $results ) ? count( $results ) : 0,
            'max_num_pages' => 1,
        );
    }

    $items = array();
    foreach ( (array) $results->products as $product ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            continue;
        }
        $items[] = serialize_product( $product );
    }

    return product_no_store_response(
        rest_ensure_response(
            array(
                'data' => $items,
                'meta' => array(
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => absint( $results->total ),
                    'total_pages' => absint( $results->max_num_pages ),
                ),
            )
        )
    );
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function get_product_detail( $request ) {
    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! products_available() ) {
        return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا API محصولات در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
    }

    apply_session_user_context( $session );
    $product_id = absint( $request['id'] );
    $product    = compose_product_repository()->findById( $product_id );
    if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
        return new \WP_Error( 'fandoogh_product_not_found', __( 'محصول پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
    }

    if ( ! product_status_is_readable( absint( $session['user']->ID ), $product->get_status() ) ) {
        return new \WP_Error( 'fandoogh_product_not_found', __( 'محصول پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
    }

    return product_no_store_response( rest_ensure_response( array( 'data' => serialize_product( $product, true ) ) ) );
}

/**
 * Return the WooCommerce product shipping classes available to the current
 * manager session. A shipping method is selected at checkout/order level;
 * products only carry an optional shipping class.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function list_product_shipping_classes() {
    $terms = get_terms(
        array(
            'taxonomy'   => 'product_shipping_class',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        )
    );

    if ( is_wp_error( $terms ) ) {
        return new \WP_Error( 'fandoogh_shipping_classes_unavailable', __( 'کلاس‌های ارسال ووکامرس در دسترس نیستند.', 'fandoogh-manager' ), array( 'status' => 503 ) );
    }

    $items = array();
    foreach ( (array) $terms as $term ) {
        if ( ! is_object( $term ) ) {
            continue;
        }
        $items[] = array(
            'id'    => absint( $term->term_id ),
            'name'  => sanitize_text_field( $term->name ),
            'slug'  => sanitize_title( $term->slug ),
            'count' => absint( $term->count ),
        );
    }

    return product_no_store_response( rest_ensure_response( array( 'data' => $items ) ) );
}

/**
 * @return bool
 */
function products_write_available() {
    return compose_product_repository()->isWriteAvailable();
}

/**
 * @param string $code Error code.
 * @param string $message Error message.
 * @param int    $status HTTP status.
 * @return \WP_Error
 */
function product_write_error( $code, $message, $status = 422 ) {
    return new \WP_Error( $code, $message, array( 'status' => absint( $status ) ) );
}

/**
 * Accept only taxonomies declared through WooCommerce's global-attribute
 * registry. A random WordPress taxonomy must remain a plain custom attribute
 * and must not be queried as if it contained WooCommerce attribute terms.
 *
 * @param mixed $name Candidate taxonomy name.
 * @return bool
 */
function product_is_global_attribute_taxonomy( $name ) {
    $name = sanitize_title( (string) $name );
    if ( '' === $name || 0 !== strpos( $name, 'pa_' ) || ! taxonomy_exists( $name ) || ! function_exists( 'wc_get_attribute_taxonomies' ) || ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
        return false;
    }

    $slug = substr( $name, 3 );
    return '' !== $slug && wc_attribute_taxonomy_name( $slug ) === $name && ! empty( array_filter(
        (array) wc_get_attribute_taxonomies(),
        function ( $attribute ) use ( $slug ) {
            return is_object( $attribute ) && isset( $attribute->attribute_name ) && sanitize_title( (string) $attribute->attribute_name ) === $slug;
        }
    ) );
}

/**
 * Parse a write body as a JSON object and reject every field outside the
 * deliberately small product-write contract.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array|\WP_Error
 */
function product_write_request_body( $request ) {
    $content_type = strtolower( (string) $request->get_header( 'content-type' ) );
    if ( false === strpos( $content_type, 'application/json' ) ) {
        return product_write_error( 'fandoogh_product_json_required', __( 'بدنهٔ درخواست باید JSON باشد.', 'fandoogh-manager' ), 415 );
    }

    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
        return product_write_error( 'fandoogh_product_invalid_body', __( 'بدنهٔ درخواست محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $allowed_fields = array(
        'name'             => true,
        'slug'             => true,
        'product_kind'     => true,
        'type'             => true,
        'status'           => true,
        'sku'              => true,
        'regular_price'    => true,
        'sale_price'       => true,
        'description'      => true,
        'short_description'=> true,
        'manage_stock'     => true,
        'stock_quantity'   => true,
        'category_ids'     => true,
        'image_id'         => true,
        'gallery_ids'      => true,
        'attributes'       => true,
        'weight'           => true,
        'length'           => true,
        'width'            => true,
        'height'           => true,
        'backorders'       => true,
        'sold_individually' => true,
        'virtual'          => true,
        'downloadable'     => true,
        'download_limit'   => true,
        'download_expiry'  => true,
        'tax_status'       => true,
        'tax_class'        => true,
        'catalog_visibility' => true,
        'shipping_class_id' => true,
        'purchase_note'    => true,
        'reviews_allowed' => true,
        'upsell_ids'       => true,
        'cross_sell_ids'   => true,
    );

    $unknown_fields = array_diff( array_keys( $body ), array_keys( $allowed_fields ) );
    if ( ! empty( $unknown_fields ) ) {
        return product_write_error( 'fandoogh_product_unknown_field', __( 'یکی از فیلدهای محصول در allowlist مجاز نیست.', 'fandoogh-manager' ) );
    }

    return $body;
}

/**
 * Plain text input is accepted, while HTML tags and NUL bytes are rejected
 * instead of being silently stored and rendered later by WooCommerce.
 *
 * @param mixed  $value Candidate value.
 * @param string $field Field name.
 * @param int    $max_length Maximum character count.
 * @param bool   $multiline Whether line breaks are allowed.
 * @return string|\WP_Error
 */
function product_write_plain_text( $value, $field, $max_length, $multiline = false ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'نوع یکی از فیلدهای متنی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $value = (string) $value;
    if ( false !== strpos( $value, "\0" ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'متن محصول شامل کاراکتر غیرمجاز است.', 'fandoogh-manager' ) );
    }

    if ( ! $multiline && preg_match( '/[\r\n\t]/', $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'این فیلد محصول نباید چندخطی باشد.', 'fandoogh-manager' ) );
    }

    if ( wp_strip_all_tags( $value ) !== $value ) {
        return product_write_error( 'fandoogh_product_html_rejected', __( 'HTML خام در فیلدهای محصول پذیرفته نمی‌شود.', 'fandoogh-manager' ) );
    }

    $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
    if ( $length > absint( $max_length ) ) {
        return product_write_error( 'fandoogh_product_field_too_long', __( 'طول یکی از فیلدهای محصول بیشتر از حد مجاز است.', 'fandoogh-manager' ) );
    }

    return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
}

/**
 * Normalize a product slug without allowing arbitrary markup or control
 * characters. WooCommerce will still resolve collisions according to its
 * normal post-slug rules when the CRUD object is saved.
 *
 * @param mixed $value Candidate slug.
 * @return string|\WP_Error
 */
function product_write_slug( $value ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_slug', __( 'نامک محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $raw_slug = trim( (string) $value );
    if ( false !== strpos( $raw_slug, "\0" ) || wp_strip_all_tags( $raw_slug ) !== $raw_slug ) {
        return product_write_error( 'fandoogh_product_invalid_slug', __( 'نامک محصول شامل مقدار غیرمجاز است.', 'fandoogh-manager' ) );
    }

    $slug = sanitize_title( $raw_slug );
    $length = function_exists( 'mb_strlen' ) ? mb_strlen( $slug ) : strlen( $slug );
    if ( $length > 200 ) {
        return product_write_error( 'fandoogh_product_slug_too_long', __( 'نامک محصول بیش از حد طولانی است.', 'fandoogh-manager' ) );
    }

    return $slug;
}

/**
 * Prices are kept as decimal strings so PHP floating-point conversion cannot
 * change the amount before WooCommerce's CRUD object receives it.
 *
 * @param mixed  $value Candidate price.
 * @param string $field Field name.
 * @return string|\WP_Error
 */
function product_write_price( $value, $field ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'قیمت محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }

    if ( strlen( $value ) > 20 || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'قیمت محصول باید عدد اعشاری مثبت با قالب معتبر باشد.', 'fandoogh-manager' ) );
    }

    return $value;
}

/**
 * Normalize a bounded product measurement. WooCommerce stores measurements as
 * decimal strings and accepts an empty value to clear the field.
 */
function product_write_measurement( $value, $field ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'اندازهٔ محصول معتبر نیست.', 'fandoogh-manager' ) );
    }
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    if ( strlen( $value ) > 20 || ! preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'اندازهٔ محصول باید عدد مثبت معتبر باشد.', 'fandoogh-manager' ) );
    }
    return $value;
}

function product_write_tax_status( $value ) {
    $value = sanitize_key( (string) $value );
    if ( ! in_array( $value, array( 'taxable', 'shipping', 'none' ), true ) ) {
        return product_write_error( 'fandoogh_product_invalid_tax_status', __( 'وضعیت مالیاتی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }
    return $value;
}

function product_write_tax_class( $value ) {
    if ( ! is_scalar( $value ) || strlen( (string) $value ) > 64 || ! preg_match( '/^[a-z0-9_-]*$/i', (string) $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_tax_class', __( 'کلاس مالیاتی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }
    return sanitize_title( (string) $value );
}

/**
 * @param mixed $value Catalog visibility value.
 * @return string|\WP_Error
 */
function product_write_catalog_visibility( $value ) {
    $value = sanitize_key( (string) $value );
    if ( ! in_array( $value, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
        return product_write_error( 'fandoogh_product_invalid_catalog_visibility', __( 'نحوهٔ نمایش محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    return $value;
}

/**
 * @param mixed $value Product shipping class ID.
 * @return int|\WP_Error
 */
function product_write_shipping_class_id( $value ) {
    $shipping_class_id = product_write_non_negative_integer( $value, 'shipping_class_id' );
    if ( is_wp_error( $shipping_class_id ) ) {
        return $shipping_class_id;
    }

    if ( $shipping_class_id > 0 && ! term_exists( $shipping_class_id, 'product_shipping_class' ) ) {
        return product_write_error( 'fandoogh_product_shipping_class_not_found', __( 'کلاس ارسال محصول پیدا نشد.', 'fandoogh-manager' ) );
    }

    return $shipping_class_id;
}

/**
 * @param mixed       $value Product kind.
 * @param bool        $is_create Whether this is a new product.
 * @param object|null $product Existing product.
 * @return string|\WP_Error
 */
function product_write_kind( $value, $is_create = false, $product = null ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_kind', __( 'نوع کاربردی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $kind = sanitize_key( (string) $value );
    if ( ! in_array( $kind, array( 'physical', 'downloadable', 'subscription' ), true ) ) {
        return product_write_error( 'fandoogh_product_invalid_kind', __( 'نوع کاربردی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    if ( 'subscription' !== $kind ) {
        return $kind;
    }

    if ( ! class_exists( '\\WC_Product_Subscription' ) && ! class_exists( '\\WC_Product_Variable_Subscription' ) ) {
        return product_write_error( 'fandoogh_subscriptions_required', __( 'برای محصول اشتراکی باید افزونهٔ WooCommerce Subscriptions فعال باشد.', 'fandoogh-manager' ), 503 );
    }

    if ( ! $is_create && $product && method_exists( $product, 'get_type' ) ) {
        $current_type = sanitize_key( (string) $product->get_type() );
        if ( ! in_array( $current_type, array( 'subscription', 'variable-subscription' ), true ) ) {
            return product_write_error( 'fandoogh_product_kind_change_forbidden', __( 'تبدیل محصول موجود به محصول اشتراکی از این مسیر مجاز نیست.', 'fandoogh-manager' ) );
        }
    }

    return $kind;
}

/**
 * @param object|null $product Product object.
 * @return string
 */
function product_kind_from_product( $product ) {
    if ( ! is_object( $product ) ) {
        return 'physical';
    }

    $type = method_exists( $product, 'get_type' ) ? sanitize_key( (string) $product->get_type() ) : '';
    if ( in_array( $type, array( 'subscription', 'variable-subscription' ), true ) ) {
        return 'subscription';
    }
    if ( method_exists( $product, 'is_downloadable' ) && $product->is_downloadable() ) {
        return 'downloadable';
    }

    return 'physical';
}

function product_write_backorders( $value ) {
    $value = sanitize_key( (string) $value );
    if ( ! in_array( $value, array( 'no', 'notify', 'yes' ), true ) ) {
        return product_write_error( 'fandoogh_product_invalid_backorders', __( 'تنظیم پیش‌فروش محصول معتبر نیست.', 'fandoogh-manager' ) );
    }
    return $value;
}

function product_write_product_ids( $value, $field ) {
    $ids = product_write_id_list( $value, $field, 20 );
    if ( is_wp_error( $ids ) ) {
        return $ids;
    }
    foreach ( $ids as $product_id ) {
        if ( ! compose_product_repository()->findById( $product_id ) ) {
            return product_write_error( 'fandoogh_product_related_not_found', __( 'یکی از محصولات مرتبط پیدا نشد.', 'fandoogh-manager' ) );
        }
    }
    return $ids;
}

/**
 * @param mixed  $value Candidate boolean.
 * @param string $field Field name.
 * @return bool|\WP_Error
 */
function product_write_boolean( $value, $field ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( 0 === $value || '0' === $value ) {
        return false;
    }

    if ( 1 === $value || '1' === $value ) {
        return true;
    }

    return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'مقدار بولی محصول باید true یا false باشد.', 'fandoogh-manager' ) );
}

/**
 * @param mixed  $value Candidate non-negative integer.
 * @param string $field Field name.
 * @param bool   $allow_null Whether null is accepted.
 * @return int|null|\WP_Error
 */
function product_write_non_negative_integer( $value, $field, $allow_null = false ) {
    if ( $allow_null && null === $value ) {
        return null;
    }

    if ( is_int( $value ) ) {
        $integer = $value;
    } elseif ( is_string( $value ) && preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', $value ) ) {
        $integer = (int) $value;
    } elseif ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
        $integer = (int) $value;
    } else {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'مقدار عددی محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    if ( $integer < 0 || $integer > 2147483647 ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'مقدار عددی محصول خارج از محدوده است.', 'fandoogh-manager' ) );
    }

    return $integer;
}

/**
 * @param mixed  $value Candidate integer list.
 * @param string $field Field name.
 * @param int    $max_items Maximum item count.
 * @return array<int, int>|\WP_Error
 */
function product_write_id_list( $value, $field, $max_items ) {
    if ( ! is_array( $value ) || count( $value ) > absint( $max_items ) ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'فهرست شناسه‌های محصول معتبر یا در محدوده نیست.', 'fandoogh-manager' ) );
    }

    $ids = array();
    foreach ( $value as $candidate ) {
        $id = product_write_non_negative_integer( $candidate, $field );
        if ( is_wp_error( $id ) || 0 === $id ) {
            return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'شناسهٔ یکی از موارد محصول معتبر نیست.', 'fandoogh-manager' ) );
        }

        if ( in_array( $id, $ids, true ) ) {
            return product_write_error( 'fandoogh_product_duplicate_' . sanitize_key( $field ), __( 'شناسه‌های تکراری در فهرست محصول مجاز نیست.', 'fandoogh-manager' ) );
        }

        $ids[] = $id;
    }

    return $ids;
}

/**
 * Validate category IDs using WordPress taxonomy APIs; this does not create
 * terms or accept arbitrary taxonomy/meta input.
 *
 * @param mixed $value Category ID list.
 * @return array<int, int>|\WP_Error
 */
function product_write_categories( $value ) {
    $category_ids = product_write_id_list( $value, 'category_ids', 100 );
    if ( is_wp_error( $category_ids ) ) {
        return $category_ids;
    }

    foreach ( $category_ids as $category_id ) {
        if ( ! term_exists( $category_id, 'product_cat' ) ) {
            return product_write_error( 'fandoogh_product_category_not_found', __( 'یکی از دسته‌بندی‌های محصول پیدا نشد.', 'fandoogh-manager' ) );
        }
    }

    return $category_ids;
}

/**
 * Validate a local image attachment without accepting URLs or arbitrary
 * metadata. Existing media can only be assigned by a user allowed to work
 * with the WordPress media library.
 *
 * @param mixed  $value Attachment ID.
 * @param string $field Field name.
 * @param int    $user_id WordPress user ID.
 * @param bool   $allow_clear Whether null/zero clears the field.
 * @return int|\WP_Error
 */
function product_write_attachment( $value, $field, $user_id, $allow_clear = true ) {
    if ( $allow_clear && ( null === $value || 0 === $value || '0' === $value ) ) {
        return 0;
    }

    $attachment_id = product_write_non_negative_integer( $value, $field );
    if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
        return product_write_error( 'fandoogh_product_invalid_' . sanitize_key( $field ), __( 'شناسهٔ تصویر محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $attachment = get_post( $attachment_id );
    if ( ! $attachment || 'attachment' !== $attachment->post_type || 'trash' === $attachment->post_status || ! wp_attachment_is_image( $attachment_id ) ) {
        return product_write_error( 'fandoogh_product_invalid_image', __( 'تصویر محصول باید یک فایل تصویری محلی معتبر باشد.', 'fandoogh-manager' ) );
    }

    if ( ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, 'edit_post', $attachment_id ) ) {
        return product_write_error( 'fandoogh_product_image_forbidden', __( 'کاربر اجازهٔ استفاده از این تصویر را ندارد.', 'fandoogh-manager' ), 403 );
    }

    return $attachment_id;
}

/**
 * @param mixed $value Candidate status.
 * @param int   $user_id WordPress user ID.
 * @return string|\WP_Error
 */
function product_write_status( $value, $user_id ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_status', __( 'وضعیت محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $status = trim( (string) $value );
    $allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
    if ( ! in_array( $status, $allowed_statuses, true ) ) {
        return product_write_error( 'fandoogh_product_invalid_status', __( 'وضعیت محصول در allowlist مجاز نیست.', 'fandoogh-manager' ) );
    }

    $is_manager = user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
    if ( 'publish' === $status && ! $is_manager && ! user_can( $user_id, 'publish_products' ) ) {
        return product_write_error( 'fandoogh_product_publish_forbidden', __( 'کاربر مجوز انتشار محصول را ندارد.', 'fandoogh-manager' ), 403 );
    }

    if ( 'private' === $status && ! $is_manager && ! user_can( $user_id, 'edit_private_products' ) ) {
        return product_write_error( 'fandoogh_product_private_forbidden', __( 'کاربر مجوز محصول خصوصی را ندارد.', 'fandoogh-manager' ), 403 );
    }

    return $status;
}

/**
 * Accept only the product types supported by the manager contract. Changing
 * an existing product between simple and variable is deliberately rejected;
 * WooCommerce stores those types through different CRUD objects and a silent
 * conversion could orphan variations or prices.
 *
 * @param mixed       $value Candidate product type.
 * @param bool        $is_create Whether this is a new product.
 * @param object|null $product Existing product.
 * @return string|\WP_Error
 */
function product_write_type( $value, $is_create = false, $product = null ) {
    $current = $product && method_exists( $product, 'get_type' ) ? sanitize_key( (string) $product->get_type() ) : 'simple';
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return product_write_error( 'fandoogh_product_invalid_type', __( 'نوع محصول معتبر نیست.', 'fandoogh-manager' ) );
    }

    $type = sanitize_key( (string) $value );
    $allowed_types = array( 'simple', 'variable' );
    if ( class_exists( '\\WC_Product_Subscription' ) ) {
        $allowed_types[] = 'subscription';
    }
    if ( class_exists( '\\WC_Product_Variable_Subscription' ) ) {
        $allowed_types[] = 'variable-subscription';
    }
    if ( ! in_array( $type, $allowed_types, true ) ) {
        return product_write_error( 'fandoogh_product_invalid_type', __( 'ساختار محصول انتخاب‌شده توسط ووکامرس یا افزونهٔ اشتراک پشتیبانی نمی‌شود.', 'fandoogh-manager' ) );
    }

    if ( ! $is_create && $type !== $current ) {
        return product_write_error( 'fandoogh_product_type_change_forbidden', __( 'تغییر نوع محصول موجود از این مسیر مجاز نیست.', 'fandoogh-manager' ) );
    }

    return $type;
}

/**
 * Normalize the small, explicit product-attribute contract. Global
 * WooCommerce attributes use existing term IDs; custom attributes use local
 * text values. Creating arbitrary taxonomies or accepting raw meta is outside
 * this API.
 *
 * @param mixed $value Candidate attributes.
 * @return array<int, array<string, mixed>>|\WP_Error
 */
function product_write_attributes( $value ) {
    if ( ! is_array( $value ) || count( $value ) > 30 ) {
        return product_write_error( 'fandoogh_product_invalid_attributes', __( 'ویژگی‌های محصول باید یک فهرست محدود و معتبر باشند.', 'fandoogh-manager' ) );
    }

    $attributes = array();
    $seen       = array();
    foreach ( array_values( $value ) as $attribute ) {
        if ( ! is_array( $attribute ) ) {
            return product_write_error( 'fandoogh_product_invalid_attribute', __( 'ساختار یکی از ویژگی‌های محصول معتبر نیست.', 'fandoogh-manager' ) );
        }

        $unknown_fields = array_diff( array_keys( $attribute ), array( 'name', 'label', 'options', 'visible', 'variation' ) );
        if ( ! empty( $unknown_fields ) ) {
            return product_write_error( 'fandoogh_product_unknown_attribute_field', __( 'یکی از فیلدهای ویژگی محصول مجاز نیست.', 'fandoogh-manager' ) );
        }

        $raw_name = isset( $attribute['name'] ) ? $attribute['name'] : '';
        if ( ! is_scalar( $raw_name ) || is_bool( $raw_name ) ) {
            return product_write_error( 'fandoogh_product_invalid_attribute_name', __( 'نام ویژگی محصول معتبر نیست.', 'fandoogh-manager' ) );
        }

        $name = sanitize_title( (string) $raw_name );
        if ( '' === $name || strlen( $name ) > 64 ) {
            return product_write_error( 'fandoogh_product_invalid_attribute_name', __( 'نام ویژگی محصول معتبر نیست.', 'fandoogh-manager' ) );
        }
        if ( isset( $seen[ $name ] ) ) {
            return product_write_error( 'fandoogh_product_duplicate_attribute', __( 'یک ویژگی محصول بیش از یک‌بار تعریف شده است.', 'fandoogh-manager' ) );
        }
        $seen[ $name ] = true;

        $is_global = product_is_global_attribute_taxonomy( $name );
        $options   = isset( $attribute['options'] ) ? $attribute['options'] : array();
        if ( ! is_array( $options ) || count( $options ) > 100 ) {
            return product_write_error( 'fandoogh_product_invalid_attribute_options', __( 'مقادیر ویژگی محصول معتبر نیستند.', 'fandoogh-manager' ) );
        }

        $normalized_options = array();
        foreach ( $options as $option ) {
            if ( $is_global ) {
                $term_id = product_write_non_negative_integer( $option, 'attribute_option' );
                if ( is_wp_error( $term_id ) || 0 === $term_id ) {
                    return product_write_error( 'fandoogh_product_invalid_attribute_term', __( 'یکی از مقادیر ویژگی سراسری معتبر نیست.', 'fandoogh-manager' ) );
                }
                $term = get_term( $term_id, $name );
                if ( ! $term || is_wp_error( $term ) ) {
                    return product_write_error( 'fandoogh_product_invalid_attribute_term', __( 'یکی از مقادیر ویژگی سراسری پیدا نشد.', 'fandoogh-manager' ) );
                }
                $normalized_options[] = $term_id;
            } else {
                $text = product_write_plain_text( $option, 'attribute_option', 200 );
                if ( is_wp_error( $text ) || '' === trim( $text ) ) {
                    return product_write_error( 'fandoogh_product_invalid_attribute_option', __( 'مقدار ویژگی سفارشی معتبر نیست.', 'fandoogh-manager' ) );
                }
                $normalized_options[] = $text;
            }
        }

        $normalized_options = array_values( array_unique( $normalized_options ) );
        if ( empty( $normalized_options ) ) {
            return product_write_error( 'fandoogh_product_attribute_options_required', __( 'برای هر ویژگی حداقل یک مقدار وارد کنید.', 'fandoogh-manager' ) );
        }

        $label = isset( $attribute['label'] ) ? product_write_plain_text( $attribute['label'], 'attribute_label', 120 ) : '';
        if ( is_wp_error( $label ) ) {
            return $label;
        }
        if ( '' === trim( $label ) ) {
            $taxonomy = get_taxonomy( $name );
            $label    = $taxonomy && isset( $taxonomy->label ) ? sanitize_text_field( $taxonomy->label ) : $name;
        }

        $visible  = isset( $attribute['visible'] ) ? product_write_boolean( $attribute['visible'], 'attribute_visible' ) : true;
        $variation = isset( $attribute['variation'] ) ? product_write_boolean( $attribute['variation'], 'attribute_variation' ) : false;
        if ( is_wp_error( $visible ) ) {
            return $visible;
        }
        if ( is_wp_error( $variation ) ) {
            return $variation;
        }

        $attributes[] = array(
            'id'        => function_exists( 'wc_attribute_taxonomy_id_by_name' ) && $is_global ? absint( wc_attribute_taxonomy_id_by_name( $name ) ) : 0,
            'name'      => $name,
            'label'     => $label,
            'options'   => $normalized_options,
            'visible'   => (bool) $visible,
            'variation' => (bool) $variation,
            'global'    => $is_global,
        );
    }

    return $attributes;
}

/**
 * Validate the limited write contract before calling any WooCommerce setter.
 *
 * @param array       $body Request body.
 * @param int         $user_id WordPress user ID.
 * @param bool        $is_create Whether this is a new product.
 * @param object|null $product Existing CRUD object.
 * @return array<string, mixed>|\WP_Error
 */
function product_write_values( $body, $user_id, $is_create = false, $product = null ) {
    $values = array();

    if ( $is_create && ! array_key_exists( 'name', $body ) ) {
        return product_write_error( 'fandoogh_product_name_required', __( 'نام محصول الزامی است.', 'fandoogh-manager' ) );
    }

    if ( array_key_exists( 'name', $body ) ) {
        $values['name'] = product_write_plain_text( $body['name'], 'name', 200 );
        if ( is_wp_error( $values['name'] ) ) {
            return $values['name'];
        }
        if ( '' === trim( $values['name'] ) ) {
            return product_write_error( 'fandoogh_product_name_required', __( 'نام محصول نمی‌تواند خالی باشد.', 'fandoogh-manager' ) );
        }
    }

    if ( array_key_exists( 'slug', $body ) ) {
        $values['slug'] = product_write_slug( $body['slug'] );
        if ( is_wp_error( $values['slug'] ) ) {
            return $values['slug'];
        }
    } elseif ( $is_create && isset( $values['name'] ) ) {
        $values['slug'] = sanitize_title( $values['name'] );
    }

    if ( array_key_exists( 'type', $body ) ) {
        $values['type'] = product_write_type( $body['type'], $is_create, $product );
        if ( is_wp_error( $values['type'] ) ) {
            return $values['type'];
        }
    } elseif ( $is_create ) {
        $values['type'] = 'simple';
    } elseif ( $product && method_exists( $product, 'get_type' ) ) {
        $values['type'] = sanitize_key( (string) $product->get_type() );
    }

    if ( array_key_exists( 'product_kind', $body ) ) {
        $values['product_kind'] = product_write_kind( $body['product_kind'], $is_create, $product );
        if ( is_wp_error( $values['product_kind'] ) ) {
            return $values['product_kind'];
        }

        if ( 'subscription' === $values['product_kind'] && $is_create ) {
            $values['type'] = 'variable' === $values['type'] ? 'variable-subscription' : 'subscription';
        }
        if ( 'physical' === $values['product_kind'] ) {
            $values['virtual']      = false;
            $values['downloadable'] = false;
        } elseif ( 'downloadable' === $values['product_kind'] ) {
            $values['virtual']      = true;
            $values['downloadable'] = true;
        }
    }

    if ( array_key_exists( 'status', $body ) ) {
        $values['status'] = product_write_status( $body['status'], $user_id );
        if ( is_wp_error( $values['status'] ) ) {
            return $values['status'];
        }
    } elseif ( $is_create ) {
        $values['status'] = 'draft';
    }

    foreach ( array( 'sku' => 100 ) as $field => $max_length ) {
        if ( ! array_key_exists( $field, $body ) ) {
            continue;
        }

        $values[ $field ] = product_write_plain_text( $body[ $field ], $field, $max_length );
        if ( is_wp_error( $values[ $field ] ) ) {
            return $values[ $field ];
        }

        if ( 'sku' === $field && '' !== $values[ $field ] && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $existing_id = compose_product_repository()->findIdBySku( $values[ $field ] );
            $current_id  = $product && method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0;
            if ( $existing_id && $existing_id !== $current_id ) {
                return product_write_error( 'fandoogh_product_duplicate_sku', __( 'این SKU قبلاً استفاده شده است.', 'fandoogh-manager' ), 409 );
            }
        }
    }

    foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
        if ( ! array_key_exists( $field, $body ) ) {
            continue;
        }

        $values[ $field ] = product_write_price( $body[ $field ], $field );
        if ( is_wp_error( $values[ $field ] ) ) {
            return $values[ $field ];
        }
    }

    foreach ( array( 'description' => 20000, 'short_description' => 5000 ) as $field => $max_length ) {
        if ( ! array_key_exists( $field, $body ) ) {
            continue;
        }

        $values[ $field ] = product_write_plain_text( $body[ $field ], $field, $max_length, true );
        if ( is_wp_error( $values[ $field ] ) ) {
            return $values[ $field ];
        }
    }

    if ( array_key_exists( 'manage_stock', $body ) ) {
        $values['manage_stock'] = product_write_boolean( $body['manage_stock'], 'manage_stock' );
        if ( is_wp_error( $values['manage_stock'] ) ) {
            return $values['manage_stock'];
        }
    }

    if ( array_key_exists( 'stock_quantity', $body ) ) {
        $values['stock_quantity'] = product_write_non_negative_integer( $body['stock_quantity'], 'stock_quantity', true );
        if ( is_wp_error( $values['stock_quantity'] ) ) {
            return $values['stock_quantity'];
        }

        if ( null !== $values['stock_quantity'] && array_key_exists( 'manage_stock', $values ) && ! $values['manage_stock'] ) {
            return product_write_error( 'fandoogh_product_stock_management_required', __( 'برای تعیین موجودی، manage_stock باید true باشد.', 'fandoogh-manager' ) );
        }

        if ( null !== $values['stock_quantity'] && ! array_key_exists( 'manage_stock', $values ) ) {
            $values['manage_stock'] = true;
        }
    }

    foreach ( array( 'weight', 'length', 'width', 'height' ) as $field ) {
        if ( array_key_exists( $field, $body ) ) {
            $values[ $field ] = product_write_measurement( $body[ $field ], $field );
            if ( is_wp_error( $values[ $field ] ) ) {
                return $values[ $field ];
            }
        }
    }

    if ( array_key_exists( 'backorders', $body ) ) {
        $values['backorders'] = product_write_backorders( $body['backorders'] );
        if ( is_wp_error( $values['backorders'] ) ) {
            return $values['backorders'];
        }
    }

    foreach ( array( 'sold_individually', 'virtual', 'downloadable' ) as $field ) {
        if ( array_key_exists( $field, $body ) ) {
            $values[ $field ] = product_write_boolean( $body[ $field ], $field );
            if ( is_wp_error( $values[ $field ] ) ) {
                return $values[ $field ];
            }
        }
    }

    foreach ( array( 'download_limit', 'download_expiry' ) as $field ) {
        if ( array_key_exists( $field, $body ) ) {
            $values[ $field ] = product_write_non_negative_integer( $body[ $field ], $field, true );
            if ( is_wp_error( $values[ $field ] ) ) {
                return $values[ $field ];
            }
        }
    }

    if ( array_key_exists( 'tax_status', $body ) ) {
        $values['tax_status'] = product_write_tax_status( $body['tax_status'] );
        if ( is_wp_error( $values['tax_status'] ) ) {
            return $values['tax_status'];
        }
    }

    if ( array_key_exists( 'tax_class', $body ) ) {
        $values['tax_class'] = product_write_tax_class( $body['tax_class'] );
        if ( is_wp_error( $values['tax_class'] ) ) {
            return $values['tax_class'];
        }
    }

    if ( array_key_exists( 'catalog_visibility', $body ) ) {
        $values['catalog_visibility'] = product_write_catalog_visibility( $body['catalog_visibility'] );
        if ( is_wp_error( $values['catalog_visibility'] ) ) {
            return $values['catalog_visibility'];
        }
    }

    if ( array_key_exists( 'shipping_class_id', $body ) ) {
        $values['shipping_class_id'] = product_write_shipping_class_id( $body['shipping_class_id'] );
        if ( is_wp_error( $values['shipping_class_id'] ) ) {
            return $values['shipping_class_id'];
        }
    }

    if ( array_key_exists( 'purchase_note', $body ) ) {
        $values['purchase_note'] = product_write_plain_text( $body['purchase_note'], 'purchase_note', 1000, true );
        if ( is_wp_error( $values['purchase_note'] ) ) {
            return $values['purchase_note'];
        }
    }

    if ( array_key_exists( 'reviews_allowed', $body ) ) {
        $values['reviews_allowed'] = product_write_boolean( $body['reviews_allowed'], 'reviews_allowed' );
        if ( is_wp_error( $values['reviews_allowed'] ) ) {
            return $values['reviews_allowed'];
        }
    }

    foreach ( array( 'upsell_ids', 'cross_sell_ids' ) as $field ) {
        if ( array_key_exists( $field, $body ) ) {
            $values[ $field ] = product_write_product_ids( $body[ $field ], $field );
            if ( is_wp_error( $values[ $field ] ) ) {
                return $values[ $field ];
            }
        }
    }

    if ( array_key_exists( 'category_ids', $body ) ) {
        $values['category_ids'] = product_write_categories( $body['category_ids'] );
        if ( is_wp_error( $values['category_ids'] ) ) {
            return $values['category_ids'];
        }
    }

    if ( array_key_exists( 'attributes', $body ) ) {
        $values['attributes'] = product_write_attributes( $body['attributes'] );
        if ( is_wp_error( $values['attributes'] ) ) {
            return $values['attributes'];
        }
    }

    if ( in_array( isset( $values['type'] ) ? $values['type'] : '', array( 'variable', 'variable-subscription' ), true ) ) {
        if ( $is_create && ! array_key_exists( 'attributes', $values ) ) {
            return product_write_error( 'fandoogh_product_variable_attributes_required', __( 'برای ساخت محصول متغیر حداقل یک ویژگی لازم است.', 'fandoogh-manager' ) );
        }

        if ( array_key_exists( 'attributes', $values ) ) {
            $has_variation_attribute = false;
            foreach ( $values['attributes'] as $attribute ) {
                if ( ! empty( $attribute['variation'] ) ) {
                    $has_variation_attribute = true;
                    break;
                }
            }

            if ( ! $has_variation_attribute ) {
                return product_write_error( 'fandoogh_product_variation_attribute_required', __( 'حداقل یک ویژگی محصول متغیر باید برای variation فعال باشد.', 'fandoogh-manager' ) );
            }
        }
    }

    if ( array_key_exists( 'image_id', $body ) ) {
        $values['image_id'] = product_write_attachment( $body['image_id'], 'image_id', $user_id );
        if ( is_wp_error( $values['image_id'] ) ) {
            return $values['image_id'];
        }
    }

    if ( array_key_exists( 'gallery_ids', $body ) ) {
        $gallery_ids = product_write_id_list( $body['gallery_ids'], 'gallery_ids', 20 );
        if ( is_wp_error( $gallery_ids ) ) {
            return $gallery_ids;
        }

        $values['gallery_ids'] = array();
        foreach ( $gallery_ids as $gallery_id ) {
            $validated_id = product_write_attachment( $gallery_id, 'gallery_ids', $user_id, false );
            if ( is_wp_error( $validated_id ) ) {
                return $validated_id;
            }
            $values['gallery_ids'][] = $validated_id;
        }

        if ( isset( $values['image_id'] ) && $values['image_id'] > 0 && in_array( $values['image_id'], $values['gallery_ids'], true ) ) {
            return product_write_error( 'fandoogh_product_duplicate_image', __( 'تصویر شاخص نباید هم‌زمان در گالری تکرار شود.', 'fandoogh-manager' ) );
        }
    }

    return $values;
}

/**
 * Apply only already-validated values through WooCommerce's CRUD setters.
 *
 * @param object               $product WooCommerce CRUD object.
 * @param array<string, mixed> $values Validated values.
 * @return true|\WP_Error
 */
function product_write_apply_values( $product, $values ) {
    try {
        if ( array_key_exists( 'name', $values ) ) {
            $product->set_name( $values['name'] );
        }
        if ( array_key_exists( 'slug', $values ) && method_exists( $product, 'set_slug' ) ) {
            $product->set_slug( $values['slug'] );
        }
        if ( array_key_exists( 'status', $values ) ) {
            $product->set_status( $values['status'] );
        }
        if ( array_key_exists( 'sku', $values ) ) {
            $product->set_sku( $values['sku'] );
        }
        if ( array_key_exists( 'regular_price', $values ) ) {
            $product->set_regular_price( $values['regular_price'] );
        }
        if ( array_key_exists( 'sale_price', $values ) ) {
            $product->set_sale_price( $values['sale_price'] );
        }
        if ( array_key_exists( 'description', $values ) ) {
            $product->set_description( $values['description'] );
        }
        if ( array_key_exists( 'short_description', $values ) ) {
            $product->set_short_description( $values['short_description'] );
        }
        if ( array_key_exists( 'manage_stock', $values ) ) {
            $product->set_manage_stock( $values['manage_stock'] );
        }
        if ( array_key_exists( 'stock_quantity', $values ) ) {
            $product->set_stock_quantity( $values['stock_quantity'] );
        }
        foreach ( array( 'weight', 'length', 'width', 'height' ) as $field ) {
            if ( array_key_exists( $field, $values ) && method_exists( $product, 'set_' . $field ) ) {
                $product->{'set_' . $field}( $values[ $field ] );
            }
        }
        if ( array_key_exists( 'backorders', $values ) && method_exists( $product, 'set_backorders' ) ) {
            $product->set_backorders( $values['backorders'] );
        }
        foreach ( array( 'sold_individually', 'virtual', 'downloadable' ) as $field ) {
            if ( array_key_exists( $field, $values ) && method_exists( $product, 'set_' . $field ) ) {
                $product->{'set_' . $field}( $values[ $field ] );
            }
        }
        foreach ( array( 'download_limit', 'download_expiry' ) as $field ) {
            if ( array_key_exists( $field, $values ) && method_exists( $product, 'set_' . $field ) ) {
                $product->{'set_' . $field}( $values[ $field ] );
            }
        }
        if ( array_key_exists( 'tax_status', $values ) && method_exists( $product, 'set_tax_status' ) ) {
            $product->set_tax_status( $values['tax_status'] );
        }
        if ( array_key_exists( 'tax_class', $values ) && method_exists( $product, 'set_tax_class' ) ) {
            $product->set_tax_class( $values['tax_class'] );
        }
        if ( array_key_exists( 'catalog_visibility', $values ) && method_exists( $product, 'set_catalog_visibility' ) ) {
            $product->set_catalog_visibility( $values['catalog_visibility'] );
        }
        if ( array_key_exists( 'shipping_class_id', $values ) && method_exists( $product, 'set_shipping_class_id' ) ) {
            $product->set_shipping_class_id( $values['shipping_class_id'] );
        }
        if ( array_key_exists( 'purchase_note', $values ) && method_exists( $product, 'set_purchase_note' ) ) {
            $product->set_purchase_note( $values['purchase_note'] );
        }
        if ( array_key_exists( 'reviews_allowed', $values ) && method_exists( $product, 'set_reviews_allowed' ) ) {
            $product->set_reviews_allowed( $values['reviews_allowed'] );
        }
        if ( array_key_exists( 'upsell_ids', $values ) && method_exists( $product, 'set_upsell_ids' ) ) {
            $product->set_upsell_ids( $values['upsell_ids'] );
        }
        if ( array_key_exists( 'cross_sell_ids', $values ) && method_exists( $product, 'set_cross_sell_ids' ) ) {
            $product->set_cross_sell_ids( $values['cross_sell_ids'] );
        }
        if ( array_key_exists( 'category_ids', $values ) ) {
            $product->set_category_ids( $values['category_ids'] );
        }
        if ( array_key_exists( 'attributes', $values ) ) {
            $attribute_objects = array();
            foreach ( $values['attributes'] as $position => $attribute_data ) {
                $attribute = compose_product_repository()->createAttribute();
                $attribute->set_id( absint( $attribute_data['id'] ) );
                $attribute->set_name( $attribute_data['name'] );
                $attribute->set_options( $attribute_data['options'] );
                $attribute->set_position( absint( $position ) );
                $attribute->set_visible( (bool) $attribute_data['visible'] );
                $attribute->set_variation( (bool) $attribute_data['variation'] );
                $attribute_objects[] = $attribute;
            }
            $product->set_attributes( $attribute_objects );
        }
        if ( array_key_exists( 'image_id', $values ) ) {
            $product->set_image_id( $values['image_id'] );
        }
        if ( array_key_exists( 'gallery_ids', $values ) ) {
            $product->set_gallery_image_ids( $values['gallery_ids'] );
        }
    } catch ( \Throwable $exception ) {
        return product_write_error( 'fandoogh_product_crud_rejected', __( 'WooCommerce داده‌های محصول را نپذیرفت.', 'fandoogh-manager' ) );
    }

    return true;
}

/**
 * Save a CRUD object and re-read it through WooCommerce so the response
 * contains normalized prices, status, stock, categories, and images.
 *
 * @param object $product WooCommerce CRUD object.
 * @return object|\WP_Error
 */
function product_write_save( $product ) {
    try {
        $saved_id = absint( $product->save() );
    } catch ( \Throwable $exception ) {
        return product_write_error( 'fandoogh_product_save_failed', __( 'ذخیرهٔ محصول انجام نشد.', 'fandoogh-manager' ), 500 );
    }

    if ( 0 === $saved_id && method_exists( $product, 'get_id' ) ) {
        $saved_id = absint( $product->get_id() );
    }

    $saved_product = compose_product_repository()->findById( $saved_id );
    if ( ! $saved_product || ! method_exists( $saved_product, 'get_id' ) ) {
        return product_write_error( 'fandoogh_product_save_failed', __( 'محصول پس از ذخیره قابل بازیابی نیست.', 'fandoogh-manager' ), 500 );
    }

    return $saved_product;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function create_product( $request ) {
    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! products_write_available() ) {
        return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا CRUD محصول در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
    }

    apply_session_user_context( $session );
    $user_id = absint( $session['user']->ID );
    if ( ! product_user_has_write_capability( $user_id ) ) {
        return new \WP_Error( 'fandoogh_products_write_forbidden', __( 'کاربر WordPress مجوز نوشتن محصولات را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    $body = product_write_request_body( $request );
    if ( is_wp_error( $body ) ) {
        return $body;
    }

    $values = product_write_values( $body, $user_id, true );
    if ( is_wp_error( $values ) ) {
        return $values;
    }

    $product_type = isset( $values['type'] ) ? $values['type'] : 'simple';
    $product = compose_product_repository()->create( $product_type );
    if ( null === $product && in_array( $product_type, array( 'subscription', 'variable-subscription' ), true ) ) {
        return product_write_error( 'fandoogh_subscriptions_required', __( 'ساخت محصول اشتراکی به کلاس محصول افزونهٔ WooCommerce Subscriptions نیاز دارد.', 'fandoogh-manager' ), 503 );
    }
    $applied = product_write_apply_values( $product, $values );
    if ( is_wp_error( $applied ) ) {
        return $applied;
    }

    $saved_product = product_write_save( $product );
    if ( is_wp_error( $saved_product ) ) {
        return $saved_product;
    }

    $response = product_no_store_response( rest_ensure_response( array( 'data' => serialize_product( $saved_product, true ) ) ) );
    record_audit_event( 'product_created', $session['user']->ID, $session['id'], $session['device_label'], 'product', $saved_product->get_id(), array( 'status' => $saved_product->get_status() ) );
    $response->set_status( 201 );
    return $response;
}

/**
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function update_product( $request ) {
    $session = get_session_context();
    if ( is_wp_error( $session ) ) {
        return $session;
    }

    if ( ! products_write_available() ) {
        return new \WP_Error( 'fandoogh_woocommerce_inactive', __( 'WooCommerce فعال نیست یا CRUD محصول در دسترس نیست.', 'fandoogh-manager' ), array( 'status' => 503 ) );
    }

    apply_session_user_context( $session );
    $user_id    = absint( $session['user']->ID );
    $product_id = absint( $request->get_param( 'id' ) );
    $product    = compose_product_repository()->findById( $product_id );

    if ( ! $product || ! method_exists( $product, 'get_id' ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) || ( method_exists( $product, 'get_status' ) && 'trash' === $product->get_status() ) ) {
        return new \WP_Error( 'fandoogh_product_not_found', __( 'محصول پیدا نشد.', 'fandoogh-manager' ), array( 'status' => 404 ) );
    }

    if ( ! product_user_can_edit_existing( $user_id, $product_id ) ) {
        return new \WP_Error( 'fandoogh_products_edit_forbidden', __( 'کاربر WordPress اجازهٔ ویرایش این محصول را ندارد.', 'fandoogh-manager' ), array( 'status' => 403 ) );
    }

    $body = product_write_request_body( $request );
    if ( is_wp_error( $body ) ) {
        return $body;
    }
    if ( empty( $body ) ) {
        return product_write_error( 'fandoogh_product_empty_update', __( 'برای ویرایش محصول حداقل یک فیلد ارسال کنید.', 'fandoogh-manager' ) );
    }

    $values = product_write_values( $body, $user_id, false, $product );
    if ( is_wp_error( $values ) ) {
        return $values;
    }

    $applied = product_write_apply_values( $product, $values );
    if ( is_wp_error( $applied ) ) {
        return $applied;
    }

    $saved_product = product_write_save( $product );
    if ( is_wp_error( $saved_product ) ) {
        return $saved_product;
    }

    record_audit_event( 'product_updated', $session['user']->ID, $session['id'], $session['device_label'], 'product', $saved_product->get_id(), array( 'status' => $saved_product->get_status() ) );
    return product_no_store_response( rest_ensure_response( array( 'data' => serialize_product( $saved_product, true ) ) ) );
}

/**
 * Serialize the attributes declared on the parent product without exposing
 * raw WooCommerce objects or arbitrary metadata to the browser.
 *
 * @param object $product WooCommerce product object.
 * @return array<int, array<string, mixed>>
 */
function serialize_product_attributes( $product ) {
    $items = array();
    if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
        return $items;
    }

    foreach ( (array) $product->get_attributes() as $attribute ) {
        if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
            continue;
        }

        $name      = sanitize_text_field( (string) $attribute->get_name() );
        $is_global = '' !== $name && product_is_global_attribute_taxonomy( $name );
        $options   = array();
        foreach ( (array) $attribute->get_options() as $option ) {
            if ( $is_global ) {
                $term = get_term( absint( $option ), $name );
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                $label = sanitize_text_field( $term->name );
                $options[] = array(
                    'id'    => absint( $term->term_id ),
                    'value' => absint( $term->term_id ),
                    'label' => $label,
                    'name'  => $label,
                    'slug'  => sanitize_title( $term->slug ),
                );
                continue;
            }

            $label = sanitize_text_field( (string) $option );
            if ( '' === trim( $label ) ) {
                continue;
            }
            $options[] = array(
                'id'    => 0,
                'value' => $label,
                'label' => $label,
                'name'  => $label,
                'slug'  => sanitize_title( $label ),
            );
        }

        if ( empty( $options ) ) {
            continue;
        }

        $taxonomy = $is_global ? get_taxonomy( $name ) : false;
        $label    = $taxonomy && isset( $taxonomy->label ) ? $taxonomy->label : $name;
        $items[]  = array(
            'id'        => method_exists( $attribute, 'get_id' ) ? absint( $attribute->get_id() ) : 0,
            'name'      => $name,
            'label'     => sanitize_text_field( (string) $label ),
            'options'   => $options,
            'visible'   => method_exists( $attribute, 'get_visible' ) ? (bool) $attribute->get_visible() : true,
            'variation' => method_exists( $attribute, 'get_variation' ) ? (bool) $attribute->get_variation() : false,
            'global'    => $is_global,
        );
    }

    return $items;
}

/**
 * Resolve display metadata without changing the product's assigned categories.
 * Read through WordPress's taxonomy API (and its term cache). A broken chain,
 * foreign taxonomy, cycle, or hierarchy exceeding 100 terms has no safe root.
 *
 * @param mixed $term Assigned product category term.
 * @return array{id: int, name: string, slug: string}|null
 */
function product_root_category( $term ) {
    $visited     = array();
    $expected_id = null;

    for ( $depth = 0; $depth < 100; $depth++ ) {
        if ( ! is_object( $term ) || is_wp_error( $term ) || ! isset( $term->term_id, $term->taxonomy, $term->parent, $term->name, $term->slug ) || 'product_cat' !== $term->taxonomy || ! is_string( $term->name ) || ! is_string( $term->slug ) ) {
            return null;
        }

        $term_id   = filter_var( $term->term_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        $parent_id = filter_var( $term->parent, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
        if ( false === $term_id || false === $parent_id || isset( $visited[ $term_id ] ) || ( null !== $expected_id && $term_id !== $expected_id ) ) {
            return null;
        }
        $visited[ $term_id ] = true;

        if ( 0 === $parent_id ) {
            return array(
                'id'   => $term_id,
                'name' => sanitize_text_field( $term->name ),
                'slug' => sanitize_title( $term->slug ),
            );
        }

        if ( isset( $visited[ $parent_id ] ) ) {
            return null;
        }
        $expected_id = $parent_id;
        $term        = get_term( $parent_id, 'product_cat' );
    }

    return null;
}

/**
 * @param object $product WooCommerce product object.
 * @param bool   $detail Include longer fields.
 * @return array<string, mixed>
 */
function serialize_product( $product, $detail = false ) {
    $product_id = absint( $product->get_id() );
    $categories = array();

    foreach ( (array) $product->get_category_ids() as $category_id ) {
        $term = get_term( absint( $category_id ), 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }
        $parent_term = ! empty( $term->parent ) ? get_term( absint( $term->parent ), 'product_cat' ) : false;
        $categories[] = array(
            'id'          => absint( $term->term_id ),
            'name'        => sanitize_text_field( $term->name ),
            'slug'        => sanitize_title( $term->slug ),
            'parent'      => absint( $term->parent ),
            'parent_name' => $parent_term && ! is_wp_error( $parent_term ) ? sanitize_text_field( $parent_term->name ) : '',
            'parent_slug' => $parent_term && ! is_wp_error( $parent_term ) ? sanitize_title( $parent_term->slug ) : '',
            'root_category' => product_root_category( $term ),
        );
    }

    $images      = array();
    $image_ids   = array();
    $featured_id = absint( $product->get_image_id() );
    if ( $featured_id > 0 ) {
        $image_ids[] = $featured_id;
    }
    foreach ( (array) $product->get_gallery_image_ids() as $gallery_id ) {
        $image_ids[] = absint( $gallery_id );
    }

    foreach ( array_values( array_unique( array_filter( $image_ids ) ) ) as $image_id ) {
        $image_url = public_asset_url( wp_get_attachment_image_url( $image_id, 'medium' ) );
        if ( ! $image_url ) {
            continue;
        }
        $images[] = array(
            'id'  => $image_id,
            'src' => $image_url,
            'alt' => sanitize_text_field( get_the_title( $image_id ) ),
        );
    }

    $regular_price = (string) $product->get_regular_price();
    $sale_price    = (string) $product->get_sale_price();
    $discount_amount = '';
    $discount_percent = null;
    if ( '' !== $regular_price && '' !== $sale_price && (float) $regular_price > (float) $sale_price && (float) $regular_price > 0 ) {
        $discount_value = (float) $regular_price - (float) $sale_price;
        $discount_amount = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $discount_value, 2 ) : number_format( $discount_value, 2, '.', '' );
        $discount_percent = round( ( $discount_value / (float) $regular_price ) * 100, 2 );
    }

    $shipping_class_id = method_exists( $product, 'get_shipping_class_id' ) ? absint( $product->get_shipping_class_id() ) : 0;
    $shipping_class_name = '';
    if ( $shipping_class_id > 0 ) {
        $shipping_class_term = get_term( $shipping_class_id, 'product_shipping_class' );
        if ( $shipping_class_term && ! is_wp_error( $shipping_class_term ) ) {
            $shipping_class_name = sanitize_text_field( $shipping_class_term->name );
        }
    }

    $item = array(
        'id'             => $product_id,
        'name'           => sanitize_text_field( $product->get_name() ),
        'slug'           => sanitize_title( $product->get_slug() ),
        'permalink'      => public_asset_url( $product->get_permalink() ),
        'type'           => sanitize_key( $product->get_type() ),
        'product_kind'   => product_kind_from_product( $product ),
        'status'         => sanitize_key( $product->get_status() ),
        'price'          => (string) $product->get_price(),
        'regular_price'  => $regular_price,
        'sale_price'     => $sale_price,
        'discount_amount'=> $discount_amount,
        'discount_percent' => $discount_percent,
        'sku'            => sanitize_text_field( $product->get_sku() ),
        'stock_status'   => sanitize_key( $product->get_stock_status() ),
        'stock_quantity' => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
        'total_sales'    => method_exists( $product, 'get_total_sales' ) ? absint( $product->get_total_sales() ) : 0,
        'manage_stock'   => (bool) $product->get_manage_stock(),
        'backorders'     => method_exists( $product, 'get_backorders' ) ? sanitize_key( $product->get_backorders() ) : 'no',
        'sold_individually' => method_exists( $product, 'get_sold_individually' ) ? (bool) $product->get_sold_individually() : false,
        'virtual'        => method_exists( $product, 'is_virtual' ) ? (bool) $product->is_virtual() : false,
        'downloadable'   => method_exists( $product, 'is_downloadable' ) ? (bool) $product->is_downloadable() : false,
        'weight'         => method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '',
        'length'         => method_exists( $product, 'get_length' ) ? (string) $product->get_length() : '',
        'width'          => method_exists( $product, 'get_width' ) ? (string) $product->get_width() : '',
        'height'         => method_exists( $product, 'get_height' ) ? (string) $product->get_height() : '',
        'tax_status'     => method_exists( $product, 'get_tax_status' ) ? sanitize_key( $product->get_tax_status() ) : 'taxable',
        'tax_class'      => method_exists( $product, 'get_tax_class' ) ? sanitize_title( $product->get_tax_class() ) : '',
        'catalog_visibility' => method_exists( $product, 'get_catalog_visibility' ) ? sanitize_key( $product->get_catalog_visibility() ) : 'visible',
        'shipping_class_id'  => $shipping_class_id,
        'shipping_class'     => method_exists( $product, 'get_shipping_class' ) ? sanitize_title( $product->get_shipping_class() ) : '',
        'shipping_class_name'=> $shipping_class_name,
        'purchase_note'     => method_exists( $product, 'get_purchase_note' ) ? sanitize_textarea_field( $product->get_purchase_note() ) : '',
        'reviews_allowed'   => method_exists( $product, 'get_reviews_allowed' ) ? (bool) $product->get_reviews_allowed() : true,
        'download_limit'    => method_exists( $product, 'get_download_limit' ) ? ( null === $product->get_download_limit() ? null : (int) $product->get_download_limit() ) : null,
        'download_expiry'   => method_exists( $product, 'get_download_expiry' ) ? ( null === $product->get_download_expiry() ? null : (int) $product->get_download_expiry() ) : null,
        'upsell_ids'     => method_exists( $product, 'get_upsell_ids' ) ? array_values( array_map( 'absint', (array) $product->get_upsell_ids() ) ) : array(),
        'cross_sell_ids' => method_exists( $product, 'get_cross_sell_ids' ) ? array_values( array_map( 'absint', (array) $product->get_cross_sell_ids() ) ) : array(),
        'short_description' => sanitize_textarea_field( wp_strip_all_tags( $product->get_short_description() ) ),
        'categories'     => $categories,
        'images'         => $images,
        'attributes'     => serialize_product_attributes( $product ),
    );

    if ( $detail ) {
        $item['short_description'] = sanitize_textarea_field( wp_strip_all_tags( $product->get_short_description() ) );
        $item['description']       = sanitize_textarea_field( wp_strip_all_tags( $product->get_description() ) );
        $item['manage_stock']      = (bool) $product->get_manage_stock();
        $item['created_at']        = $product->get_date_created() ? $product->get_date_created()->date( DATE_ATOM ) : null;
        $item['updated_at']        = $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : null;
    }

    return $item;
}
