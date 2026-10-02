<?php
/** In-person checkout. WooCommerce owns orders, taxes, customers and stock. */
namespace Fandoogh_Manager;
if ( ! defined( 'ABSPATH' ) ) { exit; }

const POS_CREATED_VIA = 'fandoogh-pos';
const POS_MAX_MINOR = 1000000000000;

function register_pos_routes() {
	register_rest_route( REST_NAMESPACE, '/pos/catalog', array( 'methods'=>'GET', 'callback'=>__NAMESPACE__ . '\\pos_catalog', 'permission_callback'=>__NAMESPACE__ . '\\products_read_permission' ) );
	register_rest_route( REST_NAMESPACE, '/pos/quote', array( 'methods'=>'POST', 'callback'=>__NAMESPACE__ . '\\pos_quote', 'permission_callback'=>__NAMESPACE__ . '\\pos_sale_permission' ) );
	register_rest_route( REST_NAMESPACE, '/pos/sales', array( 'methods'=>'POST', 'callback'=>__NAMESPACE__ . '\\pos_sale', 'permission_callback'=>__NAMESPACE__ . '\\pos_sale_permission' ) );
	register_rest_route( REST_NAMESPACE, '/pos/sales/(?P<key>[A-Za-z0-9._-]{8,80})', array( 'methods'=>'GET', 'callback'=>__NAMESPACE__ . '\\pos_sale_status', 'permission_callback'=>__NAMESPACE__ . '\\orders_read_permission' ) );
}

function pos_sale_permission( $request ) {
	$allowed = orders_create_permission( $request );
	if ( is_wp_error( $allowed ) ) { return $allowed; }
	$session = get_session_context();
	if ( ! session_has_scope( 'products.read', $session['scopes'] ) || ! session_has_scope( 'orders.read', $session['scopes'] ) ) {
		return orders_error( 'fandoogh_pos_forbidden', __( 'فروش حضوری به مجوز مشاهدهٔ کالا و سفارش و ثبت سفارش نیاز دارد.', 'fandoogh-manager' ), 403 );
	}
	return true;
}

function pos_decimals() { return min( 6, max( 0, (int) wc_get_price_decimals() ) ); }
function pos_digits( $value ) {
	return strtr( (string) $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.' ) );
}
/** Minor currency units prevent drift when allocating manual discounts. */
function pos_minor( $raw, $decimals = null ) {
	if ( ! is_scalar( $raw ) || is_bool( $raw ) ) { throw new \InvalidArgumentException( 'مبلغ معتبر وارد کنید.' ); }
	$digits = trim( pos_digits( $raw ) );
	$decimals = null === $decimals ? pos_decimals() : $decimals;
	if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $digits ) ) { throw new \InvalidArgumentException( 'مبلغ باید عدد مثبت و بدون جداکنندهٔ هزارگان باشد.' ); }
	$parts = explode( '.', $digits );
	$fraction = $parts[1] ?? '';
	if ( strlen( rtrim( $fraction, '0' ) ) > $decimals ) { throw new \InvalidArgumentException( 'تعداد رقم اعشار مبلغ با واحد پول فروشگاه سازگار نیست.' ); }
	$minor = (int) $parts[0] * ( 10 ** $decimals ) + (int) substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
	if ( $minor > POS_MAX_MINOR ) { throw new \InvalidArgumentException( 'مبلغ از سقف مجاز فاکتور بیشتر است.' ); }
	return $minor;
}
function pos_money( $minor ) { return number_format( $minor / ( 10 ** pos_decimals() ), pos_decimals(), '.', '' ); }
function pos_round_minor( $amount ) { return (int) round( (float) $amount * ( 10 ** pos_decimals() ) ); }

function pos_product( $id ) {
	$product = compose_product_repository()->findById( (int) $id );
	if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_type( array( 'simple', 'variation' ) ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		throw new \InvalidArgumentException( 'یکی از کالاها قابل فروش یا موجود نیست.' );
	}
	if ( $product->is_type( 'variation' ) ) {
		$parent = compose_product_repository()->findById( (int) $product->get_parent_id() );
		if ( ! $parent || 'publish' !== $parent->get_status() ) { throw new \InvalidArgumentException( 'محصول اصلی این تنوع قابل فروش نیست.' ); }
	}
	return $product;
}

