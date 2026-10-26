<?php
// Runs from tests/run.php with its offline HTTP, logger and WooCommerce doubles.
use Cannaland\Payments\Support;
use Cannaland\Payments\Gateway;
use Cannaland\Payments\Preparation;
use Cannaland\Payments\Diagnostics;

function diagnostic_context( $event ) {
    $entries=array_values(array_filter($GLOBALS['logger']->events,fn($e)=>$e[0]===$event));
    return $entries ? end($entries)[1] : [];
}
function diagnostic_failure() { return diagnostic_context('preparation_failed'); }
function diagnostic_setup() {
    reset_state(); $GLOBALS['options'][Support::OPTION]['logging']='yes'; return order();
}
$o=diagnostic_setup(); queue_success(); $g=new Gateway; $result=$g->process_payment(1);
check('diagnostic checkout preserves success',$result['result']==='success' && $o->completions===0);
$names=array_column($GLOBALS['logger']->events,0);
check('all ten requested milestones in order',$names===['process_payment_started','reference_prepared','create_order_request','create_order_response','remote_order_saved','create_clabe_request','create_clabe_response','clabe_saved','order_on_hold','process_payment_success']);
check('every diagnostic has stable source and order ID',array_reduce($GLOBALS['logger']->events,fn($ok,$e)=>$ok && $e[1]['source']==='cannaland-payments' && $e[1]['order_id']===1 && isset($e[1]['stage'],$e[1]['endpoint'],$e[1]['certainty']),true));
$c=diagnostic_context('create_order_response');
check('order HTTP rc and safe shape recorded',$c['http_status']===201 && $c['rc']===0 && $c['response_type']==='json_object' && $c['response_keys']===['rc','msg','data'] && $c['field_types']['amount']==='string');
check('CLABE endpoint logical template recorded',diagnostic_context('create_clabe_response')['endpoint']==='/orders/{order_id}/clabe');
check('success progress logged at info',count(array_unique($GLOBALS['logger']->levels))===1 && $GLOBALS['logger']->levels[0]==='info');
ob_start(); $g->instructions(1); ob_end_clean();
check('thank-you rendering has trace',isset(diagnostic_context('instructions_displayed')['stage']));
$before=count($GLOBALS['requests']); $GLOBALS['logger']->events=[]; $GLOBALS['logger']->levels=[]; $g->process_payment(1);
check('reuse is explicit without another POST',in_array('preparation_reused',array_column($GLOBALS['logger']->events,0),true) && count($GLOBALS['requests'])===$before);
reset_state(); order(); queue_success(); (new Gateway)->process_payment(1);
check('diagnostic logging disabled means no events',count($GLOBALS['logger']->events)===0);

$o=diagnostic_setup(); $GLOBALS['options'][Support::OPTION]['api_key']=''; (new Gateway)->process_payment(1);
$c=diagnostic_failure();
check('pre-HTTP missing key logs exact stage',$c['stage']==='configuration_validation' && $c['reason']==='missing_api_key' && $c['certainty']==='definitive_local' && count($GLOBALS['requests'])===0);
check('early checkout failure uses error severity',in_array('error',$GLOBALS['logger']->levels,true) && diagnostic_context('process_payment_failed')['wp_error_code']==='cannaland_payments_configuration');
$o=diagnostic_setup(); (new Preparation)->prepare(999);
check('missing order diagnosed',diagnostic_failure()['reason']==='order_not_found' && diagnostic_failure()['order_id']===999);
$o=diagnostic_setup(); Support::lock(1); (new Gateway)->process_payment(1);
check('lock contention diagnosed without HTTP',diagnostic_failure()['stage']==='order_lock' && diagnostic_failure()['reason']==='lock_unavailable' && count($GLOBALS['requests'])===0); Support::unlock(1);

