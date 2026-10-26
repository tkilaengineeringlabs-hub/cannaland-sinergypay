<?php
/** Offline executable regression suite. No HTTP, real bank transactions, or stored private keys. */
error_reporting( E_ALL );
set_error_handler( static function ( $level, $message, $file, $line ) { throw new ErrorException( $message, 0, $level, $file, $line ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'CANNALAND_SINERGYPAY_FILE', dirname( __DIR__ ) . '/cannaland-sinergypay.php' );
define( 'CANNALAND_SINERGYPAY_VERSION', '0.1.0' );
class WP_Error {
    private string $code; private string $message;
    public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response { public $data; public $status; public function __construct( $data, $status ) { $this->data=$data; $this->status=$status; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, ...$args ) { return $value; }
function esc_html__( $value, ...$args ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, ...$args ) {
    if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; }
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function wp_generate_uuid4() { return '12345678-1234-4234-8234-' . str_pad( (string) ++$GLOBALS['uuid'], 12, '0', STR_PAD_LEFT ); }
function wc_get_order( $id ) { return $GLOBALS['orders'][$id] ?? false; }
function add_filter( $name, $callable, ...$args ) { $GLOBALS['filters'][$name][]=$callable; }
function remove_filter( $name, $callable, ...$args ) { $GLOBALS['filters'][$name]=array_filter( $GLOBALS['filters'][$name] ?? [], fn($f)=>$f!==$callable ); }
function add_action( $name, $callable, ...$args ) { $GLOBALS['actions'][$name][]=$callable; }
function register_rest_route( $namespace, $path, $args ) { $GLOBALS['route']=[$namespace,$path,$args]; }
function wc_get_orders( $args ) {
    ++$GLOBALS['lookups'];
    $GLOBALS['last_query']=$args;
    if ( isset($args['meta_query']) ) { $meta=$args['meta_query'][0]; }
    else {
        $query=[];
        foreach($GLOBALS['filters']['woocommerce_order_data_store_cpt_get_orders_query'] ?? [] as $f) { $query=$f($query,$args); }
        $meta=$query['meta_query'][0];
        $GLOBALS['legacy_query']=$query;
    }
    $ids=[];
    foreach($GLOBALS['orders'] as $id=>$order) {
        if($order->get_payment_method()===$args['payment_method'] && $order->get_meta($meta['key'])===$meta['value']) { $ids[]=$id; }
    }
    return array_slice($ids,0,$args['limit']);
}
function wp_safe_remote_request( $url, $args ) {
    $GLOBALS['requests'][]=[$url,$args];
    if(isset($GLOBALS['during_http'])) { $f=$GLOBALS['during_http']; unset($GLOBALS['during_http']); $f(); }
    if(!$GLOBALS['responses']) { throw new Exception('Unexpected HTTP request'); }
    $response=array_shift($GLOBALS['responses']);
    if($response instanceof Throwable) { throw $response; }
    return $response;
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class TestLogger { public $events=[]; function info(...$args) { $this->events[]=$args; } }
function wc_get_logger() { return $GLOBALS['logger']; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function rest_url( $path ) { return 'https://shop.example/wp-json/'.$path; }
function wc_add_notice( ...$args ) { $GLOBALS['notices'][]=$args; }
function WC() { return $GLOBALS['wc']; }
class WC_Payment_Gateway {
    public $id,$has_fields,$supports,$method_title,$method_description,$form_fields,$settings,$enabled,$title,$description;
    public function init_settings() { $this->settings=get_option('woocommerce_'.$this->id.'_settings',[]); }
    public function get_option($key,$default='') { return $this->settings[$key] ?? $default; }
    public function is_available() { return $this->enabled==='yes'; }
    public function get_return_url($order) { return 'https://shop.example/received/'.$order->get_id(); }
}
class Order {
    public int $id; public array $meta=[],$notes=[]; public string $status='pending',$currency='MXN',$total='10.00',$transaction='',$method='cannaland_sinergypay';
    public int $completions=0; public bool $throw_payment=false,$save_before_paid=false;
    public function __construct($id) { $this->id=$id; }
    public function get_id() { return $this->id; }
    public function get_meta($key,...$args) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key,$value) { $this->meta[$key]=$value; }
    public function get_payment_method() { return $this->method; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function get_status() { return $this->status; }
    public function is_paid() { return in_array($this->status,['processing','completed'],true); }
    public function get_transaction_id() { return $this->transaction; }
    public function save() { return $this->id; }
    public function add_order_note($note) { $this->notes[]=$note; }
    public function update_status($status,$note='') { $this->status=$status; if($note) $this->notes[]=$note; }
    public function payment_complete($ticket) {
        ++$this->completions;
        if($this->throw_payment) {
            if($this->save_before_paid) { $this->transaction=$ticket; $this->status='processing'; }
            throw new Exception('Simulated interrupted completion');
        }
        $this->transaction=$ticket; $this->status='processing';
    }
}
foreach(['support','api','preparation','notification','webhook','gateway'] as $class) { require dirname(__DIR__).'/includes/class-'.$class.'.php'; }
use Cannaland\SinergyPay\Support;
use Cannaland\SinergyPay\Api;
use Cannaland\SinergyPay\Preparation;
use Cannaland\SinergyPay\Notification;
use Cannaland\SinergyPay\NotificationJson;
use Cannaland\SinergyPay\JsonNumber;
use Cannaland\SinergyPay\Webhook;
use Cannaland\SinergyPay\Gateway;
$count=0;
function check($name,$condition) { global $count; if(!$condition) throw new Exception('FAIL: '.$name); ++$count; echo "PASS: $name\n"; }
function reset_state() {
    $GLOBALS['options']=[Support::OPTION=>['enabled'=>'yes','environment'=>'sandbox','api_key'=>'synthetic-test-only','expiration_minutes'=>'8640']];
    $GLOBALS['orders']=[]; $GLOBALS['responses']=[]; $GLOBALS['requests']=[]; $GLOBALS['lookups']=0; $GLOBALS['uuid']=0;
    $GLOBALS['filters']=[]; $GLOBALS['actions']=[]; $GLOBALS['logger']=new TestLogger; $GLOBALS['currency']='MXN';
    $GLOBALS['wc']=(object)['cart'=>new class { function empty_cart() { $GLOBALS['emptied']=true; } }];
    unset($GLOBALS['during_http']);
}
function order($id=1) { return $GLOBALS['orders'][$id]=new Order($id); }
function envelope($data,$status=200) { return ['status'=>$status,'body'=>json_encode(['rc'=>0,'msg'=>'Ok','data'=>$data])]; }
function queue_success($id=1) {
    $GLOBALS['responses'][]=envelope(['id'=>'remote-'.$id,'amount'=>'10.00','currency'=>'MXN','description'=>'Cannaland order #'.$id,'expiration_date'=>'2030-01-01T12:00:00-06:00','reference_num'=>'123456'],201);
    $GLOBALS['responses'][]=envelope(['clabe'=>'012345678901234567']);
}
function ready($id=1) { $o=order($id); queue_success($id); $r=(new Preparation)->prepare($id); if(is_wp_error($r)) throw new Exception('Fixture preparation failed'); return $o; }
// Ephemeral synthetic RSA private keys stay only in memory. They are never shipped or treated as provider fixtures.
$keydir=sys_get_temp_dir().'/csp-keys-'.bin2hex(random_bytes(8)); mkdir($keydir,0700);
$private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$other=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
file_put_contents($keydir.'/sinergy_public_sb.pem',openssl_pkey_get_details($private)['key']);
file_put_contents($keydir.'/sinergy_public_prod.pem',openssl_pkey_get_details($other)['key']);
register_shutdown_function(function()use($keydir){ foreach(glob($keydir.'/*') as $p) unlink($p); rmdir($keydir); });
$notification=new Notification($keydir); $webhook=new Webhook($notification);
function event($o,$changes=[]) {
    return array_replace(['id'=>'ticket-'.$o->id,'currency'=>'MXN','amount'=>'10.00','description'=>Support::meta($o,'description'),
        'reference'=>Support::meta($o,'reference'),'date'=>'2026-10-25T12:30:00-06:00','code'=>Support::meta($o,'remote_order_id'),'payments'=>[['id'=>'nested-payment-'.$o->id,'amount'=>'10.00','currency'=>'MXN','Product'=>['id'=>22,'name'=>'SPEI']]]],$changes);
}
function signed($data,$numeric=false,$signer=null) {
    global $private;
    $chain=[];
    foreach(['id','currency','amount','description','reference','date'] as $key) $chain[]=$data[$key] ?? '';
    openssl_sign(implode('|',$chain),$sig,$signer ?? $private,OPENSSL_ALGO_SHA512);
    $data['security']=['key'=>'ignored-not-a-path','version'=>1,'signature'=>base64_encode($sig)];
    $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($numeric) $raw=str_replace('"amount":'.json_encode($data['amount']),'"amount":'.$data['amount'],$raw);
    return $raw;
}
reset_state(); $o=order(); queue_success();
check('order and CLABE creation succeeds',(new Preparation)->prepare(1)===$o);
check('exactly two POSTs',count($GLOBALS['requests'])===2 && $GLOBALS['requests'][0][1]['method']==='POST');
check('SinergyPay sandbox order endpoint',$GLOBALS['requests'][0][0]==='https://sandbox.sinergypay.mx/v2/orders/');
check('CLABE endpoint',$GLOBALS['requests'][1][0]==='https://sandbox.sinergypay.mx/v2/orders/remote-1/clabe');
check('Basic uses API key as username',$GLOBALS['requests'][0][1]['headers']['Authorization']==='Basic '.base64_encode('synthetic-test-only:'));
check('User-Agent supplied',str_starts_with($GLOBALS['requests'][0][1]['headers']['User-Agent'],'CannalandSinergyPay/'));
check('amount sent as exact decimal JSON',str_contains($GLOBALS['requests'][0][1]['body'],'"amount":10.00'));
check('CLABE POST has empty JSON object',$GLOBALS['requests'][1][1]['body']==='{}');
check('timeout and no redirects',$GLOBALS['requests'][0][1]['timeout']===20 && $GLOBALS['requests'][0][1]['redirection']===0);
check('CLABE leading zero preserved',Support::meta($o,'clabe')==='012345678901234567');
check('expiration saved',Support::meta($o,'expiration_date')==='2030-01-01T12:00:00-06:00');
check('checkout holds order without payment',$o->status==='on-hold' && $o->completions===0);
check('success states persisted',Support::meta($o,'order_state')==='succeeded' && Support::meta($o,'clabe_state')==='succeeded');
$reference=Support::meta($o,'reference');
check('ready preparation reuses order and CLABE',(new Preparation)->prepare(1)===$o && count($GLOBALS['requests'])===2 && Support::meta($o,'reference')===$reference);
foreach(['01234567890123456','0123456789012345678',123456789012345678,'01234567890123456x'] as $bad) {
    reset_state(); $o=order(); queue_success(); $GLOBALS['responses'][1]=envelope(['clabe'=>$bad]);
    check('invalid CLABE rejected '.gettype($bad).':'.(string)$bad,is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'clabe')==='');
    $n=count($GLOBALS['requests']); check('invalid CLABE not regenerated',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===$n);
}
reset_state(); $o=order(); $GLOBALS['responses'][]=['status'=>401,'body'=>'{"rc":12,"msg":"Rejected"}'];
check('explicit 4xx rejection',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'order_state')==='rejected');
check('rejection has safe administrative note',count($o->notes)===1 && !str_contains($o->notes[0],'synthetic-test-only'));
check('rejected operation not automatically retried',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===1);
foreach([['status'=>503,'body'=>'{"rc":12,"msg":"Error"}'],['status'=>400,'body'=>'not json'],['status'=>200,'body'=>'{'],['status'=>204,'body'=>''],new WP_Error('timeout','secret body')] as $response) {
    reset_state(); $o=order(); $GLOBALS['responses'][]=$response;
    check('uncertain response held for review',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'order_state')==='ambiguous');
    check('uncertain response blocks second POST',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===1 && count($o->notes)===1);
}
reset_state(); $o=order(); queue_success(); $GLOBALS['responses'][1]=new WP_Error('timeout');
check('CLABE timeout ambiguous',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'clabe_state')==='ambiguous');
check('remote order retained after CLABE timeout',Support::meta($o,'remote_order_id')==='remote-1');
check('neither POST retried after CLABE timeout',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===2);
reset_state(); $o=order(); $GLOBALS['options'][Support::OPTION]['api_key']='';
check('local config error sends nothing',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===0 && Support::meta($o,'order_state')==='local_error');
$GLOBALS['options'][Support::OPTION]['api_key']='synthetic-test-only'; queue_success();
check('local error can be corrected safely',(new Preparation)->prepare(1)===$o);
reset_state(); $o=order(); queue_success(); $GLOBALS['during_http']=function(){ check('reentrant concurrent checkout blocked',is_wp_error((new Preparation)->prepare(1))); };
check('concurrent attempt still only creates once',(new Preparation)->prepare(1)===$o && count($GLOBALS['requests'])===2);
reset_state(); $o=order(); Support::put($o,'order_state','sending');
check('interrupted sending state becomes ambiguous',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===0 && Support::meta($o,'order_state')==='ambiguous');
reset_state(); $o=order(); $o->currency='USD'; check('non MXN checkout rejected',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===0);
reset_state(); $o=ready(); $raw=signed(event($o));
check('synthetic valid signature',!is_wp_error($notification->verify($raw,'sandbox')));
check('production key cannot accept sandbox signature',is_wp_error($notification->verify($raw,'production')));
$null=event($o,['description'=>null,'reference'=>null]);
check('null fields form empty segments',!is_wp_error($notification->verify(signed($null),'sandbox')));
check('UTF-8 signed without alteration',!is_wp_error($notification->verify(signed(event($o,['description'=>'Cannaland: café y piñón 🌿'])),'sandbox')));
$numeric=signed(event($o,['amount'=>'10.00']),true);
$verified=$notification->verify($numeric,'sandbox');
check('numeric JSON amount lexeme preserved',!is_wp_error($verified) && $verified['signed']['amount']==='10.00');
check('text amount spelling preserved',!is_wp_error($notification->verify(signed(event($o,['amount'=>'10.0'])),'sandbox')));
check('changed decimal spelling fails signature',is_wp_error($notification->verify(str_replace('"amount":10.00','"amount":10.0',$numeric),'sandbox')));
check('changed signed field fails signature',is_wp_error($notification->verify(str_replace('Cannaland order #1','Cannaland order #9',$raw),'sandbox')));
$bad=json_decode($raw,true); $bad['security']['signature']=base64_encode(str_repeat('x',256));
check('invalid signature rejected',$webhook->process(json_encode($bad))===401 && $GLOBALS['lookups']===0 && $o->completions===0);
$bad=json_decode($raw,true); unset($bad['security']['signature']); check('missing signature rejected',$webhook->process(json_encode($bad))===401);
$bad=json_decode($raw,true); $bad['security']['version']=2; check('unsupported version rejected',$webhook->process(json_encode($bad))===401);
$bad=json_decode($raw,true); $bad['security']['key']='../../other.pem'; check('security.key never selects a file',$webhook->process(json_encode($bad))===200);
check('payment complete once with signed ticket',$o->completions===1 && $o->transaction==='ticket-1' && Support::meta($o,'confirmation_state')==='paid');
$n=count($o->notes); check('duplicate valid notification ACK 200',$webhook->process($raw)===200 && $o->completions===1 && count($o->notes)===$n);
check('no polling or notification HTTP calls',count($GLOBALS['requests'])===2);
foreach(['{','null','[]','{"amount":1,"amount":2}','{"x":1,"x":2}',str_repeat('x',65537),'{"x":01}','{"x":"bad'.chr(1).'"}'] as $bad) {
    check('invalid or ambiguous JSON rejected',$webhook->process($bad)===400);
}
reset_state(); $o=ready(); $base=event($o);
foreach([['reference'=>'csp-99-12345678-1234-4234-8234-000000000099'],['amount'=>'11.00'],['currency'=>'USD'],['code'=>'other'],['description'=>'Other'],['id'=>'bad|ticket']] as $change) {
    check('inconsistent event rejected '.key($change),$webhook->process(signed(array_replace($base,$change)))!==200 && $o->completions===0);
}
check('notification without payment shape rejected',$webhook->process(signed(array_replace($base,['payments'=>[]])))===400 && $o->completions===0);
$raw=signed($base); $otherOrder=order(2); $otherOrder->meta=$o->meta;
check('ambiguous reference lookup rejected',$webhook->process($raw)===409); unset($GLOBALS['orders'][2]);
$o->status='cancelled'; check('cancelled order not paid',$webhook->process($raw)===409 && $o->completions===0);
$o->status='processing'; $o->transaction='manual'; check('already paid with another transaction rejected',$webhook->process($raw)===409 && $o->completions===0);
$o->status='on-hold'; $o->transaction=''; $o->throw_payment=true;
check('interrupted completion returns 500',$webhook->process($raw)===500 && $o->completions===1);
$o->throw_payment=false; check('interrupted completion never runs twice',$webhook->process($raw)===500 && $o->completions===1);
reset_state(); $o=ready(); $o->throw_payment=true; $o->save_before_paid=true; $raw=signed(event($o));
check('crash after paid write returns failure',$webhook->process($raw)===500);
check('retry after saved payment ACKs without effects',$webhook->process($raw)===200 && $o->completions===1);
reset_state(); $o=ready(); $raw=signed(event($o)); Support::lock(1);
check('webhook while order locked safely retries',$webhook->process($raw)===500 && $o->completions===0); Support::unlock(1);
check('webhook succeeds once lock released',$webhook->process($raw)===200 && $o->completions===1);
check('legacy lookup uses Woo data store filter',isset($GLOBALS['legacy_query']['meta_query'][0]) && empty($GLOBALS['filters']['woocommerce_order_data_store_cpt_get_orders_query']));
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static $enabled=true; static function custom_orders_table_usage_is_enabled(){return self::$enabled;} }');
check('HPOS reference lookup uses meta_query',Support::find_reference(Support::meta($o,'reference'))===$o && isset($GLOBALS['last_query']['meta_query']) && !isset($GLOBALS['last_query']['csp_reference_lookup']));
reset_state(); $o=ready(); $raw=signed(event($o));
check('HPOS notification processed',$webhook->process($raw)===200 && $o->completions===1);
$webhook->register(); check('REST route stable and POST only',$GLOBALS['route'][0]==='cannaland-sinergypay/v1' && $GLOBALS['route'][1]==='/payment-notification' && $GLOBALS['route'][2]['methods']==='POST');
$request=new class($raw) { private $raw; function __construct($raw){$this->raw=$raw;} function get_body(){return $this->raw;} };
check('REST ACK body minimal',$webhook->receive($request)->data===null && $webhook->receive($request)->status===200);
Support::log('api_response',200); check('logging off suppresses logs',count($GLOBALS['logger']->events)===0);
$GLOBALS['options'][Support::OPTION]['logging']='yes'; Support::log('api_response',201); Support::log('Authorization: secret');
check('logging only safe fixed events',count($GLOBALS['logger']->events)===1 && $GLOBALS['logger']->events[0][0]==='api_response HTTP 201');
$g=new Gateway; check('gateway ID and availability',$g->id==='cannaland_sinergypay' && $g->is_available());
$GLOBALS['currency']='USD'; check('gateway not offered for USD',!$g->is_available()); $GLOBALS['currency']='MXN';
reset_state(); $o=order(); queue_success(); $g=new Gateway; $result=$g->process_payment(1);
check('gateway checkout returns success without paying',$result['result']==='success' && $o->status==='on-hold' && $o->completions===0);
ob_start(); $g->instructions(1); $html=ob_get_clean();
check('instructions show CLABE exact amount reference expiry',str_contains($html,'012345678901234567') && str_contains($html,'10.00 MXN') && str_contains($html,Support::meta($o,'reference')) && str_contains($html,'2030-01-01'));
Support::put($o,'reference_num','<script>alert(1)</script>'); ob_start(); $g->instructions(1); $html=ob_get_clean();
check('instructions escape provider content',!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'));
check('decimal comparisons exact',Support::money('10.0')===Support::money('10.00') && Support::money('10.001')===null && Support::money('1e1')===null);
reset_state(); $o=ready(); $GLOBALS['options'][Support::OPTION]['environment']='production';
check('environment change never recreates operation',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===2);
reset_state(); $o=order(); queue_success(); $GLOBALS['responses'][0]['body']='{"rc":0,"msg":"Ok","data":{"id":"remote-1"}}';
check('incomplete creation keeps remote ID and blocks recreation',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'remote_order_id')==='remote-1' && Support::meta($o,'order_state')==='ambiguous');
check('incomplete creation does not request CLABE',is_wp_error((new Preparation)->prepare(1)) && count($GLOBALS['requests'])===1);
reset_state(); $o=order(); Support::put($o,'remote_order_id','remote-1'); Support::put($o,'order_state','sending');
check('crash after remote ID save remains ambiguous',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'order_state')==='ambiguous' && count($o->notes)===1);
reset_state(); $o=order(); $GLOBALS['responses'][]=new Exception('synthetic exception');
check('unexpected transport exception held for review',is_wp_error((new Preparation)->prepare(1)) && Support::meta($o,'order_state')==='ambiguous' && count($o->notes)===1);
reset_state(); $o=ready(); $raw=signed(event($o)); $data=json_decode($raw,true); $data['payments'][0]['id']='unsigned-other';
check('unsigned nested payment ID is never transaction ID',$webhook->process(json_encode($data))===200 && $o->get_transaction_id()==='ticket-1');
reset_state(); $o=ready(1); $o2=ready(2);
check('first ticket globally claimed',$webhook->process(signed(event($o,['id'=>'same-ticket'])))===200);
check('same ticket cannot pay another order',$webhook->process(signed(event($o2,['id'=>'same-ticket'])))===409 && $o2->completions===0);
reset_state(); $o=ready(); $o->total='20.00';
check('edited WooCommerce total prevents completion',$webhook->process(signed(event($o)))===409 && $o->completions===0);
reset_state(); $o=order(); $GLOBALS['options'][Support::OPTION]['environment']='production'; queue_success();
check('production order creation',(new Preparation)->prepare(1)===$o && $GLOBALS['requests'][0][0]==='https://api.sinergypay.mx/v2/orders/');
check('production environment selects production key',$webhook->process(signed(event($o),false,$other))===200 && $o->completions===1);
reset_state(); $o=ready();
check('escaped quotes and backslashes in signed UTF-8',!is_wp_error($notification->verify(signed(event($o,['description'=>'"café" \\ prueba'])),'sandbox')));
$bad=signed(event($o)); $bad=str_replace('"amount":"10.00"','"amount":"10.00","amou\\u006et":"10.00"',$bad);
check('escaped duplicate signed key rejected',$webhook->process($bad)===400 && $o->completions===0);
check('fractional cents rejected after valid signature',$webhook->process(signed(event($o,['amount'=>'10.001'])))===400 && $o->completions===0);
check('null amount never completes',$webhook->process(signed(event($o,['amount'=>null])))===400 && $o->completions===0);
$g=new Gateway;
try { $g->validate_expiration_minutes_field('expiration_minutes','0'); check('invalid expiry rejected',false); }
catch(Exception $e) { check('invalid expiry rejected',true); }
check('valid expiry accepted',$g->validate_expiration_minutes_field('expiration_minutes','8640')==='8640');
foreach(['sb','prod'] as $env) {
    $key=file_get_contents(dirname(__DIR__).'/resources/keys/sinergy_public_'.$env.'.pem');
    check('bundled official RSA key loads '.$env,openssl_pkey_get_details(openssl_pkey_get_public($key))['type']===OPENSSL_KEYTYPE_RSA);
}
echo "$count assertions passed. Synthetic fixtures and WooCommerce doubles; no live financial API.\n";