function pos_catalog_item( $product ) {
	$image = $product->get_image_id();
	return array(
		'id'=>$product->get_id(), 'parent_id'=>$product->is_type( 'variation' ) ? $product->get_parent_id() : 0,
		'name'=>orders_clean_text( $product->get_name(), 255 ), 'sku'=>orders_clean_text( $product->get_sku(), 100 ),
		'type'=>$product->get_type(), 'price'=>(string) $product->get_price(),
		'stock_status'=>$product->get_stock_status(), 'quantity'=>$product->get_stock_quantity(),
		'sold_individually'=>$product->is_sold_individually(), 'available'=>$product->is_purchasable() && $product->is_in_stock(),
		'image'=>$image ? public_asset_url( wp_get_attachment_image_url( $image, 'woocommerce_thumbnail' ) ) : null,
	);
}

/** Bounded scrolling catalogue; variable parents open an explicit variation picker. */
function pos_catalog( $request ) {
	try {
		$repository = compose_product_repository();
		if ( ! $repository->isAvailable() ) { return orders_error( 'fandoogh_pos_unavailable', __( 'ووکامرس در دسترس نیست.', 'fandoogh-manager' ), 503 ); }
		$id = absint( $request->get_param( 'id' ) );
		if ( $id ) {
			$product = $repository->findById( $id );
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_type( array( 'simple','variable','variation' ) ) ) { throw new \InvalidArgumentException( 'کالا پیدا نشد.' ); }
			if ( $product->is_type( 'variation' ) ) { $product = pos_product( $id ); }
			return orders_no_store_response( array( 'data'=>array( pos_catalog_item( $product ) ), 'meta'=>array( 'page'=>1,'total_pages'=>1 ) ) );
		}
		$page = max( 1, min( 100000, absint( $request->get_param( 'page' ) ?: 1 ) ) );
		$parent = absint( $request->get_param( 'parent_id' ) );
		$args = array( 'type'=>$parent ? 'variation' : array( 'simple','variable' ), 'status'=>'publish', 'page'=>$page, 'limit'=>24, 'paginate'=>true, 'orderby'=>'title', 'order'=>'ASC' );
		if ( $parent ) {
			$product = $repository->findById( $parent );
			if ( ! $product || ! $product->is_type( 'variable' ) || 'publish' !== $product->get_status() ) { throw new \InvalidArgumentException( 'محصول متغیر معتبر نیست.' ); }
			$args['parent'] = $parent;
		}
		$search = orders_clean_text( $request->get_param( 'search' ), 100 );
		if ( $search ) {
			$args['s'] = $search;
			$sku_id = $repository->findIdBySku( pos_digits( $search ) );
			if ( $sku_id && ! $parent ) { $match = $repository->findById( $sku_id ); $args['include'] = array( $match->is_type( 'variation' ) ? $match->get_parent_id() : $sku_id ); unset( $args['s'] ); }
		}
		$category = absint( $request->get_param( 'category_id' ) );
		if ( $category && ! $parent ) { $args['product_category_id'] = array( $category ); }
		$result = $repository->query( $args );
		if ( ! is_object( $result ) || ! isset( $result->products, $result->max_num_pages ) ) { throw new \RuntimeException( 'invalid-catalog' ); }
		return orders_no_store_response( array( 'data'=>array_map( __NAMESPACE__ . '\\pos_catalog_item', $result->products ), 'meta'=>array( 'page'=>$page,'total_pages'=>(int) $result->max_num_pages ), 'currency'=>get_public_currency(), 'decimals'=>pos_decimals(), 'prices_include_tax'=>wc_prices_include_tax() ) );
	} catch ( \InvalidArgumentException $error ) { return orders_error( 'fandoogh_pos_catalog_invalid', $error->getMessage(), 422 ); }
	catch ( \Throwable $error ) { return orders_error( 'fandoogh_pos_catalog_failed', __( 'خواندن کالاها انجام نشد.', 'fandoogh-manager' ), 503 ); }
}

