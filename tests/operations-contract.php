<?php
/** CLI-only authorization, exact lookup, pagination and notification policy checks. */
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; } }
	function __( $text, $domain = '' ) { return $text; }
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function current_user_can( $cap ) { return $GLOBALS['capability']; }
	function user_can( $id, $cap ) { return $GLOBALS['capability']; }
	function get_current_user_id() { return 7; }
	function get_option( $key, $default = false ) { return $key === 'woocommerce_notify_low_stock_amount' ? 2 : $default; }
	function wp_parse_url( $url ) { return parse_url( $url ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_date( $format ) { return '23:00'; }
	class Request { private $params; public function __construct( $params = array() ) { $this->params=$params; } public function get_param( $key ) { return $this->params[$key] ?? null; } public function get_json_params() { return $this->params; } }
	class Product {
		public $id; public $status='publish'; public $type='simple'; public $quantity=1; public $manage=true; public $stock='instock'; public $threshold='';
		public function __construct( $id ) { $this->id=$id; }
		public function get_id() { return $this->id; } public function get_name() { return 'محصول ' . $this->id; } public function get_sku() { return $this->id === 1 ? '00123' : 'VAR-1'; }
		public function is_type( $type ) { return $this->type === $type; } public function get_parent_id() { return 1; } public function get_status() { return $this->status; }
		public function get_stock_status() { return $this->stock; } public function get_stock_quantity() { return $this->quantity; } public function get_manage_stock() { return $this->manage; } public function get_low_stock_amount() { return $this->threshold; }
	}
	class Order {
		public $id; public $shipment=array('status'=>'pending'); public $age=86400; public $virtual=false;
		public function __construct( $id ) { $this->id=$id; } public function get_id() { return $this->id; } public function get_order_number() { return (string)$this->id; } public function get_status() { return 'processing'; }
		public function get_date_paid() { return null; } public function get_date_created() { return new DateTimeImmutable( '@' . (time()-$this->age) ); } public function needs_shipping_address() { return !$this->virtual; }
	}
	class Repository {
		public $products; public $orders; public $queries=array(); public $available=true; public $invalid=false;
		public function isAvailable() { return $this->available; } public function findIdBySku( $sku ) { return $sku==='00123' ? 1 : ($sku==='VAR-1' ? 2 : 0); } public function findById( $id ) { return $this->products[$id] ?? false; }
		public function query( $args ) { $this->queries[]=$args; if($this->invalid) return false; if(isset($args['type'])) return (object)array('products'=>array_values(array_filter($this->products,static function($product) use($args) { return in_array($product->type,(array)$args['type'],true); })),'max_num_pages'=>3); return (object)array('orders'=>$this->orders,'total'=>12,'max_num_pages'=>2); }
	}
	class Database {
		public $session; public $queries=array(); public $claim=1; public function prefix() { return 'wp_'; }
		public function prepare( $query, ...$args ) { return vsprintf(str_replace(array('%s','%d'), array("'%s'",'%d'),$query),$args); }
		public function getRow( $query ) { return $this->session; }
		public function query( $query ) { $this->queries[]=$query; return strpos($query,'SET last_sent')!==false ? $this->claim : 1; }
	}
}
namespace Fandoogh_Manager {
	const SESSION_IDLE_TTL = 2592000;
	function get_session_context() { return $GLOBALS['session']; }
	function apply_session_user_context( $session ) {}
	function csrf_permission( $request ) { return $GLOBALS['csrf']; }
	function session_has_scope( $scope, $scopes ) { list($group,$name)=explode('.',$scope); return !empty($scopes[$group][$name]); }
	function scopes_for_user( $id ) { return $GLOBALS['current_scopes']; }
	function intersect_pairing_scopes( $issued, $current ) { $result=array(); foreach((array)$issued as $group=>$scopes) { foreach((array)$scopes as $name=>$enabled) { if($enabled && !empty($current[$group][$name])) $result[$group][$name]=true; } } return $result; }
	function security_sessions_table() { return 'wp_sessions'; }
	function security_timestamp_from_mysql( $value ) { return strtotime($value.' UTC'); }
	function compose_security_database() { return $GLOBALS['db']; }
	function compose_product_repository() { return $GLOBALS['repo']; }
	function compose_order_repository() { return $GLOBALS['repo']; }
	function readable_product_statuses( $id ) { return array('publish'); }
	function inventory_parent_product_types() { return array('simple','variable','grouped','external'); }
	function shipping_read_snapshot( $order ) { return $order->shipment; }
	function inventory_error( $code, $message, $status=422 ) { return new \WP_Error($code,$message,array('status'=>$status)); }
	function inventory_response( $payload ) { return $payload; }
	require __DIR__ . '/../app/operations.php';
	require __DIR__ . '/../app/push.php';
}
namespace {
	use function Fandoogh_Manager\operations_normalize_barcode;
	use function Fandoogh_Manager\operations_barcode;
	use function Fandoogh_Manager\operations_today;
	use function Fandoogh_Manager\push_valid_endpoint;
	use function Fandoogh_Manager\push_is_quiet;
	use function Fandoogh_Manager\push_send_row;
	$checks=0;
	function check( $condition, $message ) { global $checks; ++$checks; if(!$condition) throw new RuntimeException($message); }
	$GLOBALS['capability']=true; $GLOBALS['csrf']=true;
	$scopes=array('products'=>array('read'=>true),'orders'=>array('read'=>true),'inventory'=>array('read'=>true));
	$GLOBALS['current_scopes']=$scopes;
	$GLOBALS['session']=array('id'=>9,'user'=>(object)array('ID'=>7),'scopes'=>$scopes);
	$repo=new Repository(); $GLOBALS['repo']=$repo;
	$p1=new Product(1); $p2=new Product(2); $p2->type='variation'; $p2->quantity=9; $repo->products=array(1=>$p1,2=>$p2);
	$o1=new Order(11); $o1->age=3*86400; $o2=new Order(12); $o2->shipment=array('status'=>'shipped','shipped_at'=>'2026-01-01'); $o3=new Order(13); $o3->virtual=true; $repo->orders=array($o1,$o2,$o3);
	check(operations_normalize_barcode('۰۰۱۲۳')==='00123','Preserve Persian leading zeros');
	foreach(array('https://bad.test','<script>',array('00123'),str_repeat('x',101)) as $bad) check(operations_normalize_barcode($bad)==='','Reject non-identifiers');
	check(operations_barcode(new Request(array('code'=>'00123')))['data']['id']===1,'Exact SKU lookup');
	check(operations_barcode(new Request(array('code'=>'VAR-1')))['data']['parent_id']===1,'Variation returns parent without dropping exact match');
	$p1->status='private'; check(operations_barcode(new Request(array('code'=>'VAR-1')))->data['status']===404,'Private parent is not exposed'); $p1->status='publish';
	check(operations_barcode(new Request(array('code'=>'123')))->data['status']===404,'Leading zeros are significant');
	$data=operations_today(new Request(array('inventory_page'=>2)))['data'];
	check($data['groups'][0]['count']===12,'Payment count is global');
	check(count($data['groups'][1]['items'])===1 && $data['groups'][1]['items'][0]['late'],'Exclude shipped/virtual orders and mark late order');
	check($data['groups'][2]['page']===2 && $data['groups'][2]['pages']===3,'Stock scan has explicit continuation');
	check(count($data['groups'][2]['items'])===1,'Uses site-wide low stock threshold');
	$p1->threshold=0; check(!Fandoogh_Manager\operations_low_stock($p1),'Per-product zero threshold overrides site default'); $p1->threshold='';
	$p2->quantity=1; check(count(operations_today(new Request())['data']['groups'][2]['items'])===2,'Independently managed variation stock is included');
	$p2->manage='parent'; check(!Fandoogh_Manager\operations_low_stock($p2),'Inherited variation stock does not duplicate parent warnings'); $p2->manage=true; $p2->quantity=9;
	$GLOBALS['session']['scopes']=array('products'=>array('read'=>true),'inventory'=>array('read'=>true)); $repo->queries=array();
	check(count(operations_today(new Request())['data']['groups'])===1 && count($repo->queries)===2,'Inventory-only sessions only query parents and variations');
	$repo->invalid=true; check(is_wp_error(operations_today(new Request())),'Query failures do not become empty work'); $repo->invalid=false;
	$GLOBALS['csrf']=new WP_Error('csrf','bad'); check(Fandoogh_Manager\push_write_permission(new Request())===$GLOBALS['csrf'],'CSRF blocks subscription writes'); $GLOBALS['csrf']=true;
	foreach(array('https://fcm.googleapis.com/fcm/send/a','https://updates.push.services.mozilla.com/wpush/v2/a','https://web.push.apple.com/a','https://wns.notify.windows.com/a') as $good) check(push_valid_endpoint($good),'Known push host accepted');
	foreach(array('http://fcm.googleapis.com/a','https://localhost/a','https://fcm.googleapis.com.evil.test/a','https://user@fcm.googleapis.com/a','https://fcm.googleapis.com:444/a','https://fcm.googleapis.com/a#secret') as $bad) check(!push_valid_endpoint($bad),'SSRF endpoint rejected');
	$quiet=array('quiet_start'=>'22:00','quiet_end'=>'07:00'); check(push_is_quiet($quiet,'23:00') && push_is_quiet($quiet,'06:59') && !push_is_quiet($quiet,'07:00'),'Overnight quiet hours boundary');
	check(!push_is_quiet(array('quiet_start'=>'08:00','quiet_end'=>'08:00'),'08:00'),'Equal quiet hours mean disabled');
	$db=new Database(); $GLOBALS['db']=$db;
	$db->session=(object)array('status'=>'active','expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'last_seen_at'=>gmdate('Y-m-d H:i:s'),'scopes'=>json_encode($scopes));
	$row=(object)array('id'=>3,'session_id'=>9,'user_id'=>7,'pending'=>0,'preferences'=>json_encode($quiet),'subscription'=>'{}');
	check(push_send_row($row,'new_order')==='quiet','Quiet hours do not send network requests');
	check(strpos(end($db->queries),'pending = pending | 1')!==false,'Quiet events coalesce atomically');
	$row->preferences='{}'; $GLOBALS['current_scopes']['orders']['read']=false; check(push_send_row($row,'new_order')==='disabled','Revoked order scope stops notifications');
	$GLOBALS['current_scopes']=$scopes; $db->claim=0; check(push_send_row($row,'new_order')==='rate-limit','Duplicate delivery guard is atomic');
	$db->session->status='revoked'; check(push_send_row($row,'new_order')==='session-expired','Revoked session stops all delivery');
	check(strpos(end($db->queries),'DELETE FROM wp_fandoogh_manager_push')===0,'Dead subscriptions are removed');
	echo "Operations: {$checks} contract checks passed.\n";
}
