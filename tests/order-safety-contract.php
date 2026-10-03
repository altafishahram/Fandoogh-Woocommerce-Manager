<?php
/** Production handlers with Woo-like persistence and injected failures; no live gateway. */
namespace {
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 public function __construct(public $code, public $message='', public $data=[]) {}
 public function get_error_code(){return $this->code;}
 public function get_error_message(){return $this->message;}
 public function get_error_data(){return $this->data;}
}
class WP_REST_Response {public function __construct(public $data){} public function header($k,$v){} }
function rest_ensure_response($v){return new WP_REST_Response($v);}
class Request {
 public function __construct(public $body,public $id=10){}
 public function get_json_params(){return $this->body;}
 public function get_header($k){return $k==='content-type'?'application/json':'';}
 public function get_param($k){return $k==='id'?$this->id:null;}
}
function __( $v,$domain='' ){return $v;}
function absint($v){return abs((int)$v);}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$v));}
function sanitize_text_field($v){return trim(strip_tags($v));}
function sanitize_email($v){return (string)$v;}
function wp_strip_all_tags($v){return strip_tags($v);}
function wp_json_encode($v){return json_encode($v);}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function add_option($k,$v,$unused='',$autoload='no'){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function update_option($k,$v,$autoload=false){if(($GLOBALS['fail_complete']??false)&&str_starts_with($k,'fandoogh_refund_')&&is_array($v)&&($v['state']??'')==='completed')return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function add_action($k,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$k][$priority][]=$fn;}
function remove_action($k,$fn,$priority=10){foreach($GLOBALS['hooks'][$k][$priority]??[] as $i=>$f)if($f===$fn)unset($GLOBALS['hooks'][$k][$priority][$i]);}
function do_action($k,...$args){$hooks=$GLOBALS['hooks'][$k]??[];ksort($hooks);foreach($hooks as $handlers)foreach($handlers as $fn)$fn(...$args);}
function wc_get_price_decimals(){return $GLOBALS['decimals']??2;}
function wc_format_decimal($v,$dp=false,$trim=false){return $dp===false?(string)$v:number_format((float)$v,$dp,'.','');}
function wc_get_order_statuses(){return ['wc-pending'=>'Pending','wc-completed'=>'Completed','wc-refunded'=>'Refunded'];}
function wc_get_orders($args){return array_values($GLOBALS['orders']);}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
class Item {
 public function __construct(public $qty,public $total,public $taxes=[]){}
 public function get_quantity(){return $this->qty;}
 public function get_total(){return $this->total;}
 public function get_taxes(){return ['total'=>$this->taxes];}
}
class WC_Order {
 public $items=[],$refunds=[],$meta=[],$status='pending',$total='0.00';
 public function __construct(public $id=10){}
 public function get_id(){return $this->id;}
 public function get_status(){return $this->status;}
 public function set_status($v){$this->status=$v;}
 public function get_items($type='line_item'){return $type==='line_item'?$this->items:[];}
 public function get_total(){return $this->total;}
 public function get_payment_method(){return 'test';}
 public function is_paid(){return (bool)($GLOBALS['paid_hook']??false);}
 public function get_data_store(){return new class {public function get_stock_reduced($id){return (bool)($GLOBALS['stock_hook']??false);}};}
 public function get_refunds(){return array_values($this->refunds);}
 public function get_total_refunded(){return array_sum(array_map(fn($r)=>(float)$r->get_amount(),$this->refunds));}
 public function get_qty_refunded_for_item($id){return array_sum(array_map(fn($r)=>$r->items[$id]->qty??0,$this->refunds));}
 public function get_total_refunded_for_item($id){return -array_sum(array_map(fn($r)=>(float)($r->items[$id]->total??0),$this->refunds));}
 public function get_tax_refunded_for_item($id,$rate){return -array_sum(array_map(fn($r)=>(float)($r->items[$id]->taxes[$rate]??0),$this->refunds));}
 public function get_meta($k){return $this->meta[$k]??'';}
 public function update_meta_data($k,$v){$this->meta[$k]=$v;}
 public function save(){$GLOBALS['orders'][$this->id]=$this;return $this->id;}
 public function delete($force){if(!($GLOBALS['delete_failure']??false))unset($GLOBALS['orders'][$this->id]);}
 public function add_product($p,$qty,$args){$this->items[1]=new Item($qty,'10.00');return 1;}
 public function calculate_taxes(){if($GLOBALS['tax_failure']??false)throw new \RuntimeException('tax hook');}
 public function calculate_totals(){$this->total='10.00';}
 public function apply_coupon($c){return true;}
}
class WC_Order_Refund extends WC_Order {
 public function __construct($id,public $parent,public $amount){parent::__construct($id);}
 public function get_parent_id(){return $this->parent;}
 public function get_amount(){return $this->amount;}
 public function save(){parent::save();$GLOBALS['orders'][$this->parent]->refunds[$this->id]=$this;return $this->id;}
}
class WC_Coupon {public function __construct($code){} public function get_id(){return 0;}}
class WC_Product {public function get_id(){return 1;} public function get_status(){return 'publish';} public function is_type($t){return $t==='simple';}}
function wc_get_product($id){return ($GLOBALS['missing_product']??false)?false:new WC_Product();}
function wc_create_order($args){$GLOBALS['creates']++;$o=new WC_Order(++$GLOBALS['next_id']);$o->save();if($GLOBALS['factory_failure']??false)throw new \RuntimeException('saved then failed');return $o;}
function wc_create_refund($args){
 $GLOBALS['refund_calls'][]=$args;
 if(isset($GLOBALS['concurrent'])){$callback=$GLOBALS['concurrent'];unset($GLOBALS['concurrent']);$callback();}
 $r=new WC_Order_Refund(++$GLOBALS['next_id'],$args['order_id'],$args['amount']);
 foreach($args['line_items'] as $id=>$line)$r->items[$id]=new Item(-$line['qty'],-(float)$line['refund_total'],array_map(fn($v)=>-(float)$v,$line['refund_tax']));
 do_action('woocommerce_create_refund',$r,$args);$r->save();
 if(($GLOBALS['refund_failure']??'')==='throw')throw new \RuntimeException('gateway response lost');
 if(($GLOBALS['refund_failure']??'')==='error')return new WP_Error('gateway-unknown');
 if($args['restock_items'])foreach($r->items as $item)$GLOBALS['restocked']-= $item->qty;
 return $r;
}
function fixture($qty=2,$total='100.00',$tax='10.00'){
 $GLOBALS['options']=[];$GLOBALS['orders']=[];$GLOBALS['hooks']=[];$GLOBALS['next_id']=100;$GLOBALS['creates']=0;$GLOBALS['refund_calls']=[];$GLOBALS['restocked']=0;$GLOBALS['decimals']=2;$GLOBALS['user_id']=7;
 foreach(['delete_failure','factory_failure','tax_failure','missing_product','fail_complete','refund_failure','concurrent','paid_hook','stock_hook'] as $key)unset($GLOBALS[$key]);
 $o=new WC_Order(10);$o->items[1]=new Item($qty,$total,[9=>$tax]);$o->total=wc_format_decimal((float)$total+(float)$tax,2);$o->save();return $o;
}
function refund_request($key='refund-00001',$qty=1,$extra=[]){return new Request(array_merge(['idempotency_key'=>$key,'line_items'=>[['item_id'=>1,'quantity'=>$qty]],'refund_payment'=>false],$extra));}
function create_request($extra=[]){return new Request(array_merge(['idempotency_key'=>'create-00001','customer_type'=>'guest','line_items'=>[['product_id'=>1,'quantity'=>1]]],$extra));}
$GLOBALS['checks']=0;
function check($v,$message){if(!$v)throw new \RuntimeException($message);$GLOBALS['checks']++;}
function error_is($result,$code){check(is_wp_error($result)&&$result->get_error_code()===$code,'Expected '.$code.'; got '.(is_wp_error($result)?$result->get_error_code():get_debug_type($result)));}
}
namespace Fandoogh_Manager {
function get_session_context(){return ['user'=>(object)['ID'=>$GLOBALS['user_id']],'id'=>'session','device_label'=>'test','scopes'=>[]];}
function apply_session_user_context($s){}
function record_audit_event(...$args){}
require __DIR__.'/../app/orders.php';
}
namespace {
fixture();$request=refund_request();$r=\Fandoogh_Manager\refund_order($request);
check($r instanceof WP_REST_Response,'Refund should succeed');$a=$GLOBALS['refund_calls'][0];
check($a['amount']==='55.00'&&$a['line_items'][1]['refund_total']==='50.00'&&$a['line_items'][1]['refund_tax'][9]==='5.00','Refund saves line amount and per-rate tax');
check(wc_get_order(10)->get_total_refunded_for_item(1)==50&&wc_get_order(10)->get_tax_refunded_for_item(1,9)==5,'Negative Woo items are persisted');
$replay=\Fandoogh_Manager\refund_order($request);check($replay->data['idempotent_replay']&&count($GLOBALS['refund_calls'])===1&&$GLOBALS['restocked']===1,'Replay neither pays nor restocks twice');
// Other expensive items must never make an exhausted line refundable again.
wc_get_order(10)->items[2]=new Item(1,'1000.00');wc_get_order(10)->total='1110.00';
error_is(\Fandoogh_Manager\refund_order(refund_request('refund-00002',2)),'fandoogh_invalid_refund_quantity');
check(count($GLOBALS['refund_calls'])===1,'Over-refund rejected before write');
check(\Fandoogh_Manager\refund_order(refund_request('refund-00002')) instanceof WP_REST_Response,'Remaining quantity can be refunded');
check($GLOBALS['restocked']===2,'Only original quantity restocked');
fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('refund-duplicate',1,['line_items'=>[['item_id'=>1,'quantity'=>1],['item_id'=>1,'quantity'=>1]]])),'fandoogh_invalid_refund_items');
foreach([true,0,-1,'1.5','1e2'] as $q){fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('refund-invalid',$q)),'fandoogh_invalid_refund_items');}
foreach([-1,true,[1]] as $id){fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('refund-bad-id',1,['line_items'=>[['item_id'=>$id,'quantity'=>1]]])),'fandoogh_invalid_refund_items');}
fixture(2.5);error_is(\Fandoogh_Manager\refund_order(refund_request()),'fandoogh_invalid_refund_quantity');
fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('bool-amount-test',1,['amount'=>true])),'fandoogh_invalid_refund_amount');
fixture();wc_get_order(10)->items[1]->taxes=[9=>'4.00',12=>'6.00'];check(\Fandoogh_Manager\refund_order(refund_request()) instanceof WP_REST_Response,'Multiple tax rates refund');check($GLOBALS['refund_calls'][0]['line_items'][1]['refund_tax']===[9=>'2.00',12=>'3.00'],'Each tax rate is preserved');
fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('refund-mismatch',1,['amount'=>'54'])),'fandoogh_refund_line_amount');
check(!$GLOBALS['refund_calls'],'Mismatched line amount has no side effect');
fixture(3,'1.00','0.10');foreach([1,2,3] as $i)check(\Fandoogh_Manager\refund_order(refund_request('rounding-000'.$i)) instanceof WP_REST_Response,'Partial refund '.$i);
check(wc_format_decimal(wc_get_order(10)->get_total_refunded(),2)==='1.10'&&wc_format_decimal(wc_get_order(10)->get_total_refunded_for_item(1),2)==='1.00'&&wc_format_decimal(wc_get_order(10)->get_tax_refunded_for_item(1,9),2)==='0.10','Final quantity absorbs cent rounding');
fixture();$nested=null;$GLOBALS['concurrent']=function()use(&$nested){$GLOBALS['user_id']=8;$nested=\Fandoogh_Manager\refund_order(refund_request('concurrent-key'));$GLOBALS['user_id']=7;};
check(\Fandoogh_Manager\refund_order(refund_request()) instanceof WP_REST_Response,'First writer succeeds');error_is($nested,'fandoogh_refund_order_busy');check(count($GLOBALS['refund_calls'])===1,'Per-order lock serializes different users and keys');
foreach(['throw','error'] as $failure){
 fixture();$GLOBALS['refund_failure']=$failure;$request=refund_request();error_is(\Fandoogh_Manager\refund_order($request),'fandoogh_refund_review');
 foreach($GLOBALS['options'] as &$claim)if(is_array($claim))$claim['created_at']=time()-86400;unset($claim);
 error_is(\Fandoogh_Manager\refund_order($request),'fandoogh_refund_in_progress');error_is(\Fandoogh_Manager\refund_order(refund_request('different-key')),'fandoogh_refund_order_busy');
 check(count($GLOBALS['refund_calls'])===1&&isset($GLOBALS['options']['fandoogh_refund_order_10']),'Unknown result never expires or retries a write');
}
fixture();$GLOBALS['fail_complete']=true;$request=refund_request();check(\Fandoogh_Manager\refund_order($request) instanceof WP_REST_Response,'Refund completed despite option-write failure');$GLOBALS['fail_complete']=false;
$replay=\Fandoogh_Manager\refund_order($request);check($replay instanceof WP_REST_Response&&$replay->data['idempotent_replay']&&count($GLOBALS['refund_calls'])===1,'Saved completed marker recovers lost claim update');
fixture();error_is(\Fandoogh_Manager\refund_order(refund_request('automatic-test',1,['refund_payment'=>true])),'fandoogh_refund_gateway_unavailable');
check(!$GLOBALS['refund_calls']&&!isset($GLOBALS['options']['fandoogh_refund_order_10']),'Unsupported gateway preflight releases lock');
check(\Fandoogh_Manager\refund_order(refund_request('automatic-test')) instanceof WP_REST_Response,'Manual retry after preflight is safe');
fixture();check(\Fandoogh_Manager\refund_order(new Request(['idempotency_key'=>'amount-only-test','amount'=>'12.34','refund_payment'=>false])) instanceof WP_REST_Response,'Amount-only refund remains supported');
foreach(['missing_product','tax_failure','coupon'] as $failure){
 fixture();$extra=[];if($failure==='coupon')$extra['coupon_codes']=['invalid'];else $GLOBALS[$failure]=true;
 error_is(\Fandoogh_Manager\create_order(create_request($extra)),'fandoogh_order_creation_failed');
 check(count($GLOBALS['orders'])===1&&$GLOBALS['creates']===1&&!$GLOBALS['options'],'Factory-saved incomplete order deleted and claim released: '.$failure);
}
fixture();$GLOBALS['missing_product']=true;error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_creation_failed');$GLOBALS['missing_product']=false;
$created=\Fandoogh_Manager\create_order(create_request());check($created instanceof WP_REST_Response,'Confirmed rollback allows same-key retry');
$replay=\Fandoogh_Manager\create_order(create_request());check($replay->data['idempotent_replay']&&$GLOBALS['creates']===2&&count($GLOBALS['orders'])===2,'Successful creation replay preserves one final order');
fixture();$GLOBALS['missing_product']=true;$GLOBALS['delete_failure']=true;error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_rollback_failed');
foreach($GLOBALS['options'] as &$claim)$claim['created_at']=time()-86400;unset($claim);
error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_creation_in_progress');check($GLOBALS['creates']===1&&count($GLOBALS['orders'])===2,'Failed rollback cannot expire into duplicate order');
fixture();$GLOBALS['factory_failure']=true;error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_rollback_failed');error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_creation_in_progress');check($GLOBALS['creates']===1,'Unknown factory result cannot be retried');
foreach(['paid_hook','stock_hook'] as $hook){fixture();$GLOBALS[$hook]=true;$GLOBALS['tax_failure']=true;error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_rollback_failed');check(count($GLOBALS['orders'])===2,'Order with payment/stock effect preserved for review');error_is(\Fandoogh_Manager\create_order(create_request()),'fandoogh_order_creation_in_progress');}
echo 'Order safety: '.$GLOBALS['checks']." checks passed.\n";
}
