<?php
// Offline regression harness: doubles only, no WordPress/database/API requests.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', __DIR__);
class WP_Error { public $code; function __construct($code, ...$args) { $this->code=$code; } }
class DappWoo { const GATEWAY_ID='wc_dappwoo', META_ORDER_PAYMENT_ID='_DAPP_PAYMENT_ID', META_ORDER_PAYMENT_ID_INTERNAL='_DAPP_INTERNAL_DEPOSIT_ID', PLUGIN_NAME='dappwoo'; }
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['options']=['woocommerce_wc_dappwoo_settings'=>['wc_dappwoo_api_token'=>'offline-test', 'wc_dappwoo_is_production'=>'production']];
function get_option($k) { return $GLOBALS['options'][$k] ?? false; }
function add_option($k,$v,...$args) { if(array_key_exists($k,$GLOBALS['options'])) return false; $GLOBALS['options'][$k]=$v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); }
function __($x,...$args) { return $x; }
function wp_unslash($x) { return $x; }
function sanitize_text_field($x) { return trim(strip_tags($x)); }
function wp_verify_nonce($x,$action) { return $x==='valid'; }
function get_current_user_id() { return $GLOBALS['user'] ?? 0; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function wp_json_encode($x,...$args) { return json_encode($x); }
function wp_parse_url($x) { return parse_url($x); }
function get_bloginfo($x) { return 'offline'; }
function wp_generate_uuid4() { return 'offline-reference'; }
function wp_remote_retrieve_body($x) { return $x['body']; }
function wp_remote_retrieve_response_code($x) { return $x['response']['code']; }
function wp_safe_remote_request($url,$args) {
 $GLOBALS['requests'][]=[$url,$args];
 if(isset($GLOBALS['during_http'])) { $f=$GLOBALS['during_http']; unset($GLOBALS['during_http']); $f(); }
 return $GLOBALS['response'];
}
function add_filter($tag,$cb,...$args) { $GLOBALS['filters'][$tag]=$cb; }
function remove_filter($tag,$cb,...$args) { unset($GLOBALS['filters'][$tag]); }
function wc_get_orders($args) {
 $GLOBALS['query']=$args;
 if(isset($GLOBALS['filters']['woocommerce_order_data_store_cpt_get_orders_query'])) {
  $q=($GLOBALS['filters']['woocommerce_order_data_store_cpt_get_orders_query'])([], $args);
  $GLOBALS['legacy_query']=$q;
 }
 return $GLOBALS['matches'] ?? [1];
}
class Order {
 public $meta=[], $paid=false, $customer=7, $method='wc_dappwoo';
 function get_meta($k) { return $this->meta[$k] ?? ''; }
 function update_meta_data($k,$v) { $this->meta[$k]=$v; }
 function delete_meta_data($k) { unset($this->meta[$k]); }
 function save() {}
 function get_payment_method() { return $this->method; }
 function get_customer_id() { return $this->customer; }
 function get_order_key() { return 'order-secret'; }
 function needs_payment() { return !$this->paid; }
 function is_paid() { return $this->paid; }
 function get_total() { return '10.00'; }
 function get_checkout_order_received_url() { return 'https://example.org/received'; }
 function payment_complete() { throw new Exception('Unverified payment must not complete'); }
}
$root=dirname(__DIR__,2);
foreach(['includes/helper/class-assets-trait.php','includes/helper/class-debug-trait.php','includes/helper/class-logger-trait.php','includes/helper/class-validations-trait.php','includes/helper/class-settings-trait.php','includes/helper/class-handle-payment-trait.php','includes/helper/class-database-trait.php','includes/helper/class-country-currency-trait.php','includes/helper/class-helper.php','includes/api/class-api-connector.php','includes/api/class-api-interface.php','src/api/class-dapp-woo-api.php','src/sdk/class-dapp-woo-sdk.php','src/gateway/class-request-deposit-action.php','src/orders/class-webhooks.php'] as $p) require $root.'/'.$p;
use Conexa\DappWoo\Gateway\RequestDepositAction as Action;
use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Orders\Webhooks;
use Conexa\DappWoo\Api\DappWooApi;
class OfflineLogger { public $events=[]; function log(...$args) { $this->events[]=$args; } }
function wc_get_logger() { return $GLOBALS['logger'] ?? ($GLOBALS['logger']=new OfflineLogger); }
$count=0;
function check($name,$ok) { global $count; if(!$ok) throw new Exception('FAIL: '.$name); ++$count; echo "PASS: $name\n"; }
function response($body,$code=200) { $GLOBALS['response']=['body'=>$body,'response'=>['code'=>$code]]; }
$o=new Order; $GLOBALS['orders'][1]=$o; $GLOBALS['user']=7;
$_POST=['nonce'=>'invalid','order_id'=>'1']; check('invalid nonce rejected',Action::validate_ajax_request()!==true);
$_POST=['nonce'=>['valid'],'order_id'=>'1']; check('array nonce rejected',Action::validate_ajax_request()!==true);
$_POST=['nonce'=>'valid','order_id'=>'99']; check('missing order rejected',Action::validate_ajax_request()!==true);
$_POST=['nonce'=>'valid','order_id'=>'-1']; check('negative id rejected',Action::validate_ajax_request()!==true);
$_POST=['nonce'=>'valid','order_id'=>'1']; check('owner accepted',Action::validate_ajax_request()===true);
$GLOBALS['user']=8; check('other customer rejected',Action::validate_ajax_request()!==true);
$o->customer=0; $_POST['order_key']='wrong'; check('guest wrong key rejected',Action::validate_ajax_request()!==true);
$_POST['order_key']='order-secret'; check('guest correct key accepted',Action::validate_ajax_request()===true);
$o->customer=7; $GLOBALS['user']=7;
$api=new DappWooApi(['api_token'=>'offline','debug'=>false]);
$GLOBALS['response']=new WP_Error('network'); check('network error normalized',is_wp_error($api->get('/offline')));
response('{'); check('invalid JSON rejected',is_wp_error($api->get('/offline')));
response('null'); check('null JSON rejected',is_wp_error($api->get('/offline')));
response('{}',503); check('HTTP 503 rejected',is_wp_error($api->get('/offline')));
response('{"data":{}}',201); check('201 accepted',$api->post('/offline')===['data'=>[]]);
response('',204); check('204 accepted empty',$api->get('/offline')===[]);
check('explicit timeout and no redirects',end($GLOBALS['requests'])[1]['timeout']===20 && end($GLOBALS['requests'])[1]['redirection']===0);
check('invalid webhook JSON rejected',Webhooks::process_webhook('{')===false);
check('null webhook rejected',Webhooks::process_webhook('null')===false);
check('array code rejected',Webhooks::process_webhook('{"code":[]}')===false);
Helper::acquire_order_lock(1); $before=count($GLOBALS['requests']); check('held lock blocks duplicate',Action::run(1)===''); check('held lock prevents HTTP',count($GLOBALS['requests'])===$before); Helper::release_order_lock(1);
response('{"msg":"Ok","data":{"id":"code1","qr_str":"https://dapp.mx/c/code1"}}',201);
$GLOBALS['during_http']=function(){ check('concurrent creation blocked',Action::run(1)===''); };
check('first code created',Action::run(1)==='https://dapp.mx/c/code1');
check('code persisted',$o->get_meta(DappWoo::META_ORDER_PAYMENT_ID)==='code1');
check('existing code reused',Action::run(1)==='https://dapp.mx/c/code1' && end($GLOBALS['requests'])[1]['method']==='GET');
response('{"msg":"Ok","data":{"id":"other","qr_str":"https://dapp.mx/c/other"}}'); check('mismatched code rejected',Action::run(1)==='');
response('{"data":{"id":"payment1"}}'); check('data presence cannot approve',Helper::handle_payment(1)===false);
check('test flag cannot bypass validation',Helper::handle_payment(1,'CHECKOUT_TEST')===false);
$o->paid=true; check('paid order cannot create code',Action::run(1)===''); check('unrecorded paid order not acknowledged',Helper::handle_payment(1)===false);
$o->meta[DappWoo::META_ORDER_PAYMENT_ID_INTERNAL]='payment1';
$before=count($GLOBALS['requests']); check('repeated webhook acknowledged',Webhooks::process_webhook('{"code":"code1"}')===true && Webhooks::process_webhook('{"code":"code1"}')===true); check('repeat has no HTTP effects',count($GLOBALS['requests'])===$before);
check('legacy query uses Woo adapter',isset($GLOBALS['legacy_query']['meta_query'][0]) && !isset($GLOBALS['filters']['woocommerce_order_data_store_cpt_get_orders_query']));
$GLOBALS['matches']=[1,2]; check('ambiguous order rejected',Helper::find_order_by_itemmeta_value('_DAPP_PAYMENT_ID','code1')===false); unset($GLOBALS['matches']);
$o2=new Order; $GLOBALS['orders'][2]=$o2; $GLOBALS['response']=new WP_Error('timeout'); check('ambiguous creation fails safely',Action::run(2)==='');
$before=count($GLOBALS['requests']); check('ambiguous creation never retries POST',Action::run(2)==='' && count($GLOBALS['requests'])===$before);
$o->paid=false; response('{"msg":"Ok","data":{"id":"code1","qr_str":"javascript:alert(1)"}}'); check('unsafe redirect rejected',Action::run(1)==='');
check('missing run order handled',Action::run(999)==='');
$logger=wc_get_logger();
Helper::log('Webhook received'); check('logging disabled suppresses events',count($logger->events)===0);
$GLOBALS['options']['woocommerce_wc_dappwoo_settings']['wc_dappwoo_log_enabled']='yes';
Helper::log_error('Authorization secret'); Helper::log(['api_key'=>'secret']); check('sensitive input discarded with logging enabled',count($logger->events)===0);
Helper::log('Webhook received'); check('enabled logging records safe events',count($logger->events)===1 && $logger->events[0][1]==='Webhook received');
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { static function custom_orders_table_usage_is_enabled() { return true; } }');
check('HPOS lookup uses meta query',Helper::find_order_by_itemmeta_value('_DAPP_PAYMENT_ID','code1')===1 && isset($GLOBALS['query']['meta_query'][0]) && !isset($GLOBALS['query']['dapp_lookup']));
echo "$count assertions passed (offline doubles; no live API).\n";
