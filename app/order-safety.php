<?php

namespace Fandoogh_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact minor units for bounded refund amounts. */
function orders_refund_minor( $amount ) {
	$decimals = max( 0, min( 6, (int) wc_get_price_decimals() ) );
	$value = wc_format_decimal( $amount, $decimals );
	if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/D', (string) $value, $parts ) ) { throw new \InvalidArgumentException( 'invalid-refund-money' ); }
	$digits = ltrim( $parts[1] . str_pad( $parts[2] ?? '', $decimals, '0' ), '0' );
	if ( strlen( $digits ) > 15 ) { throw new \InvalidArgumentException( 'refund-money-too-large' ); }
	return (int) $digits;
}

function orders_refund_money( $minor ) {
	$decimals = max( 0, min( 6, (int) wc_get_price_decimals() ) );
	$digits = str_pad( (string) $minor, $decimals + 1, '0', STR_PAD_LEFT );
	return $decimals ? substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals ) : $digits;
}

function orders_refund_portion( $minor, $quantity, $remaining ) {
	if ( $quantity === $remaining ) { return $minor; }
	$base = intdiv( $minor, $remaining ) * $quantity;
	$rest = ( $minor % $remaining ) * $quantity;
	return $base + intdiv( $rest + intdiv( $remaining, 2 ), $remaining );
}

/** Deduct previous refunds and round the last remaining quantity exactly. */
function orders_refund_lines( $order, $raw_lines ) {
	$items = $order->get_items( 'line_item' ); $lines = array(); $amount = 0;
	foreach ( $raw_lines as $key => $raw ) {
		$raw_id = is_array( $raw ) && isset( $raw['item_id'] ) ? $raw['item_id'] : $key;
		$id = is_scalar( $raw_id ) && ! is_bool( $raw_id ) && preg_match( '/^[1-9][0-9]{0,11}$/D', (string) $raw_id ) ? (int) $raw_id : 0;
		$quantity = is_array( $raw ) ? ( $raw['quantity'] ?? null ) : null;
		if ( ! is_scalar( $quantity ) || is_bool( $quantity ) || ! preg_match( '/^[1-9][0-9]{0,5}$/D', (string) $quantity ) || ! isset( $items[$id] ) || isset( $lines[$id] ) ) {
			return orders_error( 'fandoogh_invalid_refund_items', __( 'قلم یا تعداد مرجوعی معتبر نیست؛ هر قلم را فقط یک بار انتخاب کنید.', 'fandoogh-manager' ), 422 );
		}
		$quantity = (int) $quantity; $saved_quantity = $items[$id]->get_quantity();
		$refunded_quantity = abs( $order->get_qty_refunded_for_item( $id ) );
		if ( ! preg_match( '/^[1-9][0-9]{0,5}$/D', (string) $saved_quantity ) || floor( $refunded_quantity ) !== (float) $refunded_quantity ) {
			return orders_error( 'fandoogh_invalid_refund_quantity', __( 'تعداد ذخیره‌شدهٔ این قلم برای مرجوعی معتبر نیست.', 'fandoogh-manager' ), 422 );
		}
		$original = (int) $saved_quantity;
		$remaining = $original - (int) $refunded_quantity;
		if ( $original > 999999 || $remaining < $quantity ) { return orders_error( 'fandoogh_invalid_refund_quantity', __( 'تعداد مرجوعی از تعداد باقی‌ماندهٔ این قلم بیشتر است.', 'fandoogh-manager' ), 422 ); }
		$total = max( 0, orders_refund_minor( $items[$id]->get_total() ) - orders_refund_minor( abs( $order->get_total_refunded_for_item( $id ) ) ) );
		$total = orders_refund_portion( $total, $quantity, $remaining );
		$taxes = $items[$id]->get_taxes(); $refund_tax = array();
		foreach ( (array) ( $taxes['total'] ?? array() ) as $rate_id => $tax ) {
			$left = max( 0, orders_refund_minor( $tax ) - orders_refund_minor( abs( $order->get_tax_refunded_for_item( $id, $rate_id ) ) ) );
			$minor = orders_refund_portion( $left, $quantity, $remaining );
			$refund_tax[$rate_id] = orders_refund_money( $minor ); $amount += $minor;
		}
		$amount += $total;
		$lines[$id] = array( 'qty' => $quantity, 'refund_total' => orders_refund_money( $total ), 'refund_tax' => $refund_tax );
	}
	return array( 'lines' => $lines, 'amount' => $amount );
}

/** One writer per order, across users and keys. Ambiguous operations never expire. */
function orders_refund_lock( $order_id, $claim_key ) { return add_option( 'fandoogh_refund_order_' . absint( $order_id ), $claim_key, '', 'no' ); }
function orders_refund_unlock( $order_id, $claim_key ) {
	$key = 'fandoogh_refund_order_' . absint( $order_id );
	if ( get_option( $key, false ) === $claim_key ) { delete_option( $key ); }
}

/** A returned, fully completed Woo refund is the only success marker. */
function orders_refund_recover( $order_id, $claim_key, $existing ) {
	$order = compose_order_repository()->findById( absint( $order_id ) );
	if ( ! $order || ! method_exists( $order, 'get_refunds' ) ) { return false; }
	foreach ( $order->get_refunds() as $refund ) {
		if ( ! method_exists( $refund, 'get_meta' ) || $refund->get_meta( '_fandoogh_refund_claim' ) !== $claim_key || 'completed' !== $refund->get_meta( '_fandoogh_refund_state' ) ) { continue; }
		orders_refund_idempotency_complete( array( 'key' => $claim_key, 'fingerprint' => $existing['fingerprint'], 'order_id' => $order_id ), $refund->get_id() );
		orders_refund_unlock( $order_id, $claim_key );
		return $refund;
	}
	return false;
}