/** Parse a narrow customer allowlist. Existing accounts are never silently edited. */
function pos_customer_values( $raw, $request ) {
	if ( ! is_array( $raw ) ) { throw new \InvalidArgumentException( 'اطلاعات مشتری معتبر نیست.' ); }
	$mode = $raw['mode'] ?? 'guest';
	if ( ! in_array( $mode, array( 'guest','registered','new' ), true ) ) { throw new \InvalidArgumentException( 'نوع مشتری معتبر نیست.' ); }
	$values = array( 'mode'=>$mode, 'id'=>0, 'billing'=>array(), 'vat_exempt'=>false );
	if ( 'registered' === $mode ) {
		$permission = customers_read_permission( $request );
		if ( is_wp_error( $permission ) ) { throw new \InvalidArgumentException( $permission->get_error_message() ); }
		$customer = compose_customer_repository()->findById( absint( $raw['id'] ?? 0 ) );
		if ( ! customers_is_readable( $customer ) ) { throw new \InvalidArgumentException( 'مشتری انتخاب‌شده پیدا نشد.' ); }
		$values['id'] = $customer->get_id();
		$values['billing'] = serialize_customer_address( $customer, 'billing' );
		$values['billing']['first_name'] = $customer->get_first_name();
		$values['billing']['last_name'] = $customer->get_last_name();
		$values['billing']['email'] = $customer->get_email();
		$values['billing']['phone'] = $customer->get_billing_phone();
		$values['vat_exempt'] = $customer->get_is_vat_exempt();
	} else {
		foreach ( array( 'first_name'=>120,'last_name'=>120,'phone'=>80,'email'=>120,'address_1'=>255,'city'=>120 ) as $key=>$length ) { $values['billing'][$key] = orders_clean_text( $raw[$key] ?? '', $length ); }
		$email = $values['billing']['email'];
		if ( $values['billing']['phone'] ) { $values['billing']['phone'] = pos_digits($values['billing']['phone']); }
		if ( $email && ! is_email( $email ) ) { throw new \InvalidArgumentException( 'ایمیل مشتری معتبر نیست.' ); }
		if ( 'new' === $mode ) {
			$permission = customers_write_permission( $request );
			if ( is_wp_error( $permission ) ) { throw new \InvalidArgumentException( $permission->get_error_message() ); }
			if ( ! $values['billing']['first_name'] && ! $values['billing']['last_name'] ) { throw new \InvalidArgumentException( 'نام مشتری جدید را وارد کنید.' ); }
			if ( ! $values['billing']['phone'] && ! $email ) { throw new \InvalidArgumentException( 'تلفن یا ایمیل مشتری جدید را وارد کنید.' ); }
			if ( $email && email_exists( $email ) ) { throw new \InvalidArgumentException( 'این ایمیل ثبت شده است؛ مشتری قبلی را انتخاب کنید.' ); }
		}
	}
	return $values;
}

