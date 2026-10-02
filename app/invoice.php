<?php
/** Printable snapshots of saved orders. No public invoice links or order keys. */
namespace Fandoogh_Manager;
if(!defined('ABSPATH')) { exit; }
function register_invoice_routes() {
	register_rest_route(REST_NAMESPACE,'/orders/(?P<id>\d+)/invoice',array('methods'=>'GET','callback'=>__NAMESPACE__.'\\get_order_invoice','permission_callback'=>__NAMESPACE__.'\\orders_read_permission'));
}
function invoice_store() {
	$brand=get_public_branding();
	$parts=array(get_option('woocommerce_store_address',''),get_option('woocommerce_store_address_2',''),get_option('woocommerce_store_city',''),get_option('woocommerce_store_postcode',''));
	return array('name'=>$brand['name'] ?? get_bloginfo('name'),'logo'=>$brand['logo_url'] ?? null,'address'=>implode('، ',array_filter(array_map(static function($value){return orders_clean_text($value,255);},$parts))),'phone'=>orders_clean_text(get_option('woocommerce_store_phone',''),80),'website'=>home_url('/'));
}
function invoice_snapshot($order) {
	$lines=array(); $subtotal=0; $discount=0;
	foreach($order->get_items('line_item') as $item) {
		$before=(float)$item->get_subtotal()+(float)$item->get_subtotal_tax();
		$after=(float)$item->get_total()+(float)$item->get_total_tax(); $qty=$item->get_quantity();
		$product=$item->get_product();
		$saved_sku=$item->get_meta('_fandoogh_pos_sku',true);
		$lines[]=array('name'=>orders_clean_text($item->get_name(),255),'sku'=>orders_clean_text($saved_sku ?: ($product ? $product->get_sku() : ''),100), 'quantity'=>$qty,'unit_price'=>orders_money($qty ? $before/$qty : 0),'discount'=>orders_money($before-$after),'tax'=>orders_money($item->get_total_tax()),'total'=>orders_money($after));
		$subtotal+=$before; $discount+=$before-$after;
	}
	$fees=array();
	foreach($order->get_items('fee') as $fee) { $fees[]=array('name'=>orders_clean_text($fee->get_name(),255),'total'=>orders_money((float)$fee->get_total()+(float)$fee->get_total_tax())); }
	$currency=get_public_currency(); $currency['code']=$order->get_currency();
	if(function_exists('get_woocommerce_currency_symbol')) { $currency['label']=html_entity_decode(get_woocommerce_currency_symbol($order->get_currency()),ENT_QUOTES,'UTF-8'); }
	$raw_payment=$order->get_meta('_fandoogh_pos_payment',true); $payment=null;
	if(is_array($raw_payment) && 'pos'===order_sales_channel($order)) {
		$payment=array();
		foreach(array('method','cash_received','cash_applied','card_amount','change','reference') as $key) { $payment[$key]=orders_clean_text($raw_payment[$key] ?? '',80); }
	}
	return array('id'=>$order->get_id(),'number'=>orders_clean_text($order->get_order_number(),80),'channel'=>order_sales_channel($order),'date'=>orders_date($order->get_date_created()),'status'=>orders_status_label($order->get_status()),'paid'=>$order->is_paid(),'store'=>invoice_store(),'customer'=>serialize_order_customer($order),'billing'=>serialize_order_address($order,'billing'),'currency'=>$currency,'decimals'=>pos_decimals(),'lines'=>$lines,'fees'=>$fees,'totals'=>array('subtotal'=>orders_money($subtotal),'discount'=>orders_money($discount),'tax'=>orders_money($order->get_total_tax()),'shipping'=>orders_money((float)$order->get_shipping_total()+(float)$order->get_shipping_tax()),'total'=>orders_money($order->get_total()),'refunded'=>orders_money($order->get_total_refunded()),'net'=>orders_money((float)$order->get_total()-(float)$order->get_total_refunded())),'payment'=>array('title'=>orders_clean_text($order->get_payment_method_title(),160),'tenders'=>is_array($payment)?$payment:null),'note'=>orders_clean_text($order->get_customer_note(),1000));
}
function get_order_invoice($request) {
	try {
		$order=compose_order_repository()->findById(absint($request->get_param('id')));
		if(!orders_is_readable_order($order)) { return orders_error('fandoogh_invoice_missing',__('سفارش برای چاپ پیدا نشد.','fandoogh-manager'),404); }
		return orders_no_store_response(array('data'=>invoice_snapshot($order)));
	} catch(\Throwable $error) { return orders_error('fandoogh_invoice_failed',__('دریافت فاکتور انجام نشد.','fandoogh-manager'),503); }
}