function orders_refund_gateway_available( $order ) {
	if ( ! class_exists( '\\WC_Payment_Gateways' ) ) { return false; }
	$gateways = \WC_Payment_Gateways::instance()->payment_gateways();
	$gateway = $gateways[$order->get_payment_method()] ?? false;
	return $gateway && method_exists( $gateway, 'supports' ) && $gateway->supports( 'refunds' ) && method_exists( $gateway, 'process_refund' );
}

function orders_create_review( $claim, $order_id ) {
	update_option( $claim['key'], array( 'state' => 'review', 'fingerprint' => $claim['fingerprint'], 'order_id' => absint( $order_id ), 'created_at' => time() ), false );
}

/** Serialize refund validation and mutation; keep both claims on any ambiguous result. */
function orders_execute_refund( $request, $session, $order_id, $idempotency_key, $fingerprint ) {
	$claim_key = orders_refund_idempotency_option_key( $session, $order_id, $idempotency_key );
	if ( ! orders_refund_lock( $order_id, $claim_key ) ) { return orders_error( 'fandoogh_refund_order_busy', __( 'بازپرداخت دیگری برای این سفارش در جریان است یا نیاز به بررسی دارد.', 'fandoogh-manager' ), 409, array( 'order_id' => $order_id ) ); }
	$release_lock = true;
	try {
		$order = compose_order_repository()->findById( $order_id );
		if ( ! orders_is_readable_order( $order ) ) { return orders_error( 'fandoogh_order_not_found', __( 'سفارش پیدا نشد.', 'fandoogh-manager' ), 404 ); }
		$values = order_refund_values( $request, $order );
		if ( is_wp_error( $values ) ) { return $values; }
		if ( $values['refund_payment'] && ! orders_refund_gateway_available( $order ) ) {
			return orders_error( 'fandoogh_refund_gateway_unavailable', __( 'روش پرداخت این سفارش بازپرداخت خودکار ندارد؛ پس از بازگرداندن وجه، گزینهٔ بازپرداخت دستی را انتخاب کنید.', 'fandoogh-manager' ), 422 );
		}
		$claim = orders_refund_idempotency_claim( $session, $order_id, $idempotency_key, $fingerprint );
		if ( is_wp_error( $claim ) ) { return $claim; }
		if ( ! empty( $claim['idempotent_replay'] ) ) { return orders_refund_replay_response( $order, $order_id, $claim['replay_refund'] ); }
		$release_lock = false;
		$args = array( 'amount' => $values['amount'], 'reason' => $values['reason'], 'order_id' => $order_id, 'refund_payment' => $values['refund_payment'], 'restock_items' => $values['restock_items'], 'line_items' => $values['line_items'] );
		$tag_refund = static function ( $refund, $refund_args ) use ( $order_id, $claim_key ) {
			if ( (int) ( $refund_args['order_id'] ?? 0 ) === $order_id && method_exists( $refund, 'update_meta_data' ) ) {
				$refund->update_meta_data( '_fandoogh_refund_claim', $claim_key );
				$refund->update_meta_data( '_fandoogh_refund_state', 'processing' );
			}
		};
		add_action( 'woocommerce_create_refund', $tag_refund, PHP_INT_MAX, 2 );
		try { $refund = compose_order_repository()->createRefund( $args ); }
		finally { remove_action( 'woocommerce_create_refund', $tag_refund, PHP_INT_MAX ); }
		if ( is_wp_error( $refund ) || ! is_object( $refund ) || ! method_exists( $refund, 'get_id' ) || absint( $refund->get_id() ) < 1 ) { throw new \RuntimeException( 'refund-result-uncertain' ); }
		$refund->update_meta_data( '_fandoogh_refund_claim', $claim_key );
		$refund->update_meta_data( '_fandoogh_refund_state', 'completed' );
		if ( ! $refund->save() ) { throw new \RuntimeException( 'refund-marker-save' ); }
		orders_refund_idempotency_complete( $claim, $refund->get_id() );
		$release_lock = true;
		record_audit_event( 'order_refunded', $session['user']->ID, $session['id'], $session['device_label'], 'order', $order_id, array( 'order_id' => $order_id, 'refund_id' => $refund->get_id(), 'status' => $values['refund_payment'] ? 'automatic' : 'manual' ) );
		$fresh = compose_order_repository()->findById( $order_id );
		return orders_no_store_response( array( 'data' => serialize_order( orders_is_readable_order( $fresh ) ? $fresh : $order, true ), 'refund' => serialize_order_refund( $refund ), 'idempotent_replay' => false ) );
	} catch ( \Throwable $error ) {
		return orders_error( 'fandoogh_refund_review', __( 'نتیجهٔ بازپرداخت نیاز به بررسی سفارش و درگاه دارد؛ درخواست تازه ثبت نکنید.', 'fandoogh-manager' ), 409, array( 'order_id' => $order_id, 'state' => 'review' ) );
	} finally {
		if ( $release_lock ) { orders_refund_unlock( $order_id, $claim_key ); }
	}
}