/** Server-side totals, no order/customer writes. Tax location is the physical store. */
function pos_calculate( $body, $request ) {
	if ( ! is_array( $body ) || empty( $body['items'] ) || ! is_array( $body['items'] ) || count( $body['items'] ) > 100 ) { throw new \InvalidArgumentException( 'بین یک تا صد ردیف کالا انتخاب کنید.' ); }
	$customer = pos_customer_values( $body['customer'] ?? array(), $request );
	$lines = array(); $seen = array(); $stock = array(); $sum = 0;
	foreach ( $body['items'] as $raw ) {
		if ( ! is_array( $raw ) ) { throw new \InvalidArgumentException( 'ردیف کالا معتبر نیست.' ); }
		$id = orders_create_integer( $raw['id'] ?? 0, 'product', 1 );
		$raw_qty = $raw['quantity'] ?? 0;
		$qty = orders_create_integer( is_scalar($raw_qty) && !is_bool($raw_qty) ? pos_digits($raw_qty) : null, 'quantity', 1, 999 );
		if ( is_wp_error( $id ) || is_wp_error( $qty ) || isset( $seen[$id] ) ) { throw new \InvalidArgumentException( 'شناسه، تعداد یا ردیف تکراری کالا معتبر نیست.' ); }
		$seen[$id] = true;
		$product = pos_product( $id );
		if ( $product->is_sold_individually() && $qty > 1 ) { throw new \InvalidArgumentException( 'این کالا فقط یک عدد در هر فروش مجاز است.' ); }
		$price = array_key_exists( 'unit_price', $raw ) && null !== $raw['unit_price'] ? pos_minor( $raw['unit_price'] ) : pos_round_minor( $product->get_price() );
		$subtotal = $price * $qty;
		$sum += $subtotal;
		if ( $sum > POS_MAX_MINOR ) { throw new \InvalidArgumentException( 'جمع فاکتور از سقف مجاز بیشتر است.' ); }
		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$managed = $product->get_stock_managed_by_id();
			$stock[$managed] = ( $stock[$managed] ?? 0 ) + $qty;
		}
		$lines[] = array( 'id'=>$id,'product'=>$product,'name'=>orders_clean_text( $product->get_name(),255 ),'sku'=>orders_clean_text( $product->get_sku(),100 ),'quantity'=>$qty,'unit_price'=>pos_money( $price ),'subtotal_minor'=>$subtotal,'catalog_price'=>(string) $product->get_price() );
	}
	foreach ( $stock as $id=>$qty ) {
		$product = compose_product_repository()->findById( (int) $id );
		$held = function_exists( 'wc_get_held_stock_quantity' ) ? wc_get_held_stock_quantity( $product ) : 0;
		if ( ! $product || $qty > (float) $product->get_stock_quantity() - $held ) { throw new \InvalidArgumentException( 'موجودی یکی از کالاها کافی نیست؛ تعداد را اصلاح کنید.' ); }
	}
	$discount = $body['discount'] ?? array( 'type'=>'amount','value'=>'0' );
	if ( ! is_array( $discount ) || ! in_array( $discount['type'] ?? '', array('amount','percent'),true ) ) { throw new \InvalidArgumentException( 'نوع تخفیف معتبر نیست.' ); }
	$value = pos_minor( $discount['value'] ?? '0', 'percent' === $discount['type'] ? 2 : null );
	if ( 'percent' === $discount['type'] && $value > 10000 ) { throw new \InvalidArgumentException( 'درصد تخفیف باید بین صفر و صد باشد.' ); }
	$discount_minor = 'percent' === $discount['type'] ? (int) round( $sum * ( $value / 10000 ) ) : $value;
	if ( $discount_minor > $sum ) { throw new \InvalidArgumentException( 'تخفیف از جمع کالاها بیشتر است.' ); }
	// Allocate cents without putting rounding remainders on a smaller last line.
	$allocations = array(); $remaining = $discount_minor;
	foreach ( $lines as $i=>$line ) { $allocations[$i] = (int) floor( $sum ? ( $line['subtotal_minor'] / $sum ) * $discount_minor : 0 ); $remaining -= $allocations[$i]; }
	foreach ( $lines as $i=>$line ) { $extra = min( $remaining, $line['subtotal_minor'] - $allocations[$i] ); $allocations[$i] += $extra; $remaining -= $extra; }
	$tax_totals = array(); $subtotal_tax_totals = array(); $base_subtotal = 0; $base_total = 0;
	$round_at_subtotal = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
	$location = wc_get_base_location();
	$tax_location = array( 'country'=>$location['country'],'state'=>$location['state'],'postcode'=>get_option('woocommerce_store_postcode',''),'city'=>get_option('woocommerce_store_city','') );
	foreach ( $lines as $i=>&$line ) {
		$allocated = $allocations[$i];
		$product = $line['product'];
		$rates = wc_tax_enabled() && 'taxable' === $product->get_tax_status() ? \WC_Tax::find_rates( array_merge( $tax_location,array('tax_class'=>$product->get_tax_class()) ) ) : array();
		$original = (float) pos_money( $line['subtotal_minor'] ); $net = (float) pos_money( $line['subtotal_minor']-$allocated );
		$before_taxes = $rates ? \WC_Tax::calc_tax( $original, $rates, wc_prices_include_tax() ) : array();
		$after_taxes = $rates ? \WC_Tax::calc_tax( $net, $rates, wc_prices_include_tax() ) : array();
		$line['subtotal'] = (string) ( wc_prices_include_tax() ? $original-array_sum($before_taxes) : $original );
		$line['total'] = (string) ( wc_prices_include_tax() ? $net-array_sum($after_taxes) : $net );
		if ( $customer['vat_exempt'] ) { $before_taxes = array(); $after_taxes = array(); }
		$line['taxes'] = array( 'subtotal'=>$before_taxes,'total'=>$after_taxes );
		$line['discount'] = pos_money( $allocated );
		foreach ( $before_taxes as $rate=>$amount ) { $subtotal_tax_totals[$rate] = ( $subtotal_tax_totals[$rate] ?? 0 ) + ( $round_at_subtotal ? $amount : wc_round_tax_total($amount) ); }
		foreach ( $after_taxes as $rate=>$amount ) { $tax_totals[$rate] = ( $tax_totals[$rate] ?? 0 ) + ( $round_at_subtotal ? $amount : wc_round_tax_total($amount) ); }
		$base_subtotal += $round_at_subtotal ? (float)$line['subtotal'] : pos_round_minor($line['subtotal']) / (10 ** pos_decimals());
		$base_total += $round_at_subtotal ? (float)$line['total'] : pos_round_minor($line['total']) / (10 ** pos_decimals());
	}
	unset( $line );
	$tax = array_sum( array_map( 'wc_round_tax_total', $tax_totals ) );
	$before_tax = array_sum( array_map( 'wc_round_tax_total', $subtotal_tax_totals ) );
	$total_minor = pos_round_minor( $base_total + $tax );
	if ( $total_minor > POS_MAX_MINOR ) { throw new \InvalidArgumentException( 'جمع نهایی از سقف مجاز بیشتر است.' ); }
	return array( 'lines'=>$lines,'customer'=>$customer,'discount'=>$discount,'subtotal'=>pos_money(pos_round_minor($base_subtotal+$before_tax)),'discount_total'=>pos_money(pos_round_minor($base_subtotal-$base_total+$before_tax-$tax)),'tax'=>pos_money(pos_round_minor($tax)),'total'=>pos_money($total_minor),'total_minor'=>$total_minor,'prices_include_tax'=>wc_prices_include_tax(),'decimals'=>pos_decimals(),'currency'=>get_public_currency() );
}