foreach ([['status'=>401,'body'=>'{"rc":77,"msg":"Rejected"}'],['status'=>503,'body'=>'{"rc":77,"msg":"Error"}'],['status'=>200,'body'=>'{']] as $response) {
    $o=diagnostic_setup(); $GLOBALS['responses'][]=$response; (new Gateway)->process_payment(1); $c=diagnostic_context('create_order_response');
    $kind=$response['status']===401?'rejected':'ambiguous';
    check('API failure classified and visible '.$response['status'],$c['http_status']===$response['status'] && $c['result']===$kind && diagnostic_failure()['stage']==='create_order');
    $before=count($GLOBALS['requests']); (new Gateway)->process_payment(1);
    check('logged failure does not permit POST retry',count($GLOBALS['requests'])===$before && diagnostic_failure()['result']==='blocked');
}
$o=diagnostic_setup(); $GLOBALS['responses'][]=new WP_Error('http_request_failed','cURL error 28: timed out against sensitive URL'); (new Gateway)->process_payment(1);
$c=diagnostic_context('create_order_response');
check('WP_Error code and timeout number retained safely',$c['wp_error_code']==='http_request_failed' && $c['curl_code']===28 && $c['response_type']==='wp_error' && $c['certainty']==='ambiguous');

// Synthetic reproduction of Horsements' observed response types, not a real provider fixture.
$o=diagnostic_setup(); queue_success();
$GLOBALS['responses'][0]=['status'=>200,'body'=>'{"rc":0,"msg":"Ok","data":{"id":"remote-1","amount":10.0,"currency":"MXN","expiration_date":"2030-01-01T12:00:00-06:00","description":"Cannaland order #1"}}'];
$decoded=json_decode($GLOBALS['responses'][0]['body'],true);
check('Horsements regression actually decodes amount as double',is_float($decoded['data']['amount']) && !array_key_exists('clabe',$decoded['data']));
$result=(new Gateway)->process_payment(1); $c=diagnostic_context('create_order_response');
check('HTTP 200 rc zero Ok double amount reaches CLABE request',$c['http_status']===200 && $c['rc']===0 && $c['provider_message']==='Ok' && $c['field_types']['amount']==='double' && count($GLOBALS['requests'])===2 && $GLOBALS['requests'][1][0]==='https://sandbox.sinergypay.mx/v2/orders/remote-1/clabe');
check('double amount checkout completes preparation without confirming payment',$result['result']==='success' && $o->status==='on-hold' && $o->completions===0 && Support::meta($o,'remote_order_id')==='remote-1' && Support::meta($o,'preparation')==='ready' && !diagnostic_failure());
check('observed response follows all ten expected milestones',array_column($GLOBALS['logger']->events,0)===['process_payment_started','reference_prepared','create_order_request','create_order_response','remote_order_saved','create_clabe_request','create_clabe_response','clabe_saved','order_on_hold','process_payment_success']);
$before=count($GLOBALS['requests']); (new Gateway)->process_payment(1);
check('numeric response preparation remains reusable without duplicate POSTs',count($GLOBALS['requests'])===$before);
foreach ([10,10.25,0.01,10.1] as $value) {
    $o=diagnostic_setup(); $o->total=number_format($value,2,'.',''); queue_success();
    $data=json_decode($GLOBALS['responses'][0]['body'],true); $data['data']['amount']=$value;
    $GLOBALS['responses'][0]=['status'=>200,'body'=>json_encode($data,JSON_PRESERVE_ZERO_FRACTION)];
    check('JSON numeric creation amount accepted '.gettype($value).' '.$value,(new Gateway)->process_payment(1)['result']==='success' && count($GLOBALS['requests'])===2 && !diagnostic_failure());
}
foreach ([true,false,null,[],new stdClass(),'not-numeric','10xyz','1e1',' 10.00',10.001,-10.0] as $value) {
    $o=diagnostic_setup(); queue_success(); $data=json_decode($GLOBALS['responses'][0]['body'],true); $data['data']['amount']=$value;
    $GLOBALS['responses'][0]['body']=json_encode($data,JSON_PRESERVE_ZERO_FRACTION); (new Gateway)->process_payment(1);
    check('invalid response amount blocks CLABE and retains remote ID '.gettype($value),diagnostic_failure()['reason']==='amount_type_invalid' && count($GLOBALS['requests'])===1 && Support::meta($o,'remote_order_id')==='remote-1' && Support::meta($o,'order_state')==='ambiguous');
    (new Gateway)->process_payment(1);
    check('invalid response amount never recreates remote order',count($GLOBALS['requests'])===1);
}
foreach ([INF,-INF,NAN,10000000000.0,0.001] as $value) {
    check('non-finite out-of-range and sub-cent amounts rejected',Support::response_money($value)===null);
}
check('API numeric normalization leaves signed webhook amount rules unchanged',Support::money(10.0)===null && Support::money('10.00')==='10.00' && Support::response_money('10.00')==='10.00');
$o=diagnostic_setup(); queue_success(); $data=json_decode($GLOBALS['responses'][0]['body'],true); $data['data']['amount']=11.0;
$GLOBALS['responses'][0]['body']=json_encode($data,JSON_PRESERVE_ZERO_FRACTION); (new Gateway)->process_payment(1);
check('valid numeric type with different value remains rejected',diagnostic_failure()['reason']==='amount_mismatch' && count($GLOBALS['requests'])===1);
// Existing ambiguous orders are never automatically retried or reset by this fix.
$o=diagnostic_setup(); Support::put($o,'remote_order_id','already-created'); Support::put($o,'order_state','ambiguous'); $o->save();
(new Gateway)->process_payment(1);
check('historical ambiguous remote order remains blocked',count($GLOBALS['requests'])===0 && Support::meta($o,'remote_order_id')==='already-created');
foreach (['id'=>null,'amount'=>'11.00','currency'=>'USD','description'=>'different','expiration_date'=>'not-a-date'] as $field=>$value) {
    $o=diagnostic_setup(); queue_success(); $data=json_decode($GLOBALS['responses'][0]['body'],true); $data['data'][$field]=$value;
    $GLOBALS['responses'][0]['body']=json_encode($data); (new Gateway)->process_payment(1); $c=diagnostic_failure();
    $expected=['id'=>'invalid_remote_id','amount'=>'amount_mismatch','currency'=>'currency_mismatch','description'=>'description_mismatch','expiration_date'=>'expiration_invalid'][$field];
    check('response validation reason '.$field,$c['reason']===$expected && $c['stage']==='order_response_validation' && $c['result']==='ambiguous');
}
foreach (['12345678901234567','12345678901234567x',123456789012345678] as $clabe) {
    $o=diagnostic_setup(); queue_success(); $GLOBALS['responses'][1]=envelope(['clabe'=>$clabe]); (new Gateway)->process_payment(1); $c=diagnostic_failure();
    $reason=is_string($clabe)?(strlen($clabe)===18?'invalid_clabe_characters':'invalid_clabe_length'):'invalid_clabe_type';
    check('CLABE validation exact reason '.$reason,$c['stage']==='clabe_response_validation' && $c['reason']===$reason && $c['endpoint']==='/orders/{order_id}/clabe' && Support::meta($o,'clabe')==='');
}
$o=diagnostic_setup(); queue_success(); $GLOBALS['responses'][1]=new WP_Error('timeout'); (new Gateway)->process_payment(1);
check('CLABE network failure distinct from order creation',diagnostic_failure()['stage']==='create_clabe' && diagnostic_failure()['wp_error_code']==='timeout');

