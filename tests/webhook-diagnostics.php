<?php
// Controlled fixtures only. Nothing here uses a real provider key, order or network.
use Cannaland\Payments\Support;
use Cannaland\Payments\Diagnostics;
use Cannaland\Payments\Webhook;
function webhook_request($body) { return new class($body) { private $body; function __construct($body){$this->body=$body;} function get_body(){return $this->body;} }; }
function webhook_trace($name) { return diagnostic_context($name); }
function webhook_setup() { reset_state(); $o=ready(); $GLOBALS['options'][Support::OPTION]['logging']='yes'; $GLOBALS['logger']->events=[]; return $o; }
$o=webhook_setup(); $webhook->register();
check('webhook permission callback remains anonymous public POST',$GLOBALS['route'][2]['permission_callback']==='__return_true' && $GLOBALS['route'][2]['methods']==='POST' && $GLOBALS['route'][2]['callback'][1]==='receive');
$response=call_user_func($GLOBALS['route'][2]['callback'],webhook_request('{"probe":"cannaland-payments-reachability"}'));
check('unsigned probe reaches registered handler returns 401 null',$response->status===401 && $response->data===null);
check('received event precedes every validation',array_column($GLOBALS['logger']->events,0)===['webhook_received','invalid_signature']);
check('probe cannot look up orders mutate metadata or complete payment',$GLOBALS['lookups']===0 && $o->completions===0 && $o->status==='on-hold' && Support::meta($o,'transaction_id')==='');
check('safe probe logs source route stage no trusted order',webhook_trace('webhook_received')['source']==='cannaland-payments' && webhook_trace('webhook_received')['stage']==='webhook_entry' && webhook_trace('webhook_received')['order_id']===0 && webhook_trace('invalid_signature')['reason']==='security_missing' && webhook_trace('invalid_signature')['signature_verified']===false);
$o=webhook_setup();$response=$webhook->receive(webhook_request('{'));
check('invalid JSON traced and rejected 400 before lookup',$response->status===400 && webhook_trace('invalid_json')['http_status']===400 && $GLOBALS['lookups']===0);
$o=webhook_setup(); $body=signed(event($o));$bad=json_decode($body,true);$bad['security']['signature']=base64_encode(str_repeat('x',256));
$response=$webhook->receive(webhook_request(json_encode($bad)));
check('invalid RSA signature traced without lookup',$response->status===401 && webhook_trace('invalid_signature')['reason']==='invalid_signature' && !$GLOBALS['lookups']);
$o=webhook_setup();$bad=json_decode(signed(event($o)),true);unset($bad['reference']);
$response=$webhook->receive(webhook_request(json_encode($bad)));
check('missing reference diagnostic never trusts unverified body',$response->status===400 && webhook_trace('reference_missing')['signature_verified']===false && !$GLOBALS['lookups']);
$o=webhook_setup(); $response=$webhook->receive(webhook_request(signed(event($o,['reference'=>null]))));
check('validly signed null reference still rejected without lookup',$response->status===400 && webhook_trace('reference_missing')['signature_verified']===true && !$GLOBALS['lookups']);
$o=webhook_setup();$response=$webhook->receive(webhook_request(signed(event($o,['reference'=>'csp-999-12345678-1234-4234-8234-123456789abc']))));
check('unmatched authenticated reference traced 409',$response->status===409 && webhook_trace('order_not_found')['stage']==='webhook_lookup' && $o->completions===0);
$o=webhook_setup();$response=$webhook->receive(webhook_request(signed(event($o,['amount'=>'780.00']))));
check('amount mismatch named explicitly without changing verdict',$response->status===409 && webhook_trace('amount_mismatch')['order_id']===1 && $o->completions===0);
$o=webhook_setup();foreach(['currency'=>'USD','code'=>'other'] as $key=>$value){
 $response=$webhook->receive(webhook_request(signed(event($o,[$key=>$value]))));
 check('consistency rejection reason '.$key,$response->status===409 && webhook_trace('webhook_rejected')['reason']===['currency'=>'currency_mismatch','code'=>'remote_order_mismatch'][$key] && $o->completions===0);
}
$o=webhook_setup();$raw=signed(event($o));$response=$webhook->receive(webhook_request($raw));
check('synthetic confirmation trace emitted only after completion',$response->status===200 && webhook_trace('payment_confirmed')['signature_verified']===true && $o->completions===1);
$n=count($o->notes);$response=$webhook->receive(webhook_request($raw));
check('duplicate webhook traced with 200 and no repeated effect',$response->status===200 && webhook_trace('duplicate_webhook')['reason']==='duplicate' && $o->completions===1 && count($o->notes)===$n);
$o=webhook_setup();Support::lock(1);$response=$webhook->receive(webhook_request(signed(event($o))));Support::unlock(1);
check('lock failure traced without relaxing idempotency',$response->status===500 && webhook_trace('webhook_rejected')['reason']==='lock_unavailable' && $o->completions===0);
$o=webhook_setup();$o->throw_payment=true;$response=$webhook->receive(webhook_request(signed(event($o))));
check('completion exception safely traced retains one-attempt guard',$response->status===500 && webhook_trace('webhook_exception')['stage']==='webhook_confirmation' && $o->completions===1);
$o=webhook_setup();$request=new class { function get_body(){throw new RuntimeException('SENSITIVE-DO-NOT-LOG');} };
$response=$webhook->receive($request);
check('body exception still has receipt and safe 500',$response->status===500 && webhook_trace('webhook_received') && webhook_trace('webhook_exception')['stage']==='webhook_body' && !str_contains(json_encode($GLOBALS['logger']->events),'SENSITIVE-DO-NOT-LOG'));
$o=webhook_setup();$GLOBALS['options'][Support::OPTION]['logging']='no';$webhook->receive(webhook_request('{'));
check('logging setting still controls all webhook telemetry',!$GLOBALS['logger']->events);
// Names suggested by user: presence alone proves neither the contract nor a valid payment.
$o=webhook_setup();$data=event($o,['reference_num'=>'12345','clabe'=>'012345678901234567']);
$data['payments'][0]['Product']['id']='22';$response=$webhook->receive(webhook_request(signed($data)));
check('potential string Product id incompatibility detected not coerced',$response->status===400 && webhook_trace('webhook_rejected')['reason']==='payment_product_invalid' && $o->completions===0);
$o=webhook_setup();$data=event($o,['payments'=>[]]);$response=$webhook->receive(webhook_request(signed($data)));
check('empty payments remains rejected with exact diagnostic',$response->status===400 && webhook_trace('webhook_rejected')['reason']==='payments_shape_invalid');
$o=webhook_setup();$data=json_decode(signed(event($o)),true);$data['security']['version']='1';$response=$webhook->receive(webhook_request(json_encode($data)));
check('string security version remains rejected and visible',$response->status===401 && webhook_trace('invalid_signature')['reason']==='unsupported_security_version');
$o=webhook_setup();$data=event($o,['reference_num'=>'12345','clabe'=>'012345678901234567']);unset($data['date']);
$response=$webhook->receive(webhook_request(signed($data)));
check('candidate shape missing date remains rejected with shape only',$response->status===400 && webhook_trace('webhook_rejected')['reason']==='missing_signed_fields' && webhook_trace('webhook_rejected')['field_types']['date']==='missing');
$o=webhook_setup();$secret='SYNTHETIC-SECRET-NEVER-LOG-742';$data=event($o,['reference'=>$secret,'clabe'=>$secret,'reference_num'=>$secret,$secret=>$secret]);
$raw=signed($data);$decoded=json_decode($raw,true);$signature=$decoded['security']['signature'];$decoded['security']['signature']=$secret;
$webhook->receive(webhook_request(json_encode($decoded)));$logs=json_encode($GLOBALS['logger']->events);
check('unverified keys signatures references clabes and secrets omitted',!str_contains($logs,$secret) && !str_contains($logs,$signature) && !str_contains($logs,'012345678901234567'));
check('candidate root shape logs only allowlisted key names and types',webhook_trace('invalid_signature')['field_types']['amount']==='string' && webhook_trace('invalid_signature')['field_types']['payments']==='array' && webhook_trace('invalid_signature')['field_types']['security']==='object' && in_array('[other_keys_omitted]',webhook_trace('invalid_signature')['response_keys'],true));
check('all diagnostic changes avoid provider HTTP calls',count($GLOBALS['requests'])===2);