function pos_quote_fingerprint( $calculated ) {
	$copy = $calculated;
	foreach ( $copy['lines'] as &$line ) { unset( $line['product'] ); }
	unset( $line );
	return orders_create_fingerprint( $copy );
}
function pos_quote_token( $calculated, $user_id, $expires ) {
	$data = $expires . '.' . pos_quote_fingerprint($calculated);
	return $data . '.' . hash_hmac( 'sha256', $user_id . '|' . $data, wp_salt( 'auth' ) );
}
function pos_public_quote( $calculated ) {
	foreach ( $calculated['lines'] as &$line ) { unset( $line['product'], $line['taxes'], $line['subtotal_minor'] ); }
	unset($line); unset($calculated['total_minor']);
	return $calculated;
}
function pos_quote( $request ) {
	try {
		$calculated = pos_calculate( $request->get_json_params(),$request );
		$data = pos_public_quote( $calculated );
		$data['expires'] = time()+300;
		$data['quote_token'] = pos_quote_token( $calculated,get_current_user_id(),$data['expires'] );
		return orders_no_store_response( array('data'=>$data) );
	} catch ( \InvalidArgumentException $error ) { return orders_error('fandoogh_pos_invalid',$error->getMessage(),422); }
	catch ( \Throwable $error ) { return orders_error('fandoogh_pos_quote_failed',__('محاسبهٔ فروش انجام نشد؛ دوباره بررسی کنید.','fandoogh-manager'),503); }
}