foreach ([1=>'reference_persistence',2=>'order_state_persistence',3=>'remote_order_persistence',4=>'order_state_persistence',5=>'clabe_state_persistence',6=>'clabe_persistence'] as $number=>$stage) {
    $o=diagnostic_setup(); queue_success(); $o->fail_save_number=$number; (new Gateway)->process_payment(1); $c=diagnostic_context('preparation_exception');
    check('CRUD exception identifies stage '.$number,$c['stage']===$stage && $c['exception_class']==='RuntimeException' && $c['order_id']===1);
    $o->fail_save_number=0; $before=count($GLOBALS['requests']);
    if(in_array($number,[3,4,6],true)) { (new Gateway)->process_payment(1); check('CRUD exception preserves duplicate protection '.$number,count($GLOBALS['requests'])===$before); }
    if($number===2) { check('failed sending persistence occurs before network',$before===0); }
    if($number===5) { check('failed CLABE sending persistence occurs before CLABE POST',$before===1 && !diagnostic_context('create_clabe_request')); }
}
$o=diagnostic_setup(); queue_success(); $o->throw_status=true; (new Gateway)->process_payment(1);
check('on-hold exception exact stage',diagnostic_context('preparation_exception')['stage']==='on_hold_transition' && !diagnostic_context('process_payment_success'));
$o=diagnostic_setup(); queue_success(); $GLOBALS['throw_redirect']=true; (new Gateway)->process_payment(1);
check('redirect exception exact stage',diagnostic_context('process_payment_exception')['stage']==='redirect' && count($GLOBALS['requests'])===2);