// Passive observation of a core REST rejection, before a callback can be invoked.
$o=webhook_setup();
$restRequest=new class {function get_route(){return '/cannaland-payments/v1/payment-notification';}function get_method(){return 'POST';}};
$restResponse=new class {function get_status(){return 400;}function get_data(){return ['code'=>'rest_invalid_json','message'=>'SENSITIVE-DO-NOT-LOG'];}};
check('REST pre-handler invalid JSON traced without changing response',$webhook->dispatch_diagnostic($restResponse,null,$restRequest)===$restResponse && webhook_trace('invalid_json')['stage']==='webhook_dispatch' && !webhook_trace('webhook_received') && !str_contains(json_encode($GLOBALS['logger']->events),'SENSITIVE-DO-NOT-LOG'));
$denied=new class {function get_status(){return 403;}function get_data(){return ['code'=>'unknown','message'=>'SENSITIVE-DO-NOT-LOG'];}};
check('authentication or plugin rejection observed without overriding it',$webhook->dispatch_diagnostic($denied,null,$restRequest)===$denied && webhook_trace('webhook_dispatch_rejected')['http_status']===403);
$otherRoute=new class {function get_route(){return '/other/v1/test';}function get_method(){return 'POST';}};
$before=count($GLOBALS['logger']->events);$webhook->dispatch_diagnostic($denied,null,$otherRoute);
check('REST diagnostics cannot affect or log unrelated routes',count($GLOBALS['logger']->events)===$before);
$getRequest=new class {function get_route(){return '/cannaland-payments/v1/payment-notification';}function get_method(){return 'GET';}};
$webhook->dispatch_diagnostic($denied,null,$getRequest);
check('REST diagnostics ignores other methods',count($GLOBALS['logger']->events)===$before);