function pos_payment( $raw, $total ) {
	if ( ! is_array($raw) || ! in_array($raw['method'] ?? '',array('cash','card','mixed'),true) || true !== ($raw['received'] ?? false) ) { throw new \InvalidArgumentException('دریافت پرداخت و روش آن را تأیید کنید.'); }
	$method = $raw['method'];
	$cash = pos_minor( $raw['cash_received'] ?? '0' );
	$card = pos_minor( $raw['card_amount'] ?? '0' );
	if ( ('cash' === $method && $card) || ('card' === $method && ($cash || $card !== $total)) || ('mixed' === $method && ($card <= 0 || $card >= $total)) || $card > $total || $cash+$card < $total ) { throw new \InvalidArgumentException('مبلغ نقدی و کارت‌خوان با مبلغ فاکتور سازگار نیست.'); }
	return array('method'=>$method,'cash_received'=>pos_money($cash),'cash_applied'=>pos_money($total-$card),'card_amount'=>pos_money($card),'change'=>pos_money($cash+$card-$total),'reference'=>orders_clean_text($raw['reference'] ?? '',80));
}
function pos_claim_key( $user_id,$key ) { return 'fandoogh_pos_' . hash('sha256',$user_id.'|'.$key); }
/** Recover a lost completion response only after delivery and stock reduction. */
function pos_order_fulfilled($order) {
	if(!$order || !$order->is_paid() || 'completed'!==$order->get_status()) { return false; }
	foreach($order->get_items('line_item') as $item) {
		$product=$item->get_product();
		if($product && $product->managing_stock() && !$order->get_data_store()->get_stock_reduced($order->get_id())) { return false; }
	}
	return true;
}

/** No stale claim takeover: uncertain sales must be recovered, never created twice. */
function pos_sale_status( $request ) {
	$user = get_session_context();
	$key = pos_claim_key($user['user']->ID,(string)$request->get_param('key'));
	$claim = get_option($key,false);
	if ( ! is_array($claim) ) { return orders_error('fandoogh_pos_missing',__('این فروش ثبت نشده است.','fandoogh-manager'),404); }
	return pos_replay_claim( $key,$claim );
}
function pos_replay_claim( $key,$claim ) {
	$order = !empty($claim['order_id']) ? compose_order_repository()->findById((int)$claim['order_id']) : false;
	if ( $order && orders_is_readable_order($order) && ('completed' === ($claim['state'] ?? '') || pos_order_fulfilled($order)) ) {
		if ('completed' !== ($claim['state'] ?? '')) { $claim['state']='completed'; update_option($key,$claim,false); }
		return orders_no_store_response(array('data'=>invoice_snapshot($order),'idempotent_replay'=>true));
	}
	$safe_restart = 'failed' === ($claim['state'] ?? '') && (!$order || 'cancelled'===$order->get_status()) && (!$order || !$order->is_paid());
	return orders_error('fandoogh_pos_in_progress',$safe_restart ? __('فروش انجام نشد و سفارش پرداخت‌نشده لغو شده است؛ می‌توانید فاکتور را دوباره بررسی کنید.','fandoogh-manager') : __('نتیجهٔ همین فروش هنوز قطعی نیست؛ از ثبت فروش دوباره خودداری کنید و سفارش را بررسی کنید.','fandoogh-manager'),409,array('order_id'=>$claim['order_id'] ?? 0,'state'=>$claim['state'] ?? 'processing','safe_to_restart'=>$safe_restart));
}