$secret='SYNTHETIC-SECRET-never-log-741'; $signature='SYNTHETIC-SIGNATURE-never-log-852';
$o=diagnostic_setup(); $GLOBALS['options'][Support::OPTION]['api_key']=$secret;
$GLOBALS['responses'][]=['status'=>401,'headers'=>['Authorization'=>'Basic '.base64_encode($secret.':')],
 'body'=>json_encode(['rc'=>12,'msg'=>'Authorization '.$secret.' signature '.$signature,'data'=>['api_key'=>$secret,'signature'=>$signature,$secret=>'private'],'Authorization'=>$secret])];
(new Gateway)->process_payment(1);
$serialized=json_encode($GLOBALS['logger']->events);
check('API messages headers signatures values and unknown keys never leak',!str_contains($serialized,$secret) && !str_contains($serialized,$signature) && !str_contains($serialized,base64_encode($secret.':')));
check('untrusted provider message explicitly omitted',diagnostic_context('create_order_response')['provider_message']==='[omitted_untrusted_message]');
$o=diagnostic_setup(); $GLOBALS['responses'][]=new RuntimeException('Authorization '.$secret.' '.$signature); (new Gateway)->process_payment(1);
$c=diagnostic_context('preparation_exception');
check('throwable class and exact request stage without secret message',$c['exception_class']==='RuntimeException' && $c['stage']==='create_order' && $c['exception_message']==='[omitted_untrusted_message]' && !str_contains(json_encode($GLOBALS['logger']->events),$secret));
$o=diagnostic_setup(); $GLOBALS['responses'][]=new WP_Error($secret,'signature '.$signature); (new Gateway)->process_payment(1);
check('arbitrary WP error code cannot carry secrets',diagnostic_context('create_order_response')['wp_error_code']==='[omitted_unrecognized_code]' && !str_contains(json_encode($GLOBALS['logger']->events),$secret));
$o=diagnostic_setup(); $GLOBALS['logger']=new class extends TestLogger { function info(...$args){throw new RuntimeException('logger unavailable');} function error(...$args){throw new RuntimeException('logger unavailable');} };
queue_success(); $result=(new Gateway)->process_payment(1);
check('logger failure does not alter preparation or retry financial requests',$result['result']==='success' && count($GLOBALS['requests'])===2 && $o->completions===0);

$o=diagnostic_setup(); $GLOBALS['options'][Support::OPTION]['api_key']='not:a-valid-local-format'; (new Gateway)->process_payment(1);
check('locally invalid key format diagnosed without send',diagnostic_context('create_order_response')['reason']==='invalid_api_key_format' && diagnostic_context('create_order_response')['certainty']==='definitive_local' && count($GLOBALS['requests'])===0);
$o=diagnostic_setup(); $GLOBALS['responses'][]=['status'=>200,'body'=>'{"rc":"0","msg":"Ok","data":{}}']; (new Gateway)->process_payment(1);
check('invalid rc type visible without coercing contract',diagnostic_context('create_order_response')['field_types']['rc']==='string' && diagnostic_context('create_order_response')['reason']==='invalid_envelope');
$o=diagnostic_setup(); $GLOBALS['responses'][]=['status'=>200,'body'=>['not-a-string']]; (new Gateway)->process_payment(1);
$c=diagnostic_context('create_order_response');
check('response parse throwable keeps HTTP status and request stage',$c['http_status']===200 && $c['stage']==='create_order' && $c['exception_class']==='TypeError' && $c['exception_message']==='Type mismatch.');
