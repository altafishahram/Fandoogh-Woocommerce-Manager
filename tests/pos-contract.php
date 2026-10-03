<?php
/** Production handlers with isolated WP/Woo doubles: no site, SQL or physical printer. */
namespace {
if('cli'!==PHP_SAPI) { exit; }
define('ABSPATH',__DIR__.'/');
class WP_Error {
    public $code,$message,$data;
    function __construct($c,$m,$d=array()) {$this->code=$c;$this->message=$m;$this->data=$d;}
    function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} function get_error_data($c=null){return $this->data;}
    function add_data($d,$c=null){$this->data=$d;}
}
class WP_REST_Response { public $data,$headers=array(); function __construct($d){$this->data=$d;} function header($k,$v){$this->headers[$k]=$v;} }
class WP_REST_Server { const READABLE='GET'; }
class Request { public $body,$params; function __construct($body=array(),$params=array()){$this->body=$body;$this->params=$params;} function get_json_params(){return $this->body;} function get_param($k){return $this->params[$k]??null;} }
function __($v,$domain=''){return $v;} function absint($v){return abs((int)$v);} function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));} function wp_strip_all_tags($v){return strip_tags((string)$v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);} function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);} function email_exists($v){return $GLOBALS['email_exists']??false;}
function rest_ensure_response($v){return new WP_REST_Response($v);} function wc_format_decimal($v,$dp=false,$trim=false){return false===$dp ? (string)$v : number_format((float)$v,$dp,'.','');}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;} function add_option($k,$v,$deprecated='',$auto='no'){if(isset($GLOBALS['options'][$k])) return false;$GLOBALS['options'][$k]=$v;return true;}
function update_option($k,$v,$auto=false){$different=($GLOBALS['options'][$k]??null)!==$v;$GLOBALS['options'][$k]=$v;return $different;}
function wc_get_price_decimals(){return $GLOBALS['decimals'];} function wc_round_tax_total($n,$dp=null){return round($n,$dp??wc_get_price_decimals(),PHP_ROUND_HALF_UP);}
function wc_prices_include_tax(){return $GLOBALS['inclusive'];} function wc_tax_enabled(){return $GLOBALS['tax'];}
function wc_get_base_location(){return array('country'=>'IR','state'=>'TEH');} function get_woocommerce_currency(){return 'IRT';} function get_woocommerce_currency_symbol($code){return 'تومان';}
function get_current_user_id(){return 7;} function wp_salt($key){return 'private-test-signing-key';} function wp_generate_password(...$args){return 'random-password';}
function user_can(...$args){return $GLOBALS['capability'];} function current_user_can(...$args){return $GLOBALS['capability'];}
function wp_get_attachment_image_url(...$args){return false;} function get_bloginfo($key){return 'Test Store';} function home_url($path=''){return 'https://example.test'.$path;}
function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['filters'][$hook]=$callback;} function remove_filter($hook,$callback,$priority=10){unset($GLOBALS['filters'][$hook]);}
function register_rest_route($namespace,$route,$args){$GLOBALS['routes'][$route]=$args;}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;} function wc_get_products($args){$GLOBALS['catalog_args']=$args;return (object)array('products'=>array_values($GLOBALS['products']),'max_num_pages'=>2);}
function wc_get_product_id_by_sku($sku){foreach($GLOBALS['products'] as $p){if($p->get_sku()===$sku) return $p->get_id();}return 0;}
function wc_get_orders($args){$GLOBALS['order_args']=$args;return (object)array('orders'=>array_values($GLOBALS['orders']),'max_num_pages'=>1);}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;} function wc_get_order_statuses(){return array('wc-pending'=>'Pending','wc-completed'=>'Completed','wc-processing'=>'Processing','wc-cancelled'=>'Cancelled','wc-refunded'=>'Refunded','wc-on-hold'=>'On hold');}
function wc_get_held_stock_quantity($p){return $GLOBALS['held'][$p->get_id()]??0;}
function wc_reserve_stock_for_order($order){$GLOBALS['reserve_calls']++;$GLOBALS['hold_minutes']=($GLOBALS['filters']['woocommerce_order_hold_stock_minutes'])(0,$order);if($GLOBALS['reserve_error']) throw new \RuntimeException('stock race');}
function wc_release_stock_for_order($order){$GLOBALS['release_calls']++;}
function wc_maybe_reduce_stock_levels($id){$order=wc_get_order($id);if($order->reduced) return;foreach($order->items as $i){$p=$i->product;if($p->managing_stock()){$managed=wc_get_product($p->get_stock_managed_by_id());$managed->stock-=$i->qty;}}$order->reduced=true;}
class WC_Tax {
    static function find_rates($location){return array(1=>array('rate'=>$GLOBALS['rate']));}
    static function calc_tax($price,$rates,$inclusive){$rate=$rates[1]['rate']/100;return array(1=>$inclusive ? $price-$price/(1+$rate) : $price*$rate);}
}
class WC_Product {
    public $id,$price,$stock=10,$type='simple',$parent=0,$managed=0,$sold=false,$status='publish',$backorders=false,$tax_status='taxable';
    function __construct($id,$price){$this->id=$id;$this->price=$price;}
    function get_id(){return $this->id;} function is_type($t){return in_array($this->type,(array)$t,true);} function is_purchasable(){return true;} function is_in_stock(){return $this->stock>0||$this->backorders;}
    function get_parent_id(){return $this->parent;} function get_status(){return $this->status;} function get_price(){return $this->price;} function get_name(){return 'Product '.$this->id;}
    function get_sku(){return $this->id===1 ? '00123' : 'SKU-'.$this->id;} function get_type(){return $this->type;} function get_image_id(){return 0;} function get_stock_status(){return 'instock';} function get_stock_quantity(){return $this->stock;}
    function is_sold_individually(){return $this->sold;} function managing_stock(){return true;} function backorders_allowed(){return $this->backorders;} function get_stock_managed_by_id(){return $this->managed?:$this->id;}
    function get_tax_class(){return '';} function get_tax_status(){return $this->tax_status;}
}
class WC_Customer {
    public $id=0,$values=array();
    function __construct($id=null){if($id){$this->id=$id;$this->values=$GLOBALS['customers'][$id]??array();}}
    function __call($name,$args){if(strpos($name,'set_')===0){$this->values[substr($name,4)]=$args[0];return;}return $this->values[substr($name,4)]??'';}
    function get_id(){return $this->id;} function get_role(){return 'customer';} function get_is_vat_exempt(){return (bool)($this->values['is_vat_exempt']??false);}
    function save(){$this->id=77;$GLOBALS['customers'][$this->id]=$this->values;return $this->id;}
}
class WC_Customer_Data_Store {}
class WC_Order_Item_Product {
    public $product,$qty=0,$subtotal='0',$total='0',$taxes=array('subtotal'=>array(),'total'=>array()),$metadata=array();
    function set_product($p){$this->product=$p;} function set_quantity($n){$this->qty=$n;} function set_subtotal($n){$this->subtotal=$n;} function set_total($n){$this->total=$n;} function set_taxes($n){$this->taxes=$n;}
    function add_meta_data($k,$v,$unique){$this->metadata[$k]=$v;} function get_product(){return $this->product;} function get_name(){return $this->product->get_name();} function get_quantity(){return $this->qty;}
    function get_meta($key,$single=true){return $this->metadata[$key]??'';}
    function get_subtotal(){return $this->subtotal;} function get_total(){return $this->total;}
    function get_total_tax(){return $this->taxsum('total');} function get_subtotal_tax(){return $this->taxsum('subtotal');}
    function taxsum($key){return array_sum(array_map(function($n){return 'yes'===get_option('woocommerce_tax_round_at_subtotal')?$n:wc_round_tax_total($n);},$this->taxes[$key]));}
}
class WC_Order {
    public $id=0,$items=array(),$meta=array(),$values=array('status'=>'pending','currency'=>'IRT'),$total=0,$cart_tax=0,$reduced=false,$address=array();
    function __call($name,$args){$key=substr($name,4);if(strpos($name,'set_')===0){$this->values[$key]=$args[0];return;} if(strpos($key,'billing_')===0)return $this->address[substr($key,8)]??'';return $this->values[$key]??'';}
    function set_address($a,$type){$this->address=$a;} function add_item($i){$this->items[]=$i;} function update_meta_data($k,$v){$this->meta[$k]=$v;} function get_meta($k,$single=true){return $this->meta[$k]??'';}
    function get_status(){return $this->values['status'];} function get_created_via(){return $this->values['created_via']??'';} function get_id(){return $this->id;}
    function get_items($type='line_item'){return $type==='line_item'?$this->items:array();} function get_total(){return (string)$this->total;} function get_total_tax(){return $this->cart_tax;}
    function get_shipping_total(){return 0;} function get_shipping_tax(){return 0;} function get_total_refunded(){return $this->values['refund']??0;} function get_date_created(){return null;} function get_order_number(){return $this->id;}
    function get_customer_id(){return $this->values['customer_id']??0;} function get_billing_first_name(){return $this->address['first_name']??'';} function get_billing_last_name(){return $this->address['last_name']??'';} function get_billing_phone(){return $this->address['phone']??'';}
    function is_paid(){return in_array($this->get_status(),array('processing','completed'),true);} function update_taxes(){ $t=0;foreach($this->items as $i){$t+=$i->get_total_tax();}$this->cart_tax=wc_round_tax_total($t);$this->save(); }
    function calculate_totals($tax=false){$t=0;foreach($this->items as $i){$t+='yes'===get_option('woocommerce_tax_round_at_subtotal')?(float)$i->total:round((float)$i->total,wc_get_price_decimals());}$this->total=round($t+$this->cart_tax,wc_get_price_decimals());$this->save();}
    function save(){if(!$this->id){$this->id=count($GLOBALS['orders'])+91;}$GLOBALS['orders'][$this->id]=$this;return $this->id;}
    function payment_complete($ref){$this->values['status']='processing';if($GLOBALS['payment_error'])throw new \RuntimeException('uncertain after paid');}
    function update_status($s,$note=''){$this->values['status']=$s;}
    function get_data_store(){return new class { function get_stock_reduced($id){return wc_get_order($id)->reduced;} };}
}
}
namespace Automattic\WooCommerce\Utilities { class OrderUtil { public static $enabled=false; static function custom_orders_table_usage_is_enabled(){return self::$enabled;} } }
namespace Fandoogh_Manager {
const REST_NAMESPACE='fandoogh-manager/v1';
function get_session_context(){return array('user'=>(object)array('ID'=>7),'scopes'=>$GLOBALS['scopes'],'id'=>'session','device_label'=>'test');}
function csrf_permission($r){return $GLOBALS['csrf'] ? true : new \WP_Error('csrf','denied',array('status'=>403));}
function session_has_scope($name,$scopes){return !empty($scopes[$name]);} function session_has_major_changes_access($s){return $GLOBALS['major'];} function apply_session_user_context($s){}
function get_public_currency(){return array('code'=>'IRT','label'=>'تومان');} function get_public_branding(){return array('name'=>'Current Store','logo_url'=>'https://example.test/logo.png');}
function public_asset_url($url){return $url;} function record_audit_event(...$args){$GLOBALS['audit'][]=$args;} function get_settings(){return array('analytics_enabled'=>true);}
}
namespace {
require __DIR__.'/../app/orders.php'; require __DIR__.'/../app/customers.php'; require __DIR__.'/../app/pos.php'; require __DIR__.'/../app/invoice.php'; require __DIR__.'/../app/analytics.php';
function reset_fixture(){
    \Automattic\WooCommerce\Utilities\OrderUtil::$enabled=false;
    $GLOBALS['options']=array('woocommerce_schema_version'=>1000,'woocommerce_tax_round_at_subtotal'=>'no','woocommerce_store_address'=>'Current address');
    $GLOBALS['decimals']=2;$GLOBALS['inclusive']=false;$GLOBALS['tax']=false;$GLOBALS['rate']=10;$GLOBALS['held']=array();$GLOBALS['orders']=array();$GLOBALS['customers']=array();$GLOBALS['products']=array(1=>new WC_Product(1,'100.00'),2=>new WC_Product(2,'60.00'));
    $GLOBALS['scopes']=array_fill_keys(array('orders.read','orders.create','products.read','customers.read','customers.write','analytics.read'),true);$GLOBALS['csrf']=true;$GLOBALS['capability']=true;$GLOBALS['major']=true;$GLOBALS['reserve_error']=false;$GLOBALS['payment_error']=false;$GLOBALS['reserve_calls']=0;$GLOBALS['release_calls']=0;$GLOBALS['email_exists']=false;
}
function eq($expected,$actual,$why=''){if($expected!==$actual)throw new \RuntimeException($why.' expected '.var_export($expected,true).' got '.var_export($actual,true));}
function throws_invalid($fn){try{$fn();}catch(\InvalidArgumentException $e){return;}throw new \RuntimeException('invalid input accepted');}
$count=0;
function check($name,$fn){global $count;reset_fixture();try{$fn();$count++;echo 'PASS '.$name.PHP_EOL;}catch(\Throwable $e){fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage().PHP_EOL);exit(1);}}
function basket(){return array('items'=>array(array('id'=>1,'quantity'=>2),array('id'=>2,'quantity'=>1)),'customer'=>array('mode'=>'guest'),'discount'=>array('type'=>'amount','value'=>'10'));}
function sale_body($key='sale-test-00001'){ $b=basket();$q=\Fandoogh_Manager\pos_quote(new Request($b));if(is_wp_error($q))throw new \RuntimeException($q->message);$b['quote_token']=$q->data['data']['quote_token'];$b['idempotency_key']=$key;$b['payment']=array('method'=>'cash','cash_received'=>'250','card_amount'=>'0','received'=>true);return $b; }
check('minor units support Persian digits and reject negative/exponent/precision',function(){eq(123450,\Fandoogh_Manager\pos_minor('۱۲۳۴٫۵'));foreach(array('-1','1e3',true,'1,000','1.001') as $n) throws_invalid(function()use($n){\Fandoogh_Manager\pos_minor($n);});});
check('quote computes manual discount without writing orders or product price',function(){$q=\Fandoogh_Manager\pos_calculate(basket(),new Request());eq('250.00',$q['total']);eq('10.00',$q['discount_total']);eq(array(),$GLOBALS['orders']);eq('100.00',$GLOBALS['products'][1]->price);});
check('price override only affects the order line',function(){$b=basket();$b['items'][0]['unit_price']='80';$q=\Fandoogh_Manager\pos_calculate($b,new Request());eq('210.00',$q['total']);eq('100.00',$GLOBALS['products'][1]->price);});
check('percent allocation preserves cents and never makes a last line negative',function(){$GLOBALS['products'][1]->price='0.03';$GLOBALS['products'][2]->price='0.01';$b=basket();$b['items'][0]['quantity']=1;$b['discount']=array('type'=>'percent','value'=>'50');$q=\Fandoogh_Manager\pos_calculate($b,new Request());eq('0.02',$q['total']);foreach($q['lines'] as $l){if((float)$l['total']<0)throw new \RuntimeException('negative line');} });
check('full discount and free items remain valid',function(){$b=basket();$b['discount']['value']='260';eq('0.00',\Fandoogh_Manager\pos_calculate($b,new Request())['total']);$GLOBALS['products'][1]->price='0';$b['items']=array(array('id'=>1,'quantity'=>1));$b['discount']['value']='0';eq('0.00',\Fandoogh_Manager\pos_calculate($b,new Request())['total']);});
foreach(array('no','yes') as $round){foreach(array(false,true) as $inclusive){check('Woo totals match tax rounding '.$round.' inclusive '.(int)$inclusive,function()use($round,$inclusive){$GLOBALS['options']['woocommerce_tax_round_at_subtotal']=$round;$GLOBALS['inclusive']=$inclusive;$GLOBALS['tax']=true;$GLOBALS['products'][1]->price='0.07';$GLOBALS['products'][2]->price='0.08';$b=basket();$b['discount']['value']='0.01';$q=\Fandoogh_Manager\pos_calculate($b,new Request());$o=new WC_Order();foreach($q['lines'] as $l){$i=new WC_Order_Item_Product();$i->set_product($l['product']);$i->set_quantity($l['quantity']);$i->set_subtotal($l['subtotal']);$i->set_total($l['total']);$i->set_taxes($l['taxes']);$o->add_item($i);}$o->update_taxes();$o->calculate_totals(false);eq($q['total_minor'],\Fandoogh_Manager\pos_round_minor($o->get_total()));});}}
check('VAT exempt inclusive prices remove base tax',function(){$GLOBALS['inclusive']=true;$GLOBALS['tax']=true;$GLOBALS['products'][1]->price='110';$GLOBALS['customers'][9]=array('is_vat_exempt'=>true);$b=basket();$b['items']=array(array('id'=>1,'quantity'=>1));$b['discount']['value']='0';$b['customer']=array('mode'=>'registered','id'=>9);$q=\Fandoogh_Manager\pos_calculate($b,new Request());eq('100.00',$q['total']);eq('0.00',$q['tax']);});
check('reserved stock and shared variation stock cannot oversell',function(){$GLOBALS['held'][1]=9;throws_invalid(function(){\Fandoogh_Manager\pos_calculate(basket(),new Request());});$GLOBALS['held']=array();$GLOBALS['products'][2]->type='variation';$GLOBALS['products'][2]->parent=1;$GLOBALS['products'][2]->managed=1;$GLOBALS['products'][1]->stock=2;throws_invalid(function(){\Fandoogh_Manager\pos_calculate(basket(),new Request());});});
check('invalid quantities, repeated IDs and excessive discount fail closed',function(){foreach(array(0,-1,1.2,1000) as $qty){$b=basket();$b['items'][0]['quantity']=$qty;throws_invalid(function()use($b){\Fandoogh_Manager\pos_calculate($b,new Request());});}$b=basket();$b['items'][1]['id']=1;throws_invalid(function()use($b){\Fandoogh_Manager\pos_calculate($b,new Request());});$b=basket();$b['discount']['value']='261';throws_invalid(function()use($b){\Fandoogh_Manager\pos_calculate($b,new Request());});});
check('payment cash/card/mixed computes change and rejects underpayment',function(){eq('50.00',\Fandoogh_Manager\pos_payment(array('method'=>'mixed','cash_received'=>'200','card_amount'=>'100','received'=>true),25000)['change']);eq('0.00',\Fandoogh_Manager\pos_payment(array('method'=>'card','cash_received'=>'0','card_amount'=>'250','received'=>true),25000)['change']);foreach(array(array('method'=>'cash','cash_received'=>'249','received'=>true),array('method'=>'mixed','cash_received'=>'250','card_amount'=>'0','received'=>true),array('method'=>'card','card_amount'=>'250','received'=>false)) as $p) throws_invalid(function()use($p){\Fandoogh_Manager\pos_payment($p,25000);});});
check('POS permissions enforce CSRF, scopes, capability and major changes',function(){eq(true,\Fandoogh_Manager\pos_sale_permission(new Request()));foreach(array('csrf','capability','major') as $flag){$GLOBALS[$flag]=false;eq(true,is_wp_error(\Fandoogh_Manager\pos_sale_permission(new Request())));$GLOBALS[$flag]=true;}foreach(array('orders.read','orders.create','products.read') as $s){$GLOBALS['scopes'][$s]=false;eq(true,is_wp_error(\Fandoogh_Manager\pos_sale_permission(new Request())));$GLOBALS['scopes'][$s]=true;}});
check('signed quote rejects a changed price before creating any order',function(){$b=sale_body();$GLOBALS['products'][1]->price='101';$result=\Fandoogh_Manager\pos_sale(new Request($b));eq('fandoogh_pos_quote_changed',$result->code);eq(0,count($GLOBALS['orders']));});
check('quote expiry and signature bind the cashier',function(){$b=sale_body();$b['quote_token']='1.fake.fake';eq('fandoogh_pos_quote_changed',\Fandoogh_Manager\pos_sale(new Request($b))->code);eq(0,count($GLOBALS['orders']));});
check('sale completes, reserves despite zero hold setting and reduces stock once',function(){$b=sale_body();$sale=\Fandoogh_Manager\pos_sale(new Request($b));if(is_wp_error($sale))throw new \RuntimeException($sale->message);eq('pos',$sale->data['data']['channel']);eq(true,$sale->data['data']['paid']);eq(8,$GLOBALS['products'][1]->stock);eq(9,$GLOBALS['products'][2]->stock);eq(10,$GLOBALS['hold_minutes']);eq(false,isset($GLOBALS['filters']['woocommerce_order_hold_stock_minutes']));$replay=\Fandoogh_Manager\pos_sale(new Request($b));eq(true,$replay->data['idempotent_replay']);eq(1,count($GLOBALS['orders']));eq(1,$GLOBALS['reserve_calls']);eq(8,$GLOBALS['products'][1]->stock);eq('100.00',$GLOBALS['products'][1]->price);eq('no-store, no-cache, must-revalidate, max-age=0',$sale->headers['Cache-Control']);});
check('same idempotency key cannot be reused with altered payload',function(){$b=sale_body();\Fandoogh_Manager\pos_sale(new Request($b));$b['note']='changed';eq('fandoogh_pos_conflict',\Fandoogh_Manager\pos_sale(new Request($b))->code);eq(1,count($GLOBALS['orders']));});
check('stock race cancels unpaid order and preserves failed claim',function(){$b=sale_body();$GLOBALS['reserve_error']=true;$result=\Fandoogh_Manager\pos_sale(new Request($b));eq('failed',$result->data['state']);eq('cancelled',wc_get_order(91)->get_status());eq(10,$GLOBALS['products'][1]->stock);$replay=\Fandoogh_Manager\pos_sale(new Request($b));eq(true,$replay->data['safe_to_restart']);eq(1,count($GLOBALS['orders']));});
check('uncertain paid hook never cancels or duplicates the paid order',function(){$b=sale_body();$GLOBALS['payment_error']=true;$result=\Fandoogh_Manager\pos_sale(new Request($b));eq('review',$result->data['state']);eq('processing',wc_get_order(91)->get_status());$replay=\Fandoogh_Manager\pos_sale(new Request($b));eq(true,is_wp_error($replay));eq(false,$replay->data['safe_to_restart']);eq(1,count($GLOBALS['orders']));});
check('new customer saves real contact without fake email',function(){$b=basket();$b['customer']=array('mode'=>'new','first_name'=>'Ali','phone'=>'09120000000','address_1'=>'Tehran');$q=\Fandoogh_Manager\pos_quote(new Request($b));$b['quote_token']=$q->data['data']['quote_token'];$b['idempotency_key']='customer-test-001';$b['payment']=array('method'=>'cash','cash_received'=>'250','received'=>true);$sale=\Fandoogh_Manager\pos_sale(new Request($b));if(is_wp_error($sale))throw new \RuntimeException($sale->message);eq('',$GLOBALS['customers'][77]['email']);eq('Ali',$GLOBALS['customers'][77]['billing_first_name']);eq('Tehran',$GLOBALS['customers'][77]['billing_address_1']);eq(77,$sale->data['data']['customer']['id']);});
check('invoice uses saved prices, current store and narrow private payment fields',function(){$b=sale_body();\Fandoogh_Manager\pos_sale(new Request($b));$order=wc_get_order(91);$order->meta['_fandoogh_pos_payment']['secret']='never expose';$GLOBALS['products'][1]->price='999';$order->values['refund']=50;$invoice=\Fandoogh_Manager\get_order_invoice(new Request(array(),array('id'=>91)));eq('Current Store',$invoice->data['data']['store']['name']);eq('Current address',$invoice->data['data']['store']['address']);eq('100',$invoice->data['data']['lines'][0]['unit_price']);eq('200',$invoice->data['data']['totals']['net']);eq(false,isset($invoice->data['data']['payment']['tenders']['secret']));});
check('report channel filter is applied before bounded pagination and handles legacy missing source',function(){$args=\Fandoogh_Manager\pos_channel_query_args(array('limit'=>100),'pos');eq('pos',$args['fandoogh_sales_channel']);$cpt=\Fandoogh_Manager\pos_channel_cpt_query(array(),array('fandoogh_sales_channel'=>'online'));eq('NOT EXISTS',$cpt['meta_query'][0][1]['compare']);$r=\Fandoogh_Manager\get_analytics_summary(new Request(array(),array('channel'=>'pos','range'=>'today')));eq('pos',$GLOBALS['order_args']['fandoogh_sales_channel']);eq('pos',$r->data['data']['channel']);});
check('financial channels account for partial and full refunds',function(){$b=sale_body();\Fandoogh_Manager\pos_sale(new Request($b));wc_get_order(91)->values['refund']=50;$online=new WC_Order();$online->values['status']='refunded';$online->values['refund']=100;$online->total=100;$online->save();$r=\Fandoogh_Manager\get_analytics_summary(new Request(array(),array('range'=>'today')));eq('250',$r->data['data']['channels']['pos']['gross']);eq('200',$r->data['data']['channels']['pos']['net']);eq('0',$r->data['data']['channels']['online']['net']);eq('350',$r->data['data']['sales']['gross']);eq('150',$r->data['data']['sales']['refunded']);eq('200',$r->data['data']['sales']['net']);});
check('HPOS field filters include NULL sources in online and preserve other constraints',function(){\Automattic\WooCommerce\Utilities\OrderUtil::$enabled=true;$q=\Fandoogh_Manager\pos_channel_query_args(array('field_query'=>array(array('field'=>'total','value'=>'0','compare'=>'>'))),'online');eq('AND',$q['field_query']['relation']);eq('total',$q['field_query'][0][0]['field']);eq('OR',$q['field_query'][1]['relation']);eq('NOT EXISTS',$q['field_query'][1][1]['compare']);eq('=',\Fandoogh_Manager\pos_channel_query_args(array(),'pos')['field_query'][0]['compare']);});
check('draft products and boolean quantities cannot bypass sale validation',function(){$GLOBALS['products'][1]->status='draft';throws_invalid(function(){\Fandoogh_Manager\pos_calculate(basket(),new Request());});$GLOBALS['products'][1]->status='publish';$b=basket();$b['items'][0]['quantity']=true;throws_invalid(function()use($b){\Fandoogh_Manager\pos_calculate($b,new Request());});});
echo $count.' POS contracts passed (Woo doubles, not a live site).'.PHP_EOL;
}
