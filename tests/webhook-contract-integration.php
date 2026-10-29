<?php
/** Offline contract integration: real route callback/parser/RSA/processor; WooCommerce is a test double. */
use Cannaland\Payments\Support;
use Cannaland\Payments\Notification;
use Cannaland\Payments\Webhook;

$contractFixture = json_decode(file_get_contents(__DIR__.'/fixtures/sinergypay-spei-contract.json'),true,32,JSON_THROW_ON_ERROR);
// Only observe entry to the existing Woo double; do not implement confirmation logic here.
class ContractOrder extends Order {
    public array $completion_entry=[];
    public function payment_complete($ticket) {
        $this->completion_entry=[
            'status'=>$this->get_status(), 'transaction_meta'=>Support::meta($this,'transaction_id'),
            'confirmation_meta'=>Support::meta($this,'confirmation_state'),
            'saved_meta'=>$this->last_saved_meta,
            'locked'=>array_key_exists('cannaland_payments_order_lock_'.$this->id,$GLOBALS['options']),
        ];
        parent::payment_complete($ticket);
    }
}
function contract_setup($fixture) {
    reset_state();$GLOBALS['options'][Support::OPTION]['logging']='yes';
    $order=new ContractOrder(880001);$order->status='on-hold';$order->total='5.00';
    // Existing prepared order snapshot: no call to Preparation, client, /orders/ or /clabe.
    foreach(['reference'=>$fixture['reference'],'environment'=>'sandbox','amount'=>'5.00','currency'=>'MXN',
        'description'=>$fixture['description'],'remote_order_id'=>$fixture['code'],'reference_num'=>$fixture['reference_num'],
        'clabe'=>'012345678901234567','expiration_date'=>'2030-01-01T00:00:00+00:00',
        'order_state'=>'succeeded','clabe_state'=>'succeeded','preparation'=>'ready'] as $key=>$value){Support::put($order,$key,$value);}
    $order->save();$GLOBALS['orders'][$order->id]=$order;
    $other=new ContractOrder(880002);$other->status='on-hold';$other->total='5.00';$other->meta=$order->meta;
    Support::put($other,'reference','csp-880002-12345678-1234-4234-8234-123456789abd');
    Support::put($other,'remote_order_id','OTHER-CODE');$other->save();$GLOBALS['orders'][$other->id]=$other;
    return [$order,$other];
}
function contract_dispatch($handler,$raw) {
    $handler->register();$route=$GLOBALS['route'];
    if($route[0]!=='cannaland-payments/v1' || $route[1]!=='/payment-notification' || $route[2]['methods']!=='POST' || $route[2]['permission_callback']!=='__return_true'){
        throw new RuntimeException('Contract route registration changed');
    }
    $request=new WP_REST_Request('POST','/cannaland-payments/v1/payment-notification');
    $request->set_header('Content-Type','application/json');$request->set_body($raw);
    return call_user_func($route[2]['callback'],$request);
}
check('official fixture includes numeric Product 22 with SPEI and security v1',$contractFixture['payments'][0]['Product']===['id'=>22,'name'=>'SPEI'] && $contractFixture['security']['version']===1);
foreach([false,true] as $hpos) {
    Automattic\WooCommerce\Utilities\OrderUtil::$enabled=$hpos;
    [$order,$other]=contract_setup($contractFixture);
    $handler=new Webhook(new Notification($keydir));$raw=signed($contractFixture);
    $decoded=json_decode($raw,true);
    check('contract fixture security uses generated Base64 RSA signature '.(int)$hpos,$decoded['security']['version']===1 && base64_decode($decoded['security']['signature'],true)!==false && $decoded['security']['signature']!=='');
    $response=contract_dispatch($handler,$raw);
    check('contract callback reaches payment_confirmed 200 '.(int)$hpos,$response->get_status()===200 && $response->get_data()===null && webhook_trace('payment_confirmed')['signature_verified']===true && webhook_trace('payment_confirmed')['order_id']===880001);
    check('contract exact reference chooses only matching order '.(int)$hpos,$order->completions===1 && $other->completions===0 && $other->status==='on-hold');
    check('contract reference lookup uses correct Woo data store '.(int)$hpos,$hpos ? isset($GLOBALS['last_query']['meta_query']) : isset($GLOBALS['legacy_query']['meta_query']));
    check('contract completion entered with lock and persisted claim '.(int)$hpos,$order->completion_entry['locked'] && $order->completion_entry['status']==='on-hold' && $order->completion_entry['confirmation_meta']==='applying' && $order->completion_entry['transaction_meta']===$contractFixture['id'] && $order->completion_entry['saved_meta'][Support::PREFIX.'transaction_id']===$contractFixture['id']);
    check('contract leaves on-hold via payment_complete double '.(int)$hpos,$order->status==='processing' && $order->is_paid() && $order->get_transaction_id()===$contractFixture['id']);
    check('contract confirmation metadata persisted paid '.(int)$hpos,Support::meta($order,'confirmation_state')==='paid' && $order->last_saved_meta[Support::PREFIX.'confirmation_state']==='paid');
    check('contract safe administrative confirmation note '.(int)$hpos,count($order->notes)===1 && str_contains($order->notes[0],'firma válida') && !str_contains($order->notes[0],$decoded['security']['signature']));
    $claim='cannaland_payments_ticket_'.hash('sha256','sandbox|'.$contractFixture['id']);
    check('contract ticket uniquely associated and lock released '.(int)$hpos,($GLOBALS['options'][$claim]??null)===$contractFixture['reference'] && !isset($GLOBALS['options']['cannaland_payments_order_lock_880001']));
    $snapshot=[$order->meta,$order->status,$order->notes,$order->save_count,$order->completions,$order->transaction];
    $response=contract_dispatch($handler,$raw);
    check('contract repeat ACK 200 with duplicate trace '.(int)$hpos,$response->get_status()===200 && webhook_trace('duplicate_webhook')['order_id']===880001);
    check('contract repeat no writes notes or second financial effect '.(int)$hpos,$snapshot===[$order->meta,$order->status,$order->notes,$order->save_count,$order->completions,$order->transaction]);
    check('contract confirmation event emitted once '.(int)$hpos,count(array_filter($GLOBALS['logger']->events,fn($e)=>$e[0]==='payment_confirmed'))===1);
    $differentTicket=array_replace($contractFixture,['id'=>'28e62e93-c26b-4c26-a25b-7aea2bbbfbae']);
    $response=contract_dispatch($handler,signed($differentTicket));
    check('contract already-paid order rejects another ticket '.(int)$hpos,$response->get_status()===409 && webhook_trace('webhook_rejected')['reason']==='transaction_conflict' && $snapshot===[$order->meta,$order->status,$order->notes,$order->save_count,$order->completions,$order->transaction]);
    $sameTicketOtherOrder=array_replace($contractFixture,['reference'=>Support::meta($other,'reference'),'code'=>Support::meta($other,'remote_order_id')]);
    $response=contract_dispatch($handler,signed($sameTicketOtherOrder));
    check('contract ticket cannot pay a second order '.(int)$hpos,$response->get_status()===409 && webhook_trace('webhook_rejected')['reason']==='claim_conflict' && $other->completions===0 && $other->status==='on-hold' && $order->completions===1);
    check('contract integration has zero outgoing API calls '.(int)$hpos,count($GLOBALS['requests'])===0);
}
// Signed inconsistency cases verify downstream checks rather than failing at RSA/Product shape.
foreach([
    ['unknown reference',['reference'=>'csp-880099-12345678-1234-4234-8234-123456789abc'],409,'order_not_found'],
    ['different amount',['amount'=>'5.01'],409,'amount_mismatch'],
    ['different currency',['currency'=>'USD'],409,'currency_mismatch'],
    ['different remote order',['code'=>'OTHER-CODE'],409,'remote_order_mismatch'],
    ['different description',['description'=>'otra descripción'],409,'description_mismatch'],
] as [$label,$change,$status,$reason]) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    $response=contract_dispatch($handler,signed(array_replace($contractFixture,$change)));
    $context=webhook_trace($reason==='order_not_found'?'order_not_found':($reason==='amount_mismatch'?'amount_mismatch':'webhook_rejected'));
    check('contract signed mismatch rejected '.$label,$response->get_status()===$status && $context['reason']===$reason && $context['signature_verified']===true && $order->status==='on-hold' && $order->completions===0 && $other->completions===0 && !webhook_trace('payment_confirmed'));
}
foreach(['environment snapshot','edited Woo total'] as $case) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    if($case==='environment snapshot'){Support::put($order,'environment','production');}else{$order->total='5.01';}
    $response=contract_dispatch($handler,signed($contractFixture));
    $reason=$case==='environment snapshot'?'environment_mismatch':'amount_mismatch';
    $context=webhook_trace($reason==='amount_mismatch'?'amount_mismatch':'webhook_rejected');
    check('contract stored order consistency rejected '.$case,$response->get_status()===409 && $context['reason']===$reason && $context['signature_verified']===true && $order->completions===0 && $order->status==='on-hold');
}
[$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
$raw=str_replace('"amount":"5.00"','"amount":5.00',signed($contractFixture));
$response=contract_dispatch($handler,$raw);
check('contract numeric amount lexeme also confirms without float reconstruction',$response->get_status()===200 && $order->completions===1 && webhook_trace('payment_confirmed'));
foreach(['modified signed amount','missing signature','wrong key','unsupported security version'] as $case) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    $raw=signed($contractFixture);
    if($case==='modified signed amount'){$raw=str_replace('"amount":"5.00"','"amount":"5.01"',$raw);}
    elseif($case==='wrong key'){$raw=signed($contractFixture,false,openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]));}
    else{$data=json_decode($raw,true);if($case==='missing signature'){unset($data['security']['signature']);}else{$data['security']['version']=2;}$raw=json_encode($data);}
    $response=contract_dispatch($handler,$raw);
    check('contract security rejected before association '.$case,$response->get_status()===401 && $GLOBALS['lookups']===0 && $order->completions===0 && $order->status==='on-hold' && !webhook_trace('payment_confirmed'));
}