function pos_sale( $request ) {
	$session = get_session_context(); $body=$request->get_json_params();
	if (!is_array($body) || !is_string($body['idempotency_key'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,79}$/D',$body['idempotency_key'])) { return orders_error('fandoogh_pos_key',__('شناسهٔ فروش معتبر نیست.','fandoogh-manager'),422); }
	$key = pos_claim_key($session['user']->ID,$body['idempotency_key']);
	$fingerprint=orders_create_fingerprint($body); $existing=get_option($key,false);
	if(is_array($existing)) {
		if(!hash_equals($existing['fingerprint'],$fingerprint)) { return orders_error('fandoogh_pos_conflict',__('این شناسه قبلاً برای فروش دیگری استفاده شده است.','fandoogh-manager'),409); }
		return pos_replay_claim($key,$existing);
	}
	try {
		$calculated=pos_calculate($body,$request);
		$token=$body['quote_token'] ?? ''; $parts=is_string($token) ? explode('.',$token) : array();
		$expiry=(int)($parts[0] ?? 0);
		if(count($parts)!==3 || $expiry<time() || $expiry>time()+300 || !hash_equals(pos_quote_token($calculated,$session['user']->ID,$expiry),$token)) { return orders_error('fandoogh_pos_quote_changed',__('قیمت، موجودی یا محاسبات تغییر کرده است؛ فاکتور را دوباره بررسی و تأیید کنید.','fandoogh-manager'),409); }
		$payment=pos_payment($body['payment'] ?? array(),$calculated['total_minor']);
		if(!class_exists('\\WC_Order') || !function_exists('wc_reserve_stock_for_order') || (int)get_option('woocommerce_schema_version',0)<430) { throw new \RuntimeException('stock-reservation-unavailable'); }
	} catch(\InvalidArgumentException $error) { return orders_error('fandoogh_pos_invalid',$error->getMessage(),422); }
	catch(\Throwable $error) { return orders_error('fandoogh_pos_unavailable',__('API محاسبه یا رزرو موجودی ووکامرس در دسترس نیست.','fandoogh-manager'),503); }
	$claim=array('state'=>'processing','fingerprint'=>$fingerprint,'order_id'=>0,'created_at'=>time());
	if(!add_option($key,$claim,'','no')) { return orders_error('fandoogh_pos_busy',__('همین فروش در حال ثبت است؛ نتیجه را بررسی کنید.','fandoogh-manager'),409); }
	$order=false; $paid_attempt=false;
	try {
		$customer=$calculated['customer'];
		if('new'===$customer['mode']) {
			$record=compose_customer_repository()->create();
			$record->set_username('pos_'.substr(hash('sha256',$key),0,20));
			$record->set_password(wp_generate_password(32,true,true));
			$record->set_role('customer');
			$record->set_first_name($customer['billing']['first_name']); $record->set_last_name($customer['billing']['last_name']);
			$record->set_email($customer['billing']['email']); $record->set_billing_phone($customer['billing']['phone']);
			$record->set_billing_first_name($customer['billing']['first_name']); $record->set_billing_last_name($customer['billing']['last_name']);
			$record->set_billing_email($customer['billing']['email']); $record->set_billing_address_1($customer['billing']['address_1']);
			$customer['id']=$record->save();
			if(!$customer['id']) { throw new \RuntimeException('customer-save'); }
			$claim['customer_id']=$customer['id']; update_option($key,$claim,false);
		}
		$order=new \WC_Order();
		$order->set_created_via(POS_CREATED_VIA); $order->set_currency(get_woocommerce_currency()); $order->set_prices_include_tax(wc_prices_include_tax());
		$order->set_customer_id($customer['id']); $order->set_address($customer['billing'],'billing'); $order->set_status('pending');
		$order->update_meta_data('is_vat_exempt',$customer['vat_exempt'] ? 'yes' : 'no');
		$order->set_payment_method('fandoogh_pos_'.$payment['method']);
		$order->set_payment_method_title(array('cash'=>'نقدی','card'=>'کارت‌خوان','mixed'=>'نقدی و کارت‌خوان')[$payment['method']]);
		$order->set_customer_note(orders_clean_text($body['note'] ?? '',1000));
		$order->update_meta_data('_fandoogh_sales_channel','pos'); $order->update_meta_data('_fandoogh_pos_request',hash('sha256',$key));
		$order->update_meta_data('_fandoogh_pos_cashier',(int)$session['user']->ID); $order->update_meta_data('_fandoogh_pos_payment',$payment);
		$order->update_meta_data('_fandoogh_pos_discount',$calculated['discount']);
		foreach($calculated['lines'] as $line) {
			$item=new \WC_Order_Item_Product(); $item->set_product($line['product']); $item->set_quantity($line['quantity']);
			$item->set_subtotal($line['subtotal']); $item->set_total($line['total']); $item->set_taxes($line['taxes']);
			$item->add_meta_data('_fandoogh_pos_catalog_price',$line['catalog_price'],true); $item->add_meta_data('_fandoogh_pos_unit_price',$line['unit_price'],true);
			$item->add_meta_data('_fandoogh_pos_sku',$line['sku'],true);
			$order->add_item($item);
		}
		$order->update_taxes(); $order->calculate_totals(false); $order->save();
		$claim['order_id']=$order->get_id();
		if(!update_option($key,$claim,false)) { throw new \RuntimeException('claim-save'); }
		if(pos_round_minor($order->get_total())!==$calculated['total_minor']) { throw new \RuntimeException('tax-total-mismatch'); }
		$force_hold=static function($minutes,$candidate) use($order) { return $candidate->get_id()===$order->get_id() ? 10 : $minutes; };
		add_filter('woocommerce_order_hold_stock_minutes',$force_hold,PHP_INT_MAX,2);
		try { wc_reserve_stock_for_order($order); } finally { remove_filter('woocommerce_order_hold_stock_minutes',$force_hold,PHP_INT_MAX); }
		$paid_attempt=true; $order->payment_complete($payment['reference']);
		if(!$order->is_paid()) { throw new \RuntimeException('payment-not-completed'); }
		wc_maybe_reduce_stock_levels($order->get_id());
		$order->update_status('completed',__('تحویل حضوری به مشتری؛ پرداخت توسط فروشنده تأیید شد.','fandoogh-manager'));
		if(!pos_order_fulfilled($order)) { throw new \RuntimeException('delivery-or-stock-not-completed'); }
		wc_release_stock_for_order($order);
		$claim['state']='completed'; update_option($key,$claim,false);
		record_audit_event('order_created',$session['user']->ID,$session['id'],$session['device_label'],'order',$order->get_id(),array('outcome'=>'pos_paid','order_id'=>$order->get_id(),'count'=>count($calculated['lines'])));
		return orders_no_store_response(array('data'=>invoice_snapshot($order),'idempotent_replay'=>false));
	} catch(\Throwable $error) {
		if($order && $order->get_id()) {
			$claim['order_id']=$order->get_id();
			if(!$paid_attempt) { try { $order->update_status('cancelled'); wc_release_stock_for_order($order); } catch(\Throwable $cleanup) { /* Keep the claim for review. */ } }
		}
		$claim['state']=$paid_attempt ? 'review' : 'failed'; update_option($key,$claim,false);
		return orders_error('fandoogh_pos_review',$paid_attempt ? __('سفارش ایجاد شد؛ نتیجهٔ پرداخت و موجودی را پیش از فروش دوباره بررسی کنید.','fandoogh-manager') : __('ثبت فروش انجام نشد؛ موجودی و سفارش ایجادشده را بررسی کنید.','fandoogh-manager'),409,array('order_id'=>$claim['order_id'],'state'=>$claim['state']));
	}
}

/** Query the explicit source field on HPOS; adapt only our custom query on CPT. */
function pos_channel_query_args( $args, $channel ) {
	if('all'===$channel) { return $args; }
	if(class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
		$source='pos'===$channel ? array('field'=>'created_via','value'=>POS_CREATED_VIA,'compare'=>'=') : array('relation'=>'OR',array('field'=>'created_via','value'=>POS_CREATED_VIA,'compare'=>'!='),array('field'=>'created_via','compare'=>'NOT EXISTS'));
		$args['field_query']=empty($args['field_query']) ? array($source) : array('relation'=>'AND',$args['field_query'],$source);
	} else { $args['fandoogh_sales_channel']=$channel; }
	return $args;
}
function pos_channel_cpt_query( $query,$vars ) {
	$channel=$vars['fandoogh_sales_channel'] ?? '';
	$source=null;
	if('pos'===$channel) { $source=array('key'=>'_created_via','value'=>POS_CREATED_VIA,'compare'=>'='); }
	if('online'===$channel) { $source=array('relation'=>'OR',array('key'=>'_created_via','value'=>POS_CREATED_VIA,'compare'=>'!='),array('key'=>'_created_via','compare'=>'NOT EXISTS')); }
	if($source) { $query['meta_query']=empty($query['meta_query']) ? array($source) : array('relation'=>'AND',$query['meta_query'],$source); }
	return $query;
}
function order_sales_channel( $order ) { return method_exists($order,'get_created_via') && POS_CREATED_VIA===$order->get_created_via() ? 'pos' : 'online'; }
