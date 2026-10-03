<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Register the plugin lifecycle contract in its original order. */
function register_plugin_hooks(): void {
	add_action( 'init', __NAMESPACE__ . '\\maybe_ensure_push_schema', 3 );
	add_action( 'init', __NAMESPACE__ . '\\maybe_schedule_operations_tick', 4 );
	add_action( OPERATIONS_PUSH_HOOK, __NAMESPACE__ . '\\dispatch_operations_push', 10, 2 );
	add_action( OPERATIONS_BATCH_HOOK, __NAMESPACE__ . '\\operations_push_batch', 10, 3 );
	add_action( OPERATIONS_FLUSH_HOOK, __NAMESPACE__ . '\\operations_flush_batch', 10, 2 );
	add_action( OPERATIONS_TICK_HOOK, __NAMESPACE__ . '\\operations_hourly_tick' );
	add_action( 'woocommerce_new_order', __NAMESPACE__ . '\\operations_new_order' );
	add_action( 'woocommerce_checkout_order_processed', __NAMESPACE__ . '\\operations_new_order' );
	add_action( 'woocommerce_store_api_checkout_order_processed', __NAMESPACE__ . '\\operations_store_order' );
	add_action( 'woocommerce_order_status_changed', __NAMESPACE__ . '\\operations_new_order' );
	add_action( 'woocommerce_product_set_stock', __NAMESPACE__ . '\\operations_stock_changed' );
	add_action( 'woocommerce_variation_set_stock', __NAMESPACE__ . '\\operations_stock_changed' );
	add_action( 'woocommerce_product_set_stock_status', __NAMESPACE__ . '\\operations_stock_status_changed', 10, 3 );
	add_action( 'woocommerce_variation_set_stock_status', __NAMESPACE__ . '\\operations_stock_status_changed', 10, 3 );
	add_action( 'woocommerce_product_object_updated_props', __NAMESPACE__ . '\\operations_product_updated', 10, 2 );
	add_action( 'init', __NAMESPACE__ . '\\load_plugin_textdomain', 0 );
	add_action( 'init', __NAMESPACE__ . '\\maybe_ensure_security_schema', 1 );
	add_action( 'init', __NAMESPACE__ . '\\maybe_schedule_security_cleanup', 2 );
	add_action( 'init', __NAMESPACE__ . '\\register_rewrite_rules', 20 );
	add_action( 'init', __NAMESPACE__ . '\\register_shortcodes', 20 );
	add_action( SECURITY_CLEANUP_HOOK, __NAMESPACE__ . '\\cleanup_security_data' );
	add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', __NAMESPACE__ . '\\pos_channel_cpt_query', 10, 2 );
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
}