// Run the WP-CLI helper through WordPress REST doubles, proving it cannot name an order.
class WP_REST_Request {
 private $method,$route,$body='';public $headers=[];
 function __construct($method,$route){$this->method=$method;$this->route=$route;}
 function set_header($key,$value){$this->headers[$key]=$value;}
 function set_body($body){$this->body=$body;}
 function get_body(){return $this->body;}
 function get_route(){return $this->route;}
 function get_method(){return $this->method;}
}
function rest_do_request($request){global $webhook;$GLOBALS['probe_request']=$request;return $webhook->receive($request);}
$o=webhook_setup();define('WP_CLI',true);ob_start();require __DIR__.'/../scripts/probe-webhook.php';$probeOutput=ob_get_clean();
$probe=json_decode($GLOBALS['probe_request']->get_body(),true);
check('WP-CLI helper dispatches unsigned probe to exact POST route',str_contains($probeOutput,'HTTP 401') && str_contains($probeOutput,'Response data: null') && $GLOBALS['probe_request']->get_route()==='/cannaland-payments/v1/payment-notification' && $GLOBALS['probe_request']->get_method()==='POST');
check('WP-CLI helper never names a real order or bypasses signature',array_keys($probe)===['probe'] && $GLOBALS['lookups']===0 && $o->completions===0 && webhook_trace('invalid_signature')['reason']==='security_missing');