// Real Lab evidence established lowercase casing/types, not a distributable real signed fixture.
// Use controlled RSA and the same production callback for both accepted representations.
foreach(['Product','product','both'] as $variant) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    $data=$contractFixture;
    if($variant!=='Product'){$data['payments'][0]['product']=$data['payments'][0]['Product'];if($variant==='product'){unset($data['payments'][0]['Product']);}}
    $raw=signed($data);$response=contract_dispatch($handler,$raw);
    check('SPEI product casing confirms via production callback '.$variant,$response->get_status()===200 && webhook_trace('payment_confirmed')['signature_verified']===true && $order->completions===1 && $order->status==='processing' && Support::meta($order,'confirmation_state')==='paid' && $other->completions===0);
    check('SPEI product casing preserves transaction association '.$variant,$order->get_transaction_id()===$data['id'] && Support::meta($order,'reference')===$data['reference']);
    $before=[$order->meta,$order->status,$order->notes,$order->completions,$order->save_count];
    $response=contract_dispatch($handler,$raw);
    check('SPEI product casing duplicate never confirms twice '.$variant,$response->get_status()===200 && webhook_trace('duplicate_webhook') && $before===[$order->meta,$order->status,$order->notes,$order->completions,$order->save_count]);
    check('SPEI product casing creates no external operations '.$variant,!$GLOBALS['requests']);
}
foreach(['Product','product'] as $variant) {
    foreach([null,[],22,(object)[],['id'=>null],['id'=>'22'],['id'=>true],['id'=>21],['id'=>23]] as $invalid) {
        [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
        $data=$contractFixture;unset($data['payments'][0]['Product']);$data['payments'][0][$variant]=$invalid;
        $response=contract_dispatch($handler,signed($data));
        check('SPEI product invalid container/type/id rejected '.$variant.' '.json_encode($invalid),$response->get_status()===400 && webhook_trace('webhook_rejected')['reason']==='payment_product_invalid' && webhook_trace('webhook_rejected')['signature_verified']===true && $GLOBALS['lookups']===0 && $order->status==='on-hold' && $order->completions===0);
    }
}
foreach([
    [],
    ['Product'=>null,'product'=>null],
    ['Product'=>['id'=>23],'product'=>['id'=>22]],
    ['Product'=>['id'=>22],'product'=>['id'=>23]],
    ['Product'=>null,'product'=>['id'=>22]],
    ['Product'=>['id'=>22],'product'=>null],
] as $containers) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    $data=$contractFixture;unset($data['payments'][0]['Product']);$data['payments'][0]=array_merge($data['payments'][0],$containers);
    $response=contract_dispatch($handler,signed($data));
    check('absent invalid or conflicting variants remain rejected '.json_encode($containers),$response->get_status()===400 && $order->completions===0 && $order->status==='on-hold' && !$GLOBALS['lookups']);
}
// Lowercase cannot bypass any downstream security or consistency check.
foreach(['signature','amount','currency','reference','code'] as $field) {
    [$order,$other]=contract_setup($contractFixture);$handler=new Webhook(new Notification($keydir));
    $data=$contractFixture;$data['payments'][0]['product']=$data['payments'][0]['Product'];unset($data['payments'][0]['Product']);
    if($field!=='signature'){$data[$field]=['amount'=>'5.01','currency'=>'USD','reference'=>'csp-880099-12345678-1234-4234-8234-123456789abc','code'=>'OTHER-CODE'][$field];}
    $raw=signed($data);if($field==='signature'){$raw=str_replace('"description":"chocolates"','"description":"changed"',$raw);}
    $response=contract_dispatch($handler,$raw);
    check('lowercase preserves security and association '.$field,$response->get_status()===($field==='signature'?401:409) && $order->completions===0 && $order->status==='on-hold' && !webhook_trace('payment_confirmed'));
}