// Hypotheses only: no actual payments item for #31087 has been supplied.
$cases = [
 ['string Product id', ['id'=>'synthetic-payment','Product'=>['id'=>'22']], 'Product_id_not_number','object','string','missing','missing'],
 ['missing Product id', ['id'=>'synthetic-payment','Product'=>[]], 'Product_not_object','array','missing','missing','missing'],
 ['null Product', ['id'=>'synthetic-payment','Product'=>null], 'Product_not_object','null','missing','missing','missing'],
 ['wrong numeric token', ['id'=>'synthetic-payment','Product'=>['id'=>99]], 'Product_id_not_expected_token','object','json_number','missing','missing'],
 ['numeric payment id', ['id'=>123,'Product'=>['id'=>22]], 'payment_id_invalid','object','json_number','missing','missing'],
 ['payment id missing', ['Product'=>['id'=>22]], 'payment_id_invalid','object','json_number','missing','missing'],
 ['payment scalar', 'synthetic-secret-value', 'payment_not_object','missing','missing','missing','missing'],
];
foreach ($cases as [$label,$item,$failed,$upperType,$upperIdType,$lowerType,$lowerIdType]) {
 $o=webhook_setup();$body=signed(event($o,['payments'=>[$item]]));$response=$webhook->receive(webhook_request($body));
 $context=webhook_trace('webhook_rejected');$shape=$context['payment_product_shape'];
 check('product diagnostic preserves rejection '.$label,$response->status===400 && $context['reason']==='payment_product_invalid' && $context['signature_verified']===true && $GLOBALS['lookups']===0 && $o->completions===0);
 check('product diagnostic pinpoints shape '.$label,$shape['index']===0 && $shape['failed_check']===$failed && $shape['Product_type']===$upperType && $shape['Product_id_type']===$upperIdType && $shape['product_type']===$lowerType && $shape['product_id_type']===$lowerIdType);
 check('product values never logged '.$label,!str_contains(json_encode($GLOBALS['logger']->events),'synthetic-payment') && !str_contains(json_encode($GLOBALS['logger']->events),'synthetic-secret-value'));
}
$o=webhook_setup();$data=event($o);$data['payments'][]=['id'=>'sensitive-id','product'=>['id'=>'sensitive-product-id','secret'=>'sensitive-secret-key']];
$webhook->receive(webhook_request(signed($data)));$shape=webhook_trace('webhook_rejected')['payment_product_shape'];
check('only rejected item index described',$shape['index']===1 && $shape['product_id_type']==='string');
$log=json_encode($GLOBALS['logger']->events);
check('no nested values unknown keys references signatures or CLABE in shape log',!str_contains($log,'sensitive-') && !str_contains($log,Support::meta($o,'reference')) && !str_contains($log,Support::meta($o,'clabe')));
// Empty object differs from array and must be distinguishable.
$o=webhook_setup();$webhook->receive(webhook_request(signed(event($o,['payments'=>[['id'=>'synthetic','Product'=>(object)[]]]]))));
check('missing id in Product object identified',webhook_trace('webhook_rejected')['payment_product_shape']['failed_check']==='Product_id_not_number' && webhook_trace('webhook_rejected')['payment_product_shape']['Product_id_type']==='missing');
$o=webhook_setup();$body=signed(event($o));$invalid=json_decode($body,true);$invalid['security']['signature']='invalid';
$webhook->receive(webhook_request(json_encode($invalid)));
check('nested product diagnostics not emitted before valid signature',!str_contains(json_encode($GLOBALS['logger']->events),'payment_product_shape'));
$o=webhook_setup();$GLOBALS['options'][Support::OPTION]['logging']='no';$webhook->receive(webhook_request(signed(event($o,['payments'=>[['id'=>'synthetic','product'=>['id'=>22]]]]))));
check('nested product diagnostics obey logging switch',!$GLOBALS['logger']->events);

$o=webhook_setup();$raw=signed(event($o));$raw=str_replace('"Product":{"id":22,','"Product":{"id":22.0,',$raw);
$response=$webhook->receive(webhook_request($raw));
check('numeric lexical mismatch diagnosed without recording value',$response->status===400 && webhook_trace('webhook_rejected')['payment_product_shape']['Product_id_type']==='json_number' && webhook_trace('webhook_rejected')['payment_product_shape']['failed_check']==='Product_id_not_expected_token' && $o->completions===0);
